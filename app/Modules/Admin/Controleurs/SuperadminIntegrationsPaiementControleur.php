<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\IntegrationPaiement;
use App\Modules\Admin\Modeles\PaiementAbonnement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SuperadminIntegrationsPaiementControleur extends Controller
{
    public function index(): View
    {
        $integrations = IntegrationPaiement::query()->get()->keyBy('fournisseur');
        $debut = now()->startOfMonth()->subMonths(11);
        $paiements = PaiementAbonnement::query()
            ->where('statut', 'active')
            ->whereNotNull('active_le')
            ->where('active_le', '>=', $debut)
            ->get(['offre', 'nature', 'montant', 'active_le']);

        $mois = collect(range(0, 11))->mapWithKeys(function (int $decalage) use ($debut): array {
            $date = $debut->copy()->addMonths($decalage);
            return [$date->format('Y-m') => [
                'libelle' => $date->translatedFormat('M Y'),
                'abonnements' => 0.0,
                'renouvellements' => 0.0,
                'total' => 0.0,
            ]];
        });

        foreach ($paiements as $paiement) {
            $cle = $paiement->active_le?->format('Y-m');
            if (!$cle || !$mois->has($cle)) {
                continue;
            }

            $ligne = $mois->get($cle);
            $montant = (float) $paiement->montant;
            if ($paiement->nature === 'souscription') {
                $ligne['abonnements'] += $montant;
            } elseif ($paiement->nature === 'renouvellement') {
                $ligne['renouvellements'] += $montant;
            }
            $ligne['total'] += $montant;
            $mois->put($cle, $ligne);
        }

        $revenusParOffre = $paiements
            ->filter(fn ($paiement) => $paiement->active_le?->isSameMonth(now()))
            ->groupBy('offre')
            ->map(fn ($lignes) => [
                'abonnements' => (float) $lignes->where('nature', 'souscription')->sum('montant'),
                'renouvellements' => (float) $lignes->where('nature', 'renouvellement')->sum('montant'),
                'total' => (float) $lignes->sum('montant'),
                'nombre' => $lignes->count(),
            ]);

        $courant = $mois->last() ?? ['abonnements' => 0, 'renouvellements' => 0, 'total' => 0];
        $totalPeriode = (float) $mois->sum('total');

        return view('admin::superadmin.integrations.paiements', [
            'integrations' => $integrations,
            'fournisseurs' => IntegrationPaiement::FOURNISSEURS,
            'offres' => \App\Modules\Admin\Regles\Abonnements::offres(),
            'revenusParOffre' => $revenusParOffre,
            'mois' => $mois->values(),
            'revenuMois' => $courant,
            'totalPeriode' => $totalPeriode,
            'nombrePaiements' => $paiements->count(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'fournisseur' => ['required', Rule::in(array_keys(IntegrationPaiement::FOURNISSEURS))],
            'environnement' => ['required', Rule::in(['sandbox', 'production'])],
            'api_url' => ['nullable', 'url', 'max:500'],
            'merchant_id' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:4000'],
            'api_secret' => ['nullable', 'string', 'max:4000'],
            'webhook_secret' => ['nullable', 'string', 'max:4000'],
            'configuration' => ['nullable', 'string', 'max:20000'],
            'actif' => ['required', 'in:0,1'],
        ]);

        $configuration = null;
        if (filled($donnees['configuration'] ?? null)) {
            $configuration = json_decode($donnees['configuration'], true);
            if (!is_array($configuration) || json_last_error() !== JSON_ERROR_NONE) {
                return back()->withErrors(['configuration' => 'Les paramètres complémentaires doivent être un objet JSON valide.'])->withInput();
            }
        }

        $integration = IntegrationPaiement::firstOrNew(['fournisseur' => $donnees['fournisseur']]);
        $integration->fill([
            'environnement' => $donnees['environnement'],
            'api_url' => $donnees['api_url'] ?: null,
            'merchant_id' => $donnees['merchant_id'] ?: null,
            'client_id' => $donnees['client_id'] ?: null,
            'actif' => $donnees['actif'] === '1',
            'modifie_par' => Auth::id(),
        ]);

        // Un champ secret laissé vide conserve le secret déjà chiffré.
        foreach (['api_key', 'api_secret', 'webhook_secret'] as $secret) {
            if (filled($donnees[$secret] ?? null)) {
                $integration->{$secret} = $donnees[$secret];
            }
        }

        if ($configuration !== null) {
            $integration->configuration = $configuration;
        }

        $integration->save();

        return back()->with('succes', IntegrationPaiement::FOURNISSEURS[$integration->fournisseur] . ' : paramètres enregistrés. Les secrets sont chiffrés en base et ne sont jamais renvoyés au navigateur.');
    }
}
