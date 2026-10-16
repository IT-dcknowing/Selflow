<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\IpBloquee;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Modeles\Visite;
use App\Modules\Admin\Services\AlerteSuperadminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Maintenance & traçabilité — la performance et le trafic de la plateforme
 * (16/10/2026) : KPI en direct, graphe des visites, activité par adresse IP,
 * et le blocage d'une IP qui n'a plus alors accès à rien.
 */
class SuperadminTracabiliteControleur
{
    use \App\Modules\Admin\Traits\JournaliseActions;

    public function index(Request $request): View
    {
        $depuis24h = now()->subDay();
        $depuis7j  = now()->subDays(7);

        // ── KPI (24 h) ──
        $base = Visite::where('cree_le', '>=', $depuis24h);
        $kpi = [
            'requetes'     => (clone $base)->count(),
            'visiteurs'    => (clone $base)->distinct('ip')->count('ip'),
            'connectes'    => (clone $base)->whereNotNull('utilisateur_id')->distinct('utilisateur_id')->count('utilisateur_id'),
            'entreprises'  => (clone $base)->whereNotNull('entreprise_id')->distinct('entreprise_id')->count('entreprise_id'),
            'duree_moy'    => (int) round((clone $base)->avg('duree_ms') ?? 0),
            'erreurs'      => (clone $base)->where('statut', '>=', 500)->count(),
            'refus'        => (clone $base)->whereIn('statut', [401, 403, 429])->count(),
        ];

        // ── Graphe : requêtes par heure sur 24 h ──
        $parHeure = Visite::where('cree_le', '>=', $depuis24h)
            ->get(['cree_le'])
            ->groupBy(fn ($v) => $v->cree_le->format('Y-m-d H'))
            ->map->count();

        $graphe = [];
        for ($i = 23; $i >= 0; $i--) {
            $h = now()->subHours($i);
            $graphe[] = [
                'label'  => $h->format('H') . 'h',
                'valeur' => (int) ($parHeure[$h->format('Y-m-d H')] ?? 0),
            ];
        }

        // ── Pages les plus consultées (7 j), avec le temps moyen ──
        $pages = Visite::where('cree_le', '>=', $depuis7j)
            ->select('chemin', DB::raw('COUNT(*) as vues'), DB::raw('AVG(duree_ms) as duree_moy'))
            ->groupBy('chemin')
            ->orderByDesc('vues')
            ->limit(15)
            ->get();

        // ── Activité par adresse IP (24 h) ──
        $ips = Visite::where('cree_le', '>=', $depuis24h)
            ->select('ip',
                DB::raw('COUNT(*) as requetes'),
                DB::raw('COUNT(DISTINCT chemin) as pages'),
                DB::raw('MAX(cree_le) as derniere'),
                DB::raw('SUM(CASE WHEN statut >= 400 THEN 1 ELSE 0 END) as erreurs'))
            ->groupBy('ip')
            ->orderByDesc('requetes')
            ->limit(30)
            ->get();

        $bloquees = IpBloquee::orderByDesc('created_at')->get();
        $listeBloquees = $bloquees->pluck('ip')->flip();

        return view('admin::superadmin.tracabilite.index', [
            'kpi'            => $kpi,
            'graphe'         => $graphe,
            'grapheMax'      => max(1, collect($graphe)->max('valeur')),
            'pages'          => $pages,
            'ips'            => $ips,
            'bloquees'       => $bloquees,
            'listeBloquees'  => $listeBloquees,
            'active'         => \App\Http\Middleware\JournaliserVisite::active(),
        ]);
    }

    /** L'activité détaillée d'une adresse IP : ses pages, le temps sur chacune. */
    public function ip(Request $request, string $ip): View
    {
        $depuis7j = now()->subDays(7);

        $visites = Visite::where('ip', $ip)
            ->where('cree_le', '>=', $depuis7j)
            ->orderByDesc('cree_le')
            ->paginate(50);

        $pages = Visite::where('ip', $ip)->where('cree_le', '>=', $depuis7j)
            ->select('chemin', DB::raw('COUNT(*) as vues'), DB::raw('AVG(duree_ms) as duree_moy'))
            ->groupBy('chemin')->orderByDesc('vues')->limit(20)->get();

        // Le temps par page s'estime par l'écart entre deux requêtes de la même
        // session : c'est l'approximation des journaux serveur, la seule
        // possible sans mouchard dans le navigateur. On le dit à l'écran.
        $tempsEstime = $this->tempsParPage($ip, $depuis7j);

        return view('admin::superadmin.tracabilite.ip', [
            'ip'          => $ip,
            'visites'     => $visites,
            'pages'       => $pages,
            'tempsEstime' => $tempsEstime,
            'estBloquee'  => IpBloquee::estBloquee($ip),
        ]);
    }

