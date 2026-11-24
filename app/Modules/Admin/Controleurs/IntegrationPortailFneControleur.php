<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\IntegrationFacturePortailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * « Factures FNE à intégrer » : les factures émises sur le portail de la DGI,
 * hors de Selflow, deviennent des ventes après validation (23/11/2026).
 */
class IntegrationPortailFneControleur extends Controller
{
    public function __construct(private IntegrationFacturePortailService $service)
    {
    }

    public function index(): View|RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        $toutes = $this->service->enAttente($entreprise);

        // 25 par page : 158 formulaires d'un coup, chacun avec le catalogue
        // entier en liste déroulante, rendaient la page interminable.
        $page = max(1, (int) request('page', 1));
        // Une page qui n'existe plus (les pièces ont été intégrées entre-temps)
        // ramène à la dernière, au lieu d'afficher « rien en attente ».
        $dernierePage = max(1, (int) ceil($toutes->count() / 25));
        if ($page > $dernierePage) {
            return redirect()->route('admin.ventes.portail_fne.index', array_merge(request()->except('page'), $dernierePage > 1 ? ['page' => $dernierePage] : []));
        }
        $factures = new \Illuminate\Pagination\LengthAwarePaginator(
            $toutes->forPage($page, 25)->values(), $toutes->count(), 25, $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $propositions = $factures->getCollection()->mapWithKeys(fn (PortailFneFactureRecue $f) => [
            $f->id => $this->service->proposer($f, $entreprise),
        ]);

        // Les lignes de la page, regroupées (24/11/2026) : par catégorie
        // d'article, les prestations et les lignes libres à part, avec les
        // comptes qui seront passés. On sait ainsi ce qu'il faut vérifier.
        $groupes = [];
        foreach ($propositions as $proposition) {
            foreach ($proposition['lignes'] as $l) {
                $produit = $l['produit'];
                if ($produit) {
                    $stocke = method_exists($produit, 'estStockable') ? $produit->estStockable() : ($produit->type !== 'service');
                    $cle = ($stocke ? 'Articles stockés' : 'Prestations de services') . ' · ' . ($produit->categorie ?: 'Sans catégorie');
                } else {
                    $cle = str_starts_with((string) $l['compte'], '706') ? 'Lignes libres · prestations de services' : 'Lignes libres · hors catalogue';
                }
                $ligne = $l['ligne'];
                $groupes[$cle] ??= ['lignes' => 0, 'montant' => 0.0, 'comptes' => [], 'libre' => ! $produit];
                $groupes[$cle]['lignes']++;
                $groupes[$cle]['montant'] += (float) $ligne->quantite * (float) $ligne->prix_unitaire * (1 - (float) $ligne->remise / 100);
                $groupes[$cle]['comptes'][(string) $l['compte']] = true;
            }
        }
        ksort($groupes);

        return view('admin::portail-fne.integration', [
            'groupes'       => $groupes,
            'arrierePlan'   => \App\Modules\Admin\Services\IntegrationPortailEnArrierePlan::etat($entreprise->id),
            'factures'      => $factures,
            'propositions'  => $propositions,
            'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(['id', 'nom']),
            'articles'      => Produit::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(['id', 'nom', 'reference']),
        ]);
    }

    public function integrer(Request $request, PortailFneFactureRecue $facture): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless((int) $facture->entreprise_id === (int) $entreprise->id, 404);

        $donnees = $request->validate([
            'point_de_vente_id'      => ['nullable', 'integer'],
            'lignes'                 => ['nullable', 'array'],
            'lignes.*.produit_id'    => ['nullable', 'integer'],
            'lignes.*.compte'        => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{3,20}$/'],
        ]);

        try {
            $vente = $this->service->integrer($facture, $entreprise, Auth::id(), $donnees);
        } catch (\DomainException $e) {
            return back()->with('erreur', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[portail FNE] intégration impossible de ' . $facture->reference . ' : ' . $e->getMessage());

            return back()->with('erreur', 'Une erreur s’est produite. Veuillez réessayer plus tard ou contacter le service assistance au '
                . config('selflow.assistance_telephone') . '.');
        }

        return back()->with('succes', "Facture {$facture->reference} intégrée comme {$vente->numero_facture}.");
    }

