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

    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;
        $factures = $this->service->enAttente($entreprise);

        $propositions = $factures->mapWithKeys(fn (PortailFneFactureRecue $f) => [
            $f->id => $this->service->proposer($f, $entreprise),
        ]);

        return view('admin::portail-fne.integration', [
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

    /** Intègre d'un coup toutes celles dont le point de vente est connu. */
    public function integrerPretes(): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        $faites = 0;
        $restes = 0;

        foreach ($this->service->enAttente($entreprise) as $facture) {
            try {
                $this->service->integrer($facture, $entreprise, Auth::id());
                $faites++;
            } catch (\DomainException) {
                $restes++;
            } catch (\Throwable $e) {
                Log::error('[portail FNE] intégration impossible de ' . $facture->reference . ' : ' . $e->getMessage());
                $restes++;
            }
        }

        return back()->with('succes', "{$faites} facture(s) intégrée(s)" . ($restes ? ", {$restes} à compléter à la main." : '.'));
    }

    public function ignorer(Request $request, PortailFneFactureRecue $facture): RedirectResponse
    {
        abort_unless((int) $facture->entreprise_id === (int) Auth::user()->entreprise_id, 404);

        $this->service->ignorer($facture, (string) $request->input('motif', ''));

        return back()->with('succes', "Facture {$facture->reference} écartée.");
    }
}
