<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Switch entreprise — l'assistance (19/10/2026).
 *
 * Le superadministrateur ouvre l'espace d'une entreprise tel que son
 * utilisateur le voit : « Accéder » prend le compte de l'administrateur
 * principal, « Se connecter » celui d'un membre précis. Un bandeau reste
 * affiché tout du long, et « Revenir au superadmin » rend la session
 * d'origine.
 *
 * Tout se trace : l'entrée et la sortie au journal d'audit de l'entreprise,
 * et chaque action faite pendant l'incarnation porte le nom du
 * superadministrateur (`JournaliseActions`).
 */
class SuperadminSwitchControleur
{
    use \App\Modules\Admin\Traits\JournaliseActions;

    public const SESSION = 'incarnation';

    /** Une requête dans ce délai, et l'utilisateur est « connecté ». */
    public const MINUTES_EN_LIGNE = 5;

    /** 3725 secondes → « 1 h 02 min ». */
    public static function duree(?int $secondes): string
    {
        if ($secondes === null) {
            return '—';
        }
        if ($secondes < 60) {
            return $secondes . ' s';
        }
        $h = intdiv($secondes, 3600);
        $m = intdiv($secondes % 3600, 60);

        return $h ? sprintf('%d h %02d min', $h, $m) : $m . ' min';
    }

    public function index(Request $request): View
    {
        $requete = Entreprise::horsCorbeille()
            ->withCount(['utilisateurs', 'pointsDeVente'])
            ->with([
                'utilisateurs' => fn ($q) => $q->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")->orderBy('nom'),
                'utilisateurs.pointDeVente:id,nom',
                'pointsDeVente' => fn ($q) => $q->orderBy('nom')->select('id', 'entreprise_id', 'nom'),
            ])
            ->when($request->filled('q'), function ($q) use ($request) {
                $mot = '%' . $request->string('q') . '%';
                $q->where(fn ($w) => $w->where('nom', 'like', $mot)
                    ->orWhereHas('utilisateurs', fn ($u) => $u->where('email', 'like', $mot)->orWhere('nom', 'like', $mot)));
            })
            ->when($request->input('statut') === 'actif', fn ($q) => $q->where(fn ($w) => $w->whereNull('statut')->orWhere('statut', '!=', 'bloque')))
            ->when($request->input('statut') === 'bloque', fn ($q) => $q->where('statut', 'bloque'))
            ->orderBy('nom');

        $kpi = [
            'total'        => Entreprise::horsCorbeille()->count(),
            'actives'      => Entreprise::horsCorbeille()->where(fn ($w) => $w->whereNull('statut')->orWhere('statut', '!=', 'bloque'))->count(),
            'bloquees'     => Entreprise::horsCorbeille()->where('statut', 'bloque')->count(),
            'utilisateurs' => Utilisateur::whereNotNull('entreprise_id')->where('role', '!=', 'superadmin')->count(),
        ];

        return view('admin::superadmin.switch.index', [
            'entreprises' => $requete->paginate(20)->withQueryString(),
            'kpi'         => $kpi,
            'filtres'     => $request->only(['q', 'statut']),
        ]);
    }