    /**
     * « Tout intégrer » : un lot par appel. L'écran rappelle tout seul jusqu'à
     * ce qu'il ne reste que des pièces à compléter à la main (23/11/2026 :
     * « 40 par clic, et les autres ? »).
     */
    /**
     * « Tout intégrer » en arrière-plan (23/11/2026) : la demande est notée,
     * un premier lot part tout de suite, le planificateur fait le reste. On
     * peut quitter la page.
     */
    public function lancerEnArrierePlan(Request $request, \App\Modules\Admin\Services\IntegrationPortailEnArrierePlan $arrierePlan): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $arrierePlan->demander($entreprise, (int) Auth::id(), $request->boolean('reconnus'));

        return response()->json($arrierePlan->lot($entreprise, 5));
    }

    public function etatArrierePlan(\App\Modules\Admin\Services\IntegrationPortailEnArrierePlan $arrierePlan): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $etat = \App\Modules\Admin\Services\IntegrationPortailEnArrierePlan::etat((int) $entreprise->id);

        // Filet : si le planificateur n'a pas touché la demande depuis 90 s
        // (cron arrêté), la page ouverte fait avancer un lot elle-même.
        if ($etat && $etat['etat'] !== 'termine'
            && \Illuminate\Support\Carbon::parse($etat['maj_le'])->lt(now()->subSeconds(90))) {
            $etat = $arrierePlan->lot($entreprise, 5);
        }

        return response()->json($etat);
    }

    public function integrerPretes(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $ecartees = collect((array) $request->input('a_la_main', []))->map(fn ($id) => (int) $id);

        // 20 par appel : chaque intégration écrit une pièce et ses écritures,
        // et l'hébergement coupe une requête trop longue.
        // « Intégrer les reconnus par NCC » : seulement les pièces dont le
        // client ou le fournisseur est déjà dans Selflow sous le même NCC.
        $reconnusSeulement = $request->boolean('reconnus');
        $candidates = $this->service->enAttente($entreprise)
            ->reject(fn ($f) => $ecartees->contains($f->id))
            ->when($reconnusSeulement, fn ($c) => $c->filter(fn ($f) => $this->service->tiersReconnuParNcc($entreprise, $f)));
        $lot = $candidates->take(20);
        $faites = 0;
        $aLaMain = [];

        foreach ($lot as $facture) {
            try {
                $this->service->integrer($facture, $entreprise, Auth::id());
                $faites++;
            } catch (\DomainException) {
                $aLaMain[] = $facture->id;
            } catch (\Throwable $e) {
                Log::error('[portail FNE] intégration impossible de ' . $facture->reference . ' : ' . $e->getMessage());
                $aLaMain[] = $facture->id;
            }
        }

        $restantes = $this->service->enAttente($entreprise)
            ->reject(fn ($f) => $ecartees->contains($f->id) || in_array($f->id, $aLaMain, true))
            ->when($reconnusSeulement, fn ($c) => $c->filter(fn ($f) => $this->service->tiersReconnuParNcc($entreprise, $f)))
            ->count();

        if ($request->expectsJson()) {
            return response()->json(['faites' => $faites, 'a_la_main' => $aLaMain, 'restantes' => $restantes]);
        }

        return back()->with('succes', "{$faites} pièce(s) intégrée(s)" . ($aLaMain ? ', ' . count($aLaMain) . ' à compléter à la main.' : '.'));
    }

    public function ignorer(Request $request, PortailFneFactureRecue $facture): RedirectResponse
    {
        abort_unless((int) $facture->entreprise_id === (int) Auth::user()->entreprise_id, 404);

        $this->service->ignorer($facture, (string) $request->input('motif', ''));

        return back()->with('succes', "Facture {$facture->reference} écartée.");
    }
}