    /**
     * Le temps passé par page, estimé par l'écart entre requêtes successives
     * d'une même session (plafonné à 10 min : au-delà, l'onglet a été quitté).
     *
     * @return array<string, int>  chemin => secondes cumulées
     */
    private function tempsParPage(string $ip, Carbon $depuis): array
    {
        $visites = Visite::where('ip', $ip)->where('cree_le', '>=', $depuis)
            ->orderBy('session_id')->orderBy('cree_le')
            ->get(['session_id', 'chemin', 'cree_le']);

        $temps = [];
        $precedente = null;
        foreach ($visites as $v) {
            if ($precedente && $precedente->session_id === $v->session_id) {
                $ecart = min(600, $v->cree_le->diffInSeconds($precedente->cree_le));
                $temps[$precedente->chemin] = ($temps[$precedente->chemin] ?? 0) + $ecart;
            }
            $precedente = $v;
        }

        arsort($temps);

        return array_slice($temps, 0, 20, true);
    }

    /**
     * La traçabilité par entreprise (16/10/2026) : tout ce qui se fait —
     * création, modification, suppression — qui l'a fait, quand, et d'où.
     */
    public function audit(Request $request): View
    {
        $journal = \App\Modules\Admin\Modeles\JournalAudit::query()
            ->with(['utilisateur:id,nom,prenom,email,role', 'entreprise:id,nom'])
            ->when($request->filled('entreprise'), fn ($q) => $q->where('entreprise_id', $request->integer('entreprise')))
            ->when($request->filled('utilisateur'), fn ($q) => $q->where('utilisateur_id', $request->integer('utilisateur')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%' . $request->string('action') . '%'))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('au')))
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $entreprises = \App\Modules\Admin\Modeles\Entreprise::orderBy('nom')->get(['id', 'nom']);

        return view('admin::superadmin.tracabilite.audit', [
            'journal'      => $journal,
            'entreprises'  => $entreprises,
            'filtres'      => $request->only(['entreprise', 'utilisateur', 'action', 'du', 'au']),
        ]);
    }

    public function bloquer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'ip'    => ['required', 'ip'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);

        IpBloquee::updateOrCreate(
            ['ip' => $donnees['ip']],
            ['motif' => $donnees['motif'] ?? null, 'bloquee_par' => Auth::id()]
        );

        $this->journaliser('blocage_ip', 'Ip', null, [], ['ip' => $donnees['ip'], 'motif' => $donnees['motif'] ?? null]);

        try {
            AlerteSuperadminService::alerter(
                AlerteSuperadminService::ATTAQUE,
                'IP bloquée : ' . $donnees['ip'],
                'L\'adresse ' . $donnees['ip'] . ' a été bloquée'
                . (($donnees['motif'] ?? null) ? ' (' . $donnees['motif'] . ')' : '')
                . ' par ' . (Auth::user()?->email ?? 'le support') . ' le ' . now()->format('d/m/Y à H:i') . '.'
            );
        } catch (\Throwable $e) {
            // sans conséquence
        }

        return back()->with('succes', "L'adresse {$donnees['ip']} est bloquée : elle n'a plus accès à la plateforme.");
    }

    public function debloquer(Request $request, string $ip): RedirectResponse
    {
        IpBloquee::where('ip', $ip)->delete();
        // Un delete par requête ne déclenche pas l'événement du modèle : on vide
        // le cache nous-mêmes, sinon l'IP resterait bloquée une minute de plus.
        \Illuminate\Support\Facades\Cache::forget('ip_bloquees');
        $this->journaliser('deblocage_ip', 'Ip', null, [], ['ip' => $ip]);

        return back()->with('succes', "L'adresse {$ip} est débloquée.");
    }

    public function basculerTracabilite(Request $request): RedirectResponse
    {
        $active = \App\Http\Middleware\JournaliserVisite::active();
        ParametrePlateforme::ecrire('tracabilite_trafic', $active ? 'non' : 'oui');
        \Illuminate\Support\Facades\Cache::forget('tracabilite_active');

        return back()->with('succes', $active
            ? 'Traçabilité du trafic mise en pause : aucune nouvelle visite n\'est enregistrée.'
            : 'Traçabilité du trafic réactivée.');
    }
}
