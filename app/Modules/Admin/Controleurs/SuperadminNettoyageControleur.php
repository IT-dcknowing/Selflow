<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\VenteDetailTaxe;
use App\Modules\Admin\Modeles\VenteTaxe;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SuperadminNettoyageControleur
{
    use \App\Modules\Admin\Traits\JournaliseActions;

    public function index(): View
    {
        $entreprises = Entreprise::withoutGlobalScopes()->orderBy('nom')->get();
        $stats = [];

        foreach ($entreprises as $entreprise) {
            $pieces = fn (string $type, bool $archive) => Vente::withoutGlobalScopes()
                ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                ->where('archived', $archive)
                ->where(function ($q) use ($type) {
                    $type === 'avoir'
                        ? $q->where('type_facture', 'avoir')
                        : $q->where(fn ($v) => $v->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'));
                })->count();

            $stats[$entreprise->id] = [
                'ventes_actives' => $pieces('vente', false),
                'ventes_archivees' => $pieces('vente', true),
                'avoirs_actifs' => $pieces('avoir', false),
                'avoirs_archives' => $pieces('avoir', true),
                'achats_actifs' => Achat::withoutGlobalScopes()->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))->where('archived', false)->count(),
                'achats_archives' => Achat::withoutGlobalScopes()->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))->where('archived', true)->count(),
                'points_de_vente' => PointDeVente::where('entreprise_id', $entreprise->id)->count(),
            ];
        }

        return view('admin::superadmin.nettoyage.index', compact('entreprises', 'stats'));
    }

    public function executer(Request $request): RedirectResponse
    {
        $request->validate([
            'entreprise_id' => ['required', 'integer', 'exists:entreprises,id'],
            'cible' => ['required', 'in:ventes,avoirs,achats,points_de_vente'],
            'etat' => ['required', 'in:actifs,archives,tous,ouverts,fermes'],
            'action' => ['required', 'in:archiver,restaurer,purger,fermer,ouvrir,supprimer'],
            'confirmation' => ['required', 'string', 'max:150'],
            'confirmer' => ['accepted'],
        ]);

        $entreprise = Entreprise::withoutGlobalScopes()->findOrFail($request->integer('entreprise_id'));
        abort_unless(hash_equals((string) $entreprise->nom, trim($request->confirmation)), 422,
            'Le nom de l’entreprise saisi ne correspond pas. Aucune donnée n’a été modifiée.');

        $cible = $request->string('cible')->toString();
        $action = $request->string('action')->toString();
        $actionsDocuments = ['archiver', 'restaurer', 'purger'];
        $actionsSites = ['fermer', 'ouvrir', 'supprimer'];
        abort_unless(
            in_array($action, $cible === 'points_de_vente' ? $actionsSites : $actionsDocuments, true),
            422,
            'Cette action ne s’applique pas à la catégorie choisie.'
        );

        $resultat = DB::transaction(function () use ($request, $entreprise, $cible, $action) {
            if ($cible === 'points_de_vente') {
                $sites = PointDeVente::where('entreprise_id', $entreprise->id)
                    ->when($request->etat === 'ouverts', fn ($q) => $q->where('statut', 'Ouvert'))
                    ->when($request->etat === 'fermes', fn ($q) => $q->where('statut', 'Fermé'))
                    ->lockForUpdate()->get();

                if ($action === 'fermer' || $action === 'ouvrir') {
                    $nouveauStatut = $action === 'fermer' ? 'Fermé' : 'Ouvert';
                    $sites->each(fn (PointDeVente $site) => $site->update(['statut' => $nouveauStatut]));
                    return ['traites' => $sites->count(), 'refuses' => 0];
                }

                $traites = 0;
                $refuses = 0;
                foreach ($sites as $site) {
                    if (!$this->pointDeVenteEstVide($site)) {
                        $refuses++;
                        continue;
                    }
                    $site->delete();
                    $traites++;
                }
                return compact('traites', 'refuses');
            }

            $requete = match ($cible) {
                'ventes', 'avoirs' => Vente::withoutGlobalScopes()
                    ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                    ->where(function ($q) use ($cible) {
                        $cible === 'avoirs'
                            ? $q->where('type_facture', 'avoir')
                            : $q->where(fn ($v) => $v->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'));
                    }),
                'achats' => Achat::withoutGlobalScopes()
                    ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id)),
            };

            $requete->when($request->etat === 'actifs', fn ($q) => $q->where('archived', false))
                ->when($request->etat === 'archives', fn ($q) => $q->where('archived', true))
                ->lockForUpdate();

            if ($action === 'archiver' || $action === 'restaurer') {
                $nombre = $requete->update(['archived' => $action === 'archiver']);
                return ['traites' => $nombre, 'refuses' => 0];
            }

            $traites = 0;
            $refuses = 0;
            foreach ($requete->get() as $piece) {
                if (!$this->piecePeutEtrePurgee($piece, $cible)) {
                    $refuses++;
                    continue;
                }

                if ($piece instanceof Vente) {
                    $details = VenteDetail::where('vente_id', $piece->id)->get();
                    foreach ($details as $detail) {
                        $detail->taxes()->delete();
                    }
                    $piece->details()->delete();
                    $piece->taxesPersonnalisees()->delete();
                    $piece->rejets()->delete();
                } else {
                    $piece->details()->delete();
                }

                $piece->delete();
                $traites++;
            }

            return compact('traites', 'refuses');
        });

        $this->journaliser('nettoyage_donnees_superadmin', 'Entreprise', $entreprise->id, null, [
            'cible' => $cible,
            'action' => $action,
            'etat' => $request->etat,
            'traites' => $resultat['traites'],
            'refuses' => $resultat['refuses'],
        ]);

        $message = $resultat['traites'] . ' élément(s) traité(s).';
        if ($resultat['refuses'] > 0) {
            $message .= ' ' . $resultat['refuses'] . ' élément(s) conservé(s) car ils portent encore des écritures, des mouvements de stock, une certification ou des dépendances.';
        }

        return redirect()->route('superadmin.nettoyage.index')->with('succes', $message);
    }

    private function piecePeutEtrePurgee(Vente|Achat $piece, string $cible): bool
    {
        if ((bool) $piece->normalise || filled($piece->numero_fne) || filled($piece->signature_dgi)) {
            return false;
        }

        $reference = (string) $piece->numero_facture;
        if (Operation::where('reference_document', $reference)->exists()
            || TresorerieJournal::where('reference_document', $reference)->exists()
            || MouvementStock::where('reference_document', $reference)->exists()) {
            return false;
        }

        if ($piece instanceof Vente) {
            return !$piece->avoirs()->exists()
                && !$piece->bonsLivraison()->exists()
                && !$piece->pieceLiee()->exists()
                && !$piece->convertiEn()->exists()
                && !$piece->parent()->exists();
        }

        if ((bool) $piece->declaree_par_fournisseur) {
            return false;
        }

        return !$piece->details()->where('quantite_receptionnee', '>', 0)->exists();
    }

    private function pointDeVenteEstVide(PointDeVente $site): bool
    {
        foreach (['ventes', 'achats', 'mouvements_stock', 'tresorerie_journal', 'utilisateurs', 'stocks', 'lots', 'bons_livraison'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'point_de_vente_id')
                && DB::table($table)->where('point_de_vente_id', $site->id)->exists()) {
                return false;
            }
        }

        return true;
    }
}
