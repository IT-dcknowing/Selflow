<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\TransfertStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\JournalAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;

class TransfertStockControleur
{
    /**
     * Liste des transferts internes et formulaire de demande.
     */
    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        // Liste des produits actifs
        // Un membre affecté ne transfère que depuis son site, et ne voit que
        // les articles et les transferts qui le concernent (audit du 23/11/2026).
        // La source est par ailleurs ramenée sur son site par le middleware.
        $site = \App\Modules\Admin\Services\SiteAutorise::siteImpose();

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->where('statut', 'actif')
            ->when($site, fn ($q) => $q->visiblesAuSite($site))
            ->orderBy('nom')
            ->get();

        // Liste des points de vente de l'entreprise
        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();
        $siteSource = $site;

        // Récupérer l'historique des transferts
        $transferts = TransfertStock::with(['produit', 'source', 'destination', 'demandeur', 'approbateur'])
            ->whereHas('produit', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->when($site, fn ($q) => $q->where(fn ($t) => $t->where('point_de_vente_source_id', $site)->orWhere('point_de_vente_destination_id', $site)))
            ->latest()
            ->paginate(20);

        return view('admin::stock.transfert', compact('produits', 'pointsDeVente', 'transferts', 'siteSource'));
    }

    /**
     * Créer une demande de transfert ou effectuer un transfert direct (si admin).
     */
    public function creer(Request $request): RedirectResponse
    {
        $request->validate([
            'produit_id'                   => ['required', 'integer', Appartenance::a('produits', 'id')],
            'point_de_vente_source_id'      => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'point_de_vente_destination_id'=> ['required', 'integer', Appartenance::a('points_de_vente', 'id'), 'different:point_de_vente_source_id'],
            'quantite'                     => Quantite::physique(),
            'note'                         => ['nullable', 'string', 'max:500'],
        ], [
            'point_de_vente_destination_id.different' => 'Le point de vente de destination doit être différent de la source.',
        ]);

        $produit = Produit::findOrFail($request->produit_id);
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $sourceId = $request->point_de_vente_source_id;
        $destId   = $request->point_de_vente_destination_id;
        $qty      = round((float) $request->quantite, Stock::DECIMALES);

        // Vérifier le stock disponible à la source
        $stockSource = Stock::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $sourceId)
            ->first();

        $dispo = $stockSource ? $stockSource->quantite_disponible : 0;

        if ($qty > $dispo) {
            return back()->with('erreur', "Quantité insuffisante à la source (Disponible: $dispo).");
        }

        $user = Auth::user();
        $isAdmin = $user->role === 'admin';

        DB::transaction(function () use ($produit, $sourceId, $destId, $qty, $request, $user, $isAdmin) {
            $statut = $isAdmin ? 'approuve' : 'en_attente';

            $transfert = TransfertStock::create([
                'produit_id'                    => $produit->id,
                'point_de_vente_source_id'      => $sourceId,
                'point_de_vente_destination_id'=> $destId,
                'quantite'                      => $qty,
                'statut'                        => $statut,
                'demandeur_id'                  => $user->id,
                'approbateur_id'                => $isAdmin ? $user->id : null,
                'approuve_le'                   => $isAdmin ? now() : null,
                'note'                          => $request->note,
            ]);

            // Si admin, on applique directement le mouvement de stock
            if ($isAdmin) {
                StockService::transferer($produit, (int) $sourceId, (int) $destId, $qty, [
                    'piece'     => $transfert,
                    'reference' => 'TRANSFERT-DIR-' . $transfert->id,
                ]);

                // Journaliser
                JournalAudit::create([
                    'entreprise_id'  => $produit->entreprise_id,
                    'utilisateur_id' => $user->id,
                    'action'         => 'transfert_direct',
                    'details'        => "Transfert direct de {$qty} {$produit->unite} de {$produit->nom} vers site ID {$destId}",
                ]);
            } else {
                // Journaliser la demande
                JournalAudit::create([
                    'entreprise_id'  => $produit->entreprise_id,
                    'utilisateur_id' => $user->id,
                    'action'         => 'demande_transfert',
                    'details'        => "Demande de transfert de {$qty} {$produit->unite} de {$produit->nom} vers site ID {$destId}",
                ]);
            }
        });

        $message = $isAdmin 
            ? 'Transfert de stock effectué avec succès.' 
            : 'Demande de transfert soumise à l\'approbation de l\'administrateur.';

        return back()->with('succes', $message);
    }

    /**
     * Approuver une demande de transfert (Admin uniquement).
     */
    public function valider(TransfertStock $transfert): RedirectResponse
    {
        abort_unless(Auth::user()->role === 'admin', 403);
        abort_unless($transfert->produit->entreprise_id === Auth::user()->entreprise_id, 404);

        if ($transfert->statut !== 'en_attente') {
            return back()->with('erreur', 'Ce transfert a déjà été traité.');
        }

        // Vérifier à nouveau la disponibilité du stock
        $stockSource = Stock::where('produit_id', $transfert->produit_id)
            ->where('point_de_vente_id', $transfert->point_de_vente_source_id)
            ->first();

        $dispo = $stockSource ? $stockSource->quantite_disponible : 0;

        if ($transfert->quantite > $dispo) {
            return back()->with('erreur', "Impossible d'approuver : stock insuffisant à la source (Disponible: $dispo).");
        }

        DB::transaction(function () use ($transfert) {
            $produit = $transfert->produit;

            $transfert->update([
                'statut'         => 'approuve',
                'approbateur_id' => Auth::id(),
                'approuve_le'    => now(),
            ]);

            StockService::transferer(
                $produit,
                (int) $transfert->point_de_vente_source_id,
                (int) $transfert->point_de_vente_destination_id,
                (float) $transfert->quantite,
                ['piece' => $transfert, 'reference' => 'TRANSFERT-APP-' . $transfert->id]
            );

            // Journaliser
            JournalAudit::create([
                'entreprise_id'  => $produit->entreprise_id,
                'utilisateur_id' => Auth::id(),
                'action'         => 'approbation_transfert',
                'details'        => "Approbation du transfert #{$transfert->id} de {$transfert->quantite} {$produit->unite} de {$produit->nom}",
            ]);
        });

        return back()->with('succes', 'Demande de transfert approuvée et appliquée au stock.');
    }

    /**
     * Rejeter une demande de transfert (Admin uniquement).
     */
    public function rejeter(TransfertStock $transfert): RedirectResponse
    {
        abort_unless(Auth::user()->role === 'admin', 403);
        abort_unless($transfert->produit->entreprise_id === Auth::user()->entreprise_id, 404);

        if ($transfert->statut !== 'en_attente') {
            return back()->with('erreur', 'Ce transfert a déjà été traité.');
        }

        $transfert->update([
            'statut'         => 'rejete',
            'approbateur_id' => Auth::id(),
            'approuve_le'    => now(),
        ]);

        // Journaliser
        JournalAudit::create([
            'entreprise_id'  => $transfert->produit->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'rejet_transfert',
            'details'        => "Rejet du transfert #{$transfert->id} de {$transfert->quantite} {$transfert->produit->nom}",
        ]);

        return back()->with('succes', 'Demande de transfert rejetée.');
    }
}