    /**
     * La fiche détaillée d'une entreprise (19/10/2026) : ses points de vente —
     * qui y est affecté, ce qui y est en stock, ce qui s'y vend —, ses
     * utilisateurs et l'essentiel de son dossier.
     */
    public function entreprise(Entreprise $entreprise): View
    {
        $sites = $entreprise->pointsDeVente()->orderBy('nom')->get()->map(function ($site) {
            $site->membres = Utilisateur::where('point_de_vente_id', $site->id)->orderBy('nom')->get();
            $site->articles_en_stock = \App\Modules\Admin\Modeles\Stock::where('point_de_vente_id', $site->id)->where('quantite_disponible', '>', 0)->count();
            $site->entrees = \App\Modules\Admin\Modeles\MouvementStock::where('point_de_vente_id', $site->id)
                ->where('type_mouvement', \App\Modules\Admin\Modeles\MouvementStock::ENTREE)->count();
            $site->ventes = \App\Modules\Admin\Modeles\Vente::withoutGlobalScopes()->where('point_de_vente_id', $site->id)
                ->where('etape', 'Facture')->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))->count();
            $site->chiffre = (float) \App\Modules\Admin\Modeles\Vente::withoutGlobalScopes()->where('point_de_vente_id', $site->id)
                ->where('etape', 'Facture')->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
                ->where(fn ($q) => $q->whereNull('statut')->orWhere('statut', '!=', \App\Modules\Admin\Modeles\Vente::STATUT_ANNULEE))
                ->sum('montant_ttc');

            return $site;
        });

        $utilisateurs = $entreprise->utilisateurs()->with('pointDeVente:id,nom')
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")->orderBy('nom')->get();

        // La présence (20/10/2026) : connecté si une requête est passée dans
        // les cinq dernières minutes ; et la durée de la dernière connexion,
        // de la première à la dernière requête de sa dernière session. Lu dans
        // le journal du trafic (lot 104) ; la dernière ouverture de session,
        // dans le journal d'audit, quand le trafic n'a rien.
        foreach ($utilisateurs as $u) {
            $derniere = \App\Modules\Admin\Modeles\Visite::where('utilisateur_id', $u->id)->orderByDesc('cree_le')->first(['session_id', 'cree_le']);
            $u->vu_le = $derniere?->cree_le;
            $u->en_ligne = $derniere && $derniere->cree_le->gt(now()->subMinutes(self::MINUTES_EN_LIGNE));
            $u->duree_derniere_session = null;
            if ($derniere?->session_id) {
                $debut = \App\Modules\Admin\Modeles\Visite::where('utilisateur_id', $u->id)
                    ->where('session_id', $derniere->session_id)->min('cree_le');
                $u->duree_derniere_session = $debut ? (int) \Illuminate\Support\Carbon::parse($debut)->diffInSeconds($derniere->cree_le, true) : null;
            }
            $u->connecte_le = \App\Modules\Admin\Modeles\JournalAudit::where('utilisateur_id', $u->id)
                ->where('action', 'connexion')->latest('id')->value('created_at');
        }

        return view('admin::superadmin.switch.entreprise', [
            'entreprise'   => $entreprise,
            'sites'        => $sites,
            'utilisateurs' => $utilisateurs,
            'articles'     => \App\Modules\Admin\Modeles\Produit::where('entreprise_id', $entreprise->id)->count(),
        ]);
    }

    /** « Accéder » : l'administrateur principal de l'entreprise. */
    public function acceder(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $cible = $entreprise->utilisateurs()->where('role', 'admin')->orderBy('id')->first()
            ?? $entreprise->utilisateurs()->where('role', '!=', 'superadmin')->orderBy('id')->first();

        if (!$cible) {
            return back()->with('erreur', "« {$entreprise->nom} » n'a aucun utilisateur : il n'y a pas d'espace à ouvrir.");
        }

        return $this->incarner($request, $cible);
    }

    /** « Se connecter » : un membre précis. */
    public function incarner(Request $request, Utilisateur $utilisateur): RedirectResponse
    {
        $superadmin = Auth::user();
        $entreprise = $utilisateur->entreprise;

        abort_if($utilisateur->estSuperAdmin() || !$entreprise, 404);
        if ($entreprise->estEnCorbeille()) {
            return back()->with('erreur', 'Cette entreprise est en corbeille : restaurez-la avant d\'y entrer.');
        }

        // Tracé au nom du superadministrateur, avant de changer de compte.
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'utilisateur_id' => $superadmin->id,
            'entreprise_id'  => $entreprise->id,
            'action'         => 'assistance_debut',
            'entite'         => 'Utilisateur',
            'entite_id'      => $utilisateur->id,
            'nouvelle_valeur' => ['incarne' => $utilisateur->email, 'par' => $superadmin->email],
            'adresse_ip'     => $request->ip(),
        ]);

        Auth::login($utilisateur);
        $request->session()->regenerate();
        $request->session()->forget(['point_de_vente_actif_id', 'point_de_vente_actif_nom', 'apercu_pdv_id', 'apercu_pdv_nom']);
        $request->session()->put(self::SESSION, [
            'superadmin_id'    => $superadmin->id,
            'superadmin_email' => $superadmin->email,
            'utilisateur'      => trim($utilisateur->prenom . ' ' . $utilisateur->nom) ?: $utilisateur->email,
            'entreprise'       => $entreprise->nom,
            'depuis'           => now()->toIso8601String(),
        ]);

        $pdvId = $utilisateur->pointDeVenteDOuverture();
        $pdv = $pdvId ? PointDeVente::where('entreprise_id', $entreprise->id)->find($pdvId) : null;
        if ($pdv) {
            $request->session()->put(['point_de_vente_actif_id' => $pdv->id, 'point_de_vente_actif_nom' => $pdv->nom]);
        }

        return redirect()->route($utilisateur->estCaissier() ? 'caissier.tableau_de_bord' : 'admin.tableau_de_bord')
            ->with('succes', "Assistance : vous êtes dans l'espace de {$entreprise->nom}, en tant que {$utilisateur->email}.");
    }

    /** Revenir au superadmin : la session d'origine, et la trace de la sortie. */
    public function quitter(Request $request): RedirectResponse
    {
        $incarnation = $request->session()->get(self::SESSION);
        abort_unless(is_array($incarnation) && !empty($incarnation['superadmin_id']), 403);

        $superadmin = Utilisateur::find($incarnation['superadmin_id']);
        abort_unless($superadmin && $superadmin->estSuperAdmin(), 403);

        $quitte = Auth::user();
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'utilisateur_id' => $superadmin->id,
            'entreprise_id'  => $quitte?->entreprise_id,
            'action'         => 'assistance_fin',
            'entite'         => 'Utilisateur',
            'entite_id'      => $quitte?->id,
            'nouvelle_valeur' => ['incarne' => $quitte?->email, 'par' => $superadmin->email],
            'adresse_ip'     => $request->ip(),
        ]);

        Auth::login($superadmin);
        $request->session()->regenerate();
        $request->session()->forget([self::SESSION, 'point_de_vente_actif_id', 'point_de_vente_actif_nom', 'apercu_pdv_id', 'apercu_pdv_nom']);

        return redirect()->route('superadmin.switch.index')->with('succes', 'Vous êtes revenu à votre espace superadministrateur.');
    }
}
