<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PropositionStock;
use App\Modules\Admin\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * « Proposition entrée/sortie » (23/11/2026) : les mouvements de stock
 * qu'appellent les factures reprises du portail FNE, à valider ou refuser
 * ligne par ligne. Rien ne bouge le stock sans un clic de l'utilisateur.
 */
class PropositionStockControleur extends Controller
{
    public function index(): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        // Un membre affecté ne voit que les propositions de son site (audit du 23/11/2026).
        $site = Auth::user()->estAffecteAUnSite() ? (int) Auth::user()->point_de_vente_id : null;

        $enAttente = PropositionStock::disponible()
            ? PropositionStock::with(['produit:id,nom,unite', 'vente:id,numero_facture,numero_fne,date_vente', 'pointDeVente:id,nom'])
                ->where('entreprise_id', $entrepriseId)
                ->where('statut', PropositionStock::PROPOSEE)
                ->when($site, fn ($q) => $q->where('point_de_vente_id', $site))
                ->orderBy('id')
                ->get()
            : collect();

        $traitees = PropositionStock::disponible()
            ? PropositionStock::with(['produit:id,nom', 'vente:id,numero_facture'])
                ->where('entreprise_id', $entrepriseId)
                ->where('statut', '!=', PropositionStock::PROPOSEE)
                ->when($site, fn ($q) => $q->where('point_de_vente_id', $site))
                ->latest('traite_le')
                ->limit(30)
                ->get()
            : collect();

        return view('admin::stock.propositions', compact('enAttente', 'traitees'));
    }

    public function valider(PropositionStock $proposition): RedirectResponse
    {
        $this->appartient($proposition);

        try {
            DB::transaction(function () use ($proposition) {
                $proposition = PropositionStock::whereKey($proposition->id)->lockForUpdate()->firstOrFail();
                if ($proposition->statut !== PropositionStock::PROPOSEE) {
                    throw new \DomainException('Cette proposition a déjà été traitée.');
                }

                $produit = $proposition->produit;
                $pdvId = (int) $proposition->point_de_vente_id;

                // La pièce d'origine : une vente (ou son avoir), ou un achat
                // repris du portail (ou son avoir), retrouvé par son numéro.
                $achat = $proposition->vente_id ? null : \App\Modules\Admin\Modeles\Achat::query()
                    ->where('numero_facture', $proposition->reference)
                    ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $proposition->entreprise_id))
                    ->first();
                $piece = $proposition->vente ?? $achat;
                $contexte = ['piece' => $piece, 'reference' => $proposition->reference];
                if ($proposition->vente) {
                    $contexte['client_id'] = $proposition->vente->client_id;
                } elseif ($achat) {
                    $contexte['fournisseur_id'] = $achat->fournisseur_id;
                }

                if ($proposition->sens === 'sortie') {
                    $disponible = StockService::disponible($produit, $pdvId);
                    if ($disponible < $proposition->quantite) {
                        throw new \DomainException("Stock insuffisant pour « {$produit->nom} » : {$disponible} disponible(s), {$proposition->quantite} à sortir. Faites d'abord l'entrée, ou refusez la proposition.");
                    }
                    StockService::sortie($produit, $pdvId, (float) $proposition->quantite,
                        $achat ? MouvementStock::RETOUR_FOURNISSEUR : MouvementStock::LIVRAISON, $contexte);
                } elseif ($achat) {
                    // Une réception, au prix d'achat de la ligne : c'est lui qui
                    // fait le CUMP (Coût Unitaire Moyen Pondéré).
                    $cout = (float) $achat->details()->where('produit_id', $produit->id)->value('prix_unitaire');
                    StockService::entree($produit, $pdvId, (float) $proposition->quantite, MouvementStock::RECEPTION, $contexte + ['cout_unitaire' => $cout]);
                } else {
                    StockService::entree($produit, $pdvId, (float) $proposition->quantite, MouvementStock::RETOUR_CLIENT, $contexte);
                }

                $proposition->update(['statut' => PropositionStock::VALIDEE, 'traite_par' => Auth::id(), 'traite_le' => now()]);
            });
        } catch (\DomainException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', 'Mouvement de stock validé.');
    }

    public function refuser(Request $request, PropositionStock $proposition): RedirectResponse
    {
        $this->appartient($proposition);

        if ($proposition->statut === PropositionStock::PROPOSEE) {
            $proposition->update([
                'statut'     => PropositionStock::REFUSEE,
                'motif'      => mb_substr((string) $request->input('motif', ''), 0, 250) ?: null,
                'traite_par' => Auth::id(),
                'traite_le'  => now(),
            ]);
        }

        return back()->with('succes', 'Proposition refusée : le stock ne bouge pas.');
    }

    private function appartient(PropositionStock $proposition): void
    {
        abort_unless((int) $proposition->entreprise_id === (int) Auth::user()->entreprise_id, 404);
        abort_unless($proposition->point_de_vente_id, 404);
        \App\Modules\Admin\Services\SiteAutorise::verifier((int) $proposition->point_de_vente_id);
    }
}
