<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Support\Structure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Maintenance › Temps d'affichage (24/11/2026, propriétaire : « les clics par
 * page, la moyenne d'exécution et d'apparition des pages, pour optimiser »).
 *
 * Deux mesures, par page (GET) et par bouton (POST, PUT, DELETE…) :
 * - **serveur** : la durée de la requête, déjà notée par la traçabilité
 *   (`visites.duree_ms`), regroupée par nom de route ;
 * - **affichage** : le temps qu'a mis la page à paraître dans le navigateur
 *   (Navigation Timing), envoyé par la page elle-même et cumulé par jour.
 */
class TempsAffichageControleur extends Controller
{
    /** Le navigateur signale le temps d'affichage de la page qu'il vient d'ouvrir. */
    public function noter(Request $request)
    {
        $donnees = $request->validate([
            'route' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._-]+$/'],
            'ms'    => ['required', 'integer', 'min:0', 'max:120000'],
        ]);

        if (! Structure::aTable('mesures_affichage')) {
            return response()->noContent();
        }

        $user = Auth::user();
        if ($user?->estSuperAdmin() || is_array(session('incarnation'))) {
            return response()->noContent();
        }

        $cle = ['jour' => now()->toDateString(), 'route' => $donnees['route'], 'entreprise_id' => $user?->entreprise_id];
        $ms = (int) $donnees['ms'];

        try {
            $modifiees = DB::table('mesures_affichage')->where($cle)->update([
                'nb'         => DB::raw('nb + 1'),
                'total_ms'   => DB::raw('total_ms + ' . $ms),
                'max_ms'     => DB::raw('CASE WHEN max_ms < ' . $ms . ' THEN ' . $ms . ' ELSE max_ms END'),
                'updated_at' => now(),
            ]);
            if ($modifiees === 0) {
                DB::table('mesures_affichage')->insertOrIgnore($cle + ['nb' => 1, 'total_ms' => $ms, 'max_ms' => $ms, 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Une mesure ne doit jamais gêner l'utilisateur.
        }

        return response()->noContent();
    }

    public function index(Request $request): View
    {
        $jours = in_array((int) $request->query('jours'), [1, 7, 30], true) ? (int) $request->query('jours') : 7;
        $entrepriseId = $request->integer('entreprise') ?: null;
        $depuis = now()->subDays($jours);

        $serveur = collect();
        if (Structure::aColonne('visites', 'route')) {
            $serveur = DB::table('visites')
                ->where('cree_le', '>=', $depuis)
                ->whereNotNull('route')
                ->when($entrepriseId, fn ($q) => $q->where('entreprise_id', $entrepriseId))
                ->groupBy('route', 'methode')
                ->selectRaw('route, methode, COUNT(*) as nb, AVG(duree_ms) as moyenne, MAX(duree_ms) as maxi, SUM(CASE WHEN statut >= 500 THEN 1 ELSE 0 END) as erreurs')
                ->orderByDesc('moyenne')
                ->limit(300)
                ->get();
        }

        $affichage = collect();
        if (Structure::aTable('mesures_affichage')) {
            $affichage = DB::table('mesures_affichage')
                ->where('jour', '>=', $depuis->toDateString())
                ->when($entrepriseId, fn ($q) => $q->where('entreprise_id', $entrepriseId))
                ->groupBy('route')
                ->selectRaw('route, SUM(nb) as nb, SUM(total_ms) as total, MAX(max_ms) as maxi')
                ->get()
                ->keyBy('route');
        }

        $pages = $serveur->where('methode', 'GET')->values();
        $boutons = $serveur->where('methode', '!=', 'GET')->values();

        return view('admin::superadmin.temps-affichage.index', [
            'jours'        => $jours,
            'entrepriseId' => $entrepriseId,
            'entreprises'  => \App\Modules\Admin\Modeles\Entreprise::query()->horsCorbeille()->orderBy('nom')->get(['id', 'nom']),
            'pages'        => $pages,
            'boutons'      => $boutons,
            'affichage'    => $affichage,
            'mesure'       => Structure::aColonne('visites', 'route'),
        ]);
    }
}
