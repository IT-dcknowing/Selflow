<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Modules\Admin\Services\TrousseauEntrepriseService;
use App\Modules\Admin\Services\ExpressionSqlPortable;

class SuperadminControleur
{
    /**
     * Afficher le tableau de bord SuperAdmin avec KPIs enrichis.
     */
    public function tableauDeBord(): View
    {
        // ── KPI Globaux ──
        $totalEntreprises   = Entreprise::count();
        $totalPdvs          = PointDeVente::count();
        $totalUtilisateurs  = Utilisateur::count();

        // ── Utilisateurs par rôle ──
        $totalAdmins    = Utilisateur::where('role', 'admin')->count();
        $totalCaissiers = Utilisateur::where('role', 'caissier')->count();
        $totalActifsJour = Utilisateur::whereDate('updated_at', today())->count();

        // ── PDV par entreprise (moyennes) ──
        $avgPdvParEntreprise = $totalEntreprises > 0
            ? round($totalPdvs / $totalEntreprises, 1)
            : 0;

        // ── Entreprises par plan d'abonnement ──
        $parPlan = Entreprise::select('plan_abonnement', DB::raw('count(*) as total'))
            ->groupBy('plan_abonnement')
            ->pluck('total', 'plan_abonnement')
            ->toArray();

        // ── Inscriptions des 6 derniers mois ──
        $inscriptionsParMois = Entreprise::select(
                DB::raw(ExpressionSqlPortable::annee('created_at') . ' as annee'),
                DB::raw(ExpressionSqlPortable::mois('created_at') . ' as mois'),
                DB::raw('count(*) as total')
            )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('annee', 'mois')
            ->orderBy('annee')->orderBy('mois')
            ->get()
            ->map(fn($r) => [
                'label' => \Carbon\Carbon::createFromDate($r->annee, $r->mois, 1)->translatedFormat('M Y'),
                'total' => $r->total,
            ]);

        // ── Modules les plus utilisés ──
        $tousModules = [];
        Entreprise::whereNotNull('modules_actifs')->get()->each(function ($e) use (&$tousModules) {
            $mods = is_array($e->modules_actifs) ? $e->modules_actifs : json_decode($e->modules_actifs, true) ?? [];
            foreach ($mods as $m) {
                $tousModules[$m] = ($tousModules[$m] ?? 0) + 1;
            }
        });
        arsort($tousModules);
        $modulesPopulaires = array_slice($tousModules, 0, 6, true);

        // ── Entreprises récentes ──
        $entreprisesRecentes = Entreprise::withCount('pointsDeVente')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        // ── Entreprises sans utilisateurs ──
        $entreprisesSansUsers = Entreprise::doesntHave('utilisateurs')->count();

        return view('admin::superadmin.tableau_de_bord', compact(
            'totalEntreprises',
            'totalPdvs',
            'totalUtilisateurs',
            'totalAdmins',
            'totalCaissiers',
            'totalActifsJour',
            'avgPdvParEntreprise',
            'parPlan',
            'inscriptionsParMois',
            'modulesPopulaires',
            'entreprisesRecentes',
            'entreprisesSansUsers'
        ));
    }

    /**
     * Liste complète des entreprises.
     */
    public function entreprises(): View
    {
        $entreprises = Entreprise::orderBy('nom', 'asc')->paginate(10);
        return view('admin::superadmin.entreprises.index', compact('entreprises'));
    }

    /**
     * Afficher le formulaire de création d'entreprise.
     */
    public function creerFormulaire(): View
    {
        return view('admin::superadmin.entreprises.creer');
    }

    /**
     * Enregistrer une nouvelle entreprise et créer son point de vente "Siège".
     */
    public function creer(Request $request): RedirectResponse
    {
        // Normaliser le NCC : suppression des espaces et mise en majuscule
        $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);

        $request->validate([
            'nom'                     => ['required', 'string', 'max:150', 'unique:entreprises,nom'],
            'forme_juridique'         => ['nullable', 'string', 'max:50'],
            'gerant_nom'              => ['nullable', 'string', 'max:100'],
            'gerant_prenom'           => ['nullable', 'string', 'max:150'],
            'gerant_fonction'         => ['nullable', 'string', 'max:150'],

            // ── Le responsable, et son mot de passe ──
            //
            // Cet écran créait une entreprise **et personne pour s'y
            // connecter** : aucun `Utilisateur` n'était enregistré, et le
            // formulaire ne demandait pas même un mot de passe. Toute
            // entreprise créée par le superadministrateur était donc
            // inutilisable, sans que rien ne le signale — il fallait ensuite
            // lui fabriquer un compte à la main.
            'email'                   => ['required', 'email', 'max:150', 'unique:utilisateurs,email'],
            'gerant_password'         => ['required', 'string', 'min:8', 'confirmed'],

            'telephone'               => ['nullable', 'string', 'max:30'],
            'adresse'                 => ['nullable', 'string', 'max:255'],
            'rccm'                    => ['nullable', 'string', 'max:100'],
            'ncc'                     => ['nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'compte_contribuable'     => ['nullable', 'string', 'max:100'],
            'centre_impots'           => ['nullable', 'string', 'max:100'],
            // Le régime n'est pas une étiquette : `deduireCodeTva()` le compare
            // aux régimes d'exonération légale pour choisir entre TVAC et TVAD.
            // La règle acceptait ici n'importe quelle chaîne de 80 caractères,
            // et l'écran proposait cinq intitulés que rien ne reconnaît.
            'regime_imposition'       => ['nullable', 'string', Rule::in(Entreprise::regimesAcceptesPour())],
            'quota_points_de_vente'   => ['required', 'integer', 'min:1'],
            'plan_abonnement'         => ['required', 'string'],

            // Le domaine et la situation fiscale sont des étapes facultatives,
            // ici comme à l'inscription : elles se complètent aussi bien une
            // fois dans l'application. Voir EtapesCreation.
            'secteur_activite'        => ['nullable', 'array'],
            'secteur_activite.*'      => ['string', Rule::in(Categorie::domaines())],
            'modules_actifs'          => ['nullable', 'array'],

            // ── L'accès à l'espace FNE, si l'entreprise en a déjà un ──
            'possede_compte_fne'      => ['nullable', 'in:0,1'],
            'fne_ncc'                 => ['nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'fne_mot_de_passe'        => ['nullable', 'string', 'max:255'],

            // Le mot de passe du compte Comptaflow etait demande ici : le
            // superadministrateur choisissait celui d'un client, et il partait
            // en clair dans le corps de la requete. Comptaflow envoie son
            // propre lien d'activation au responsable.
        ], [
            'email.required'           => "L'adresse du responsable est obligatoire : c'est avec elle qu'il se connectera.",
            'email.unique'             => 'Cette adresse est déjà utilisée par un autre compte.',
            'gerant_password.required' => "Sans mot de passe, le responsable ne pourra pas entrer dans son espace.",
            'gerant_password.min'      => 'Le mot de passe doit contenir au moins 8 caractères.',
            'gerant_password.confirmed'=> 'La confirmation du mot de passe ne correspond pas.',
        ]);

        // Tous les modules sont activés par défaut — l'admin peut en désactiver dans les paramètres
        $tousModules = ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'];
        $modulesChoisis = is_array($request->modules_actifs) ? $request->modules_actifs : [];
        $modules = array_unique(array_merge($tousModules, $modulesChoisis));

        // Créer l'entreprise
        $entreprise = Entreprise::create([
            'nom'                    => $request->nom,
            'forme_juridique'        => $request->forme_juridique,
            'gerant_nom'             => $request->gerant_nom,
            'gerant_prenom'          => $request->gerant_prenom,
            'gerant_fonction'        => $request->gerant_fonction,
            'email'                  => $request->email,
            'telephone'              => $request->telephone,
            'adresse'                => $request->adresse,
            'rccm'                   => $request->rccm,
            'ncc'                    => $request->ncc,
            'compte_contribuable'    => $request->compte_contribuable,
            'centre_impots'          => $request->centre_impots,
            'regime_imposition'      => $request->regime_imposition,
            'quota_points_de_vente'  => $request->quota_points_de_vente,
            'plan_abonnement'        => $request->plan_abonnement,
            'secteur_activite'       => $request->secteur_activite ?? [],
            'modules_actifs'         => $modules,
            // Trois états : la question peut n'avoir pas encore été posée,
            // l'étape étant facultative.
            'possede_compte_fne'     => $request->filled('possede_compte_fne')
                ? $request->input('possede_compte_fne') === '1'
                : null,
        ]);

        // Sans plan comptable ni journal, la premiere vente s'impute sur des
        // comptes inventes a la volee. L'entreprise recoit donc de quoi
        // travailler des le premier jour ; ce qui ne lui sert pas, elle
        // l'archivera.
        TrousseauEntrepriseService::doter($entreprise);

        // ── Le responsable, sans qui l'entreprise n'est atteignable par
        //    personne ──
        //
        // Cet écran ne créait aucun compte : l'entreprise naissait sans
        // administrateur, et il fallait ensuite lui en fabriquer un à la
        // main. `doit_changer_password` est posé parce que le mot de passe
        // vient d'un tiers — le superadministrateur — et non de son
        // propriétaire : il doit être remplacé à la première connexion.
        Utilisateur::create([
            'entreprise_id'         => $entreprise->id,
            'nom'                   => $request->gerant_nom ?: 'Responsable',
            'prenom'                => $request->gerant_prenom ?: '',
            'email'                 => $request->email,
            'password'              => \Illuminate\Support\Facades\Hash::make($request->gerant_password),
            'role'                  => 'admin',
            'fonction'              => $request->gerant_fonction ?: 'Gérant',
            'statut'                => 'actif',
            'doit_changer_password' => true,
        ]);

        // L'acces a l'espace FNE, si l'entreprise en a deja un : chiffre au
        // repos, jamais rendu a l'ecran, efface une fois le parametrage releve.
        \App\Modules\Admin\Services\AccesFneService::enregistrer(
            $entreprise, $request->fne_ncc, $request->fne_mot_de_passe
        );

        // Un point de vente « Siège » se créait ici d'office, ville devinée en
        // coupant l'adresse à la première virgule, commune « Plateau »,
        // responsable « Responsable Général ». Trois informations inventées.
        //
        // **Le nom du point de vente est ce que la plateforme de la DGI
        // reçoit**, et elle refuse la facture s'il ne correspond à aucun site
        // déclaré sur l'espace FNE. Le créer d'office, c'est décider à la place
        // de l'entreprise du nom sous lequel ses pièces seront certifiées — et
        // celui-là, « Siège », a toutes les chances de ne pas être le sien.
        //
        // L'entreprise crée son premier point de vente elle-même ; tant qu'elle
        // ne l'a pas fait, l'écran le lui réclame et la caisse reste fermée.

        // ── Liaison COMPTAFLOW (si case cochée) ──
        //
        // Ce bloc tirait `Str::random(40)` et déclarait cette valeur « clé de
        // synchronisation » : Selflow inventait donc une clé que Comptaflow
        // devait accepter sur parole. Et il demandait au superadministrateur
        // de **choisir le mot de passe** du compte du client, qu'il envoyait
        // en clair dans le corps de la requête.
        //
        // Le provisionnement est passé au service : Comptaflow ouvre le
        // dossier, génère la clé, la renvoie, et envoie lui-même son lien
        // d'activation au responsable.
        $messageSupplement = '';
        if ($request->boolean('creer_compte_comptaflow')) {
            $resultat = \App\Modules\Admin\Services\LiaisonComptaflowService::valider($entreprise);

            $messageSupplement = $resultat['success']
                ? ' Le dossier Comptaflow a été ouvert et la liaison établie. ' . $resultat['message']
                : ' ⚠️ L\'entreprise Selflow est créée, mais le dossier Comptaflow n\'a pas pu être ouvert : ' . $resultat['message'];
        }

        return redirect()->route('superadmin.entreprises')->with('succes',
            'Entreprise créée, avec son point de vente « Siège » et le compte de son responsable ('
            . $entreprise->email . '). Il devra changer son mot de passe à la première connexion.'
            . $messageSupplement);
    }

    /**
     * Afficher le formulaire de modification d'entreprise.
     */
    public function modifierFormulaire(Entreprise $entreprise): View
    {
        return view('admin::superadmin.entreprises.modifier', compact('entreprise'));
    }

    /**
     * Mettre à jour le secteur d'activité et les modules actifs de l'entreprise.
     */
    public function modifier(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $request->validate([
            'nom'                     => ['required', 'string', 'max:150', 'unique:entreprises,nom,' . $entreprise->id],
            'gerant_nom'              => ['nullable', 'string', 'max:100'],
            'gerant_prenom'           => ['nullable', 'string', 'max:150'],
            'gerant_fonction'         => ['nullable', 'string', 'max:150'],
            'quota_points_de_vente'   => ['required', 'integer', 'min:1'],
            'plan_abonnement'         => ['required', 'string'],
            'secteur_activite'        => ['required', 'array'],
            'secteur_activite.*'      => ['required', 'string', Rule::in(Categorie::domaines())],
            'modules_actifs'          => ['required', 'array'],
        ]);

        // Conserver tous les modules activés par défaut même lors d'une mise à jour
        $tousModules = ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'];
        $modulesChoisis = is_array($request->modules_actifs) ? $request->modules_actifs : [];
        $modules = array_unique(array_merge($tousModules, $modulesChoisis));

        $entreprise->update([
            'nom'                    => $request->nom,
            'gerant_nom'             => $request->gerant_nom,
            'gerant_prenom'          => $request->gerant_prenom,
            'gerant_fonction'        => $request->gerant_fonction,
            'quota_points_de_vente'  => $request->quota_points_de_vente,
            'plan_abonnement'        => $request->plan_abonnement,
            'secteur_activite'       => $request->secteur_activite,
            'modules_actifs'         => $modules,
        ]);

        return redirect()->route('superadmin.entreprises')->with('succes', 'Entreprise mise à jour avec succès.');
    }

    /**
     * Activer ou Bloquer une entreprise.
     */
    public function toggleStatus(Entreprise $entreprise): RedirectResponse
    {
        $nouveauStatut = $entreprise->statut === 'bloque' ? 'actif' : 'bloque';
        $entreprise->update(['statut' => $nouveauStatut]);
        
        $message = $nouveauStatut === 'bloque' 
            ? "L'entreprise « {$entreprise->nom} » et tous ses utilisateurs ont été bloqués avec succès."
            : "L'entreprise « {$entreprise->nom} » a été réactivée.";

        return redirect()->route('superadmin.entreprises')->with('succes', $message);
    }

    /**
     * Supprimer une entreprise.
     */
    public function supprimer(Entreprise $entreprise): RedirectResponse
    {
        $nom = $entreprise->nom;
        
        // Supprimer les entités enfants associées
        $entreprise->utilisateurs()->delete();
        $entreprise->pointsDeVente()->delete();
        $entreprise->delete();

        return redirect()->route('superadmin.entreprises')->with('succes', "L'entreprise « {$nom} » a été supprimée avec succès.");
    }

    /**
     * Liste complète des utilisateurs de toutes les entreprises.
     */
    public function utilisateurs(Request $request): View
    {
        $query = Utilisateur::with('entreprise');

        // Filtrer par nom/email d'utilisateur
        if ($request->filled('recherche_utilisateur')) {
            $search = $request->recherche_utilisateur;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('prenom', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtrer par nom d'entreprise
        if ($request->filled('recherche_entreprise')) {
            $search = $request->recherche_entreprise;
            $query->whereHas('entreprise', function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%");
            });
        }

        $utilisateurs = $query->orderBy('created_at', 'desc')->paginate(15);
        $entreprises = Entreprise::orderBy('nom')->get();

        return view('admin::superadmin.utilisateurs.index', compact('utilisateurs', 'entreprises'));
    }

    public function modifierUtilisateur(Request $request, Utilisateur $utilisateur): RedirectResponse
    {
        $request->validate([
            'role'          => ['required', 'string', 'in:superadmin,admin,admin_secondaire,responsable_pdv,caissier'],
            'statut'        => ['required', 'string', 'in:actif,suspendu,inactif'],
            'habilitations' => ['nullable', 'array'],
            'password'      => ['nullable', 'string', 'min:6'],
        ]);

        $data = [
            'role'          => $request->role,
            'statut'        => $request->statut,
            'habilitations' => $request->habilitations ?? [],
        ];

        if ($request->filled('password')) {
            $data['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
        }

        $utilisateur->update($data);

        return redirect()->back()->with('succes', "L'utilisateur « {$utilisateur->nom} {$utilisateur->prenom} » a été mis à jour avec succès.");
    }

    // ─────────────────────────────────────────────────────────────
    // ADMINISTRATION INTERNE (SUPERADMINS CRUD)
    // ─────────────────────────────────────────────────────────────

    public function admins(Request $request): View
    {
        $query = Utilisateur::where('role', 'superadmin');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('prenom', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $admins = $query->orderBy('created_at', 'desc')->paginate(15);
        return view('admin::superadmin.admins.index', compact('admins'));
    }

    public function creerAdmin(): View
    {
        return view('admin::superadmin.admins.creer');
    }

    public function enregistrerAdmin(Request $request): RedirectResponse
    {
        $request->validate([
            'nom'           => ['required', 'string', 'max:100'],
            'prenom'        => ['required', 'string', 'max:150'],
            'email'         => ['required', 'email', 'max:150', 'unique:utilisateurs,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'habilitations' => ['nullable', 'array'],
            'statut'        => ['required', 'string', 'in:actif,inactif'],
        ]);

        Utilisateur::create([
            'entreprise_id'         => null,
            'nom'                   => trim($request->nom),
            'prenom'                => trim($request->prenom),
            'email'                 => trim($request->email),
            'password'              => \Illuminate\Support\Facades\Hash::make($request->password),
            'role'                  => 'superadmin',
            'statut'                => $request->statut,
            'habilitations'         => $request->habilitations ?? [],
            'doit_changer_password' => false,
        ]);

        return redirect()->route('superadmin.admins.index')->with('succes', 'Administrateur interne créé avec succès.');
    }

    public function modifierAdmin(Utilisateur $utilisateur): View
    {
        return view('admin::superadmin.admins.modifier', compact('utilisateur'));
    }

    public function mettreAJourAdmin(Request $request, Utilisateur $utilisateur): RedirectResponse
    {
        $request->validate([
            'nom'           => ['required', 'string', 'max:100'],
            'prenom'        => ['required', 'string', 'max:150'],
            'email'         => ['required', 'email', 'max:150', 'unique:utilisateurs,email,' . $utilisateur->id],
            'password'      => ['nullable', 'string', 'min:8', 'confirmed'],
            'habilitations' => ['nullable', 'array'],
            'statut'        => ['required', 'string', 'in:actif,inactif'],
        ]);

        $data = [
            'nom'           => trim($request->nom),
            'prenom'        => trim($request->prenom),
            'email'         => trim($request->email),
            'statut'        => $request->statut,
            'habilitations' => $request->habilitations ?? [],
        ];

        if ($utilisateur->email === 'superadmin@gmail.com') {
            $data['habilitations'] = [];
        }

        if ($request->filled('password')) {
            $data['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
        }

        $utilisateur->update($data);

        return redirect()->route('superadmin.admins.index')->with('succes', 'Administrateur interne mis à jour avec succès.');
    }

    public function supprimerAdmin(Utilisateur $utilisateur): RedirectResponse
    {
        if ($utilisateur->id === Auth::id()) {
            return redirect()->back()->with('erreur', 'Vous ne pouvez pas vous supprimer vous-même.');
        }

        if ($utilisateur->email === 'superadmin@gmail.com') {
            return redirect()->back()->with('erreur', 'Le super-administrateur principal ne peut pas être supprimé.');
        }

        $utilisateur->delete();

        return redirect()->route('superadmin.admins.index')->with('succes', 'Administrateur interne supprimé avec succès.');
    }

    // ─────────────────────────────────────────────────────────────
    // CONFIGURATION PLATEFORME — SECTEURS ↔ MODULES
    // ─────────────────────────────────────────────────────────────

    /** Définition des 10 modules de la plateforme */
    public static function tousLesModules(): array
    {
        return [
            'principal'      => ['label' => 'Tableau de bord',     'icone' => 'fa-chart-pie',          'par_defaut' => true,  'desactivable' => true],
            'ventes'         => ['label' => 'Ventes & Facturation', 'icone' => 'fa-cash-register',      'par_defaut' => true,  'desactivable' => true],
            'achats'         => ['label' => 'Achats',               'icone' => 'fa-cart-plus',          'par_defaut' => true,  'desactivable' => true],
            'stock'          => ['label' => 'Stock',                'icone' => 'fa-boxes-stacked',      'par_defaut' => true,  'desactivable' => true],
            'production'     => ['label' => 'Production',           'icone' => 'fa-industry',           'par_defaut' => true,  'desactivable' => true],
            'comptabilite'   => ['label' => 'Comptabilité & FNE',   'icone' => 'fa-book',               'par_defaut' => true,  'desactivable' => true],
            'points_de_vente'=> ['label' => 'Points de vente',      'icone' => 'fa-store',              'par_defaut' => true,  'desactivable' => true],
            'personnel'      => ['label' => 'Personnels & Accès',   'icone' => 'fa-users-gear',         'par_defaut' => true,  'desactivable' => true],
            'b2b'            => ['label' => 'Communication B2B',    'icone' => 'fa-handshake',          'par_defaut' => true,  'desactivable' => true],
            'fne'            => ['label' => 'Fiscalité DGI',        'icone' => 'fa-file-invoice-dollar','par_defaut' => true,  'desactivable' => true],
        ];
    }

    /**
     * Les domaines d'activité, tels que le référentiel les nomme.
     *
     * L'écran « secteurs ↔ modules » range sa configuration **par nom de
     * domaine**. Douze noms écrits en dur vivaient ici, qui n'étaient ceux
     * d'aucune catégorie du référentiel : la configuration se classait donc
     * sous des clés — « Commercial », « Agricole » — qu'aucune entreprise ne
     * portait, et elle ne servait jamais.
     *
     * L'apparence reste locale : un domaine que la table ci-dessous ne connaît
     * pas reçoit une pastille neutre plutôt que rien.
     */
    public static function tousLesSecteurs(): array
    {
        $apparences = [
            'Commerce'              => ['icone' => 'fa-tag',             'couleur' => '#3b82f6'],
            'E-commerce'            => ['icone' => 'fa-cart-shopping',   'couleur' => '#6366f1'],
            'Production'            => ['icone' => 'fa-industry',        'couleur' => '#8b5cf6'],
            'Agriculture-Élevage'   => ['icone' => 'fa-seedling',        'couleur' => '#84cc16'],
            'Services'              => ['icone' => 'fa-handshake',       'couleur' => '#10b981'],
            'Restauration'          => ['icone' => 'fa-utensils',        'couleur' => '#ef4444'],
            'BTP-Travaux'           => ['icone' => 'fa-helmet-safety',   'couleur' => '#f97316'],
            'Location'              => ['icone' => 'fa-key',             'couleur' => '#f59e0b'],
            'Éducation'             => ['icone' => 'fa-graduation-cap',  'couleur' => '#0ea5e9'],
            'Professions libérales' => ['icone' => 'fa-briefcase',       'couleur' => '#14b8a6'],
            'Santé'                 => ['icone' => 'fa-stethoscope',     'couleur' => '#ec4899'],
            'ONG-Associations'      => ['icone' => 'fa-hand-holding-heart', 'couleur' => '#a855f7'],
        ];

        $secteurs = [];
        foreach (\App\Modules\Admin\Modeles\Referentiel\Categorie::domaines() as $nom) {
            $secteurs[$nom] = $apparences[$nom]
                ?? ['icone' => 'fa-circle-dot', 'couleur' => '#94a3b8'];
        }

        return $secteurs;
    }

    /** Chemin du fichier de config JSON */
    private function cheminConfig(): string
    {
        return storage_path('app/secteurs_modules.json');
    }

    /** Charger la configuration courante */
    private function chargerConfig(): array
    {
        $chemin = $this->cheminConfig();
        if (!file_exists($chemin)) {
            // Config par défaut : tous les modules actifs pour tous les secteurs
            $tousModules = array_keys(self::tousLesModules());
            $defaut = [];
            foreach (array_keys(self::tousLesSecteurs()) as $secteur) {
                $defaut[$secteur] = $tousModules;
            }
            $defaut['__defaut__'] = $tousModules; // modules activés pour une entreprise sans secteur
            return $defaut;
        }
        return json_decode(file_get_contents($chemin), true) ?? [];
    }

    public function secteursModules(): View
    {
        $modules = self::tousLesModules();
        $secteurs = self::tousLesSecteurs();
        $config = $this->chargerConfig();

        return view('admin::superadmin.secteurs_modules.index', compact('modules', 'secteurs', 'config'));
    }

    public function sauvegarderSecteursModules(Request $request): RedirectResponse
    {
        $tousModules = array_keys(self::tousLesModules());
        $tousSecteurs = array_merge(['__defaut__'], array_keys(self::tousLesSecteurs()));

        $config = [];
        foreach ($tousSecteurs as $secteur) {
            $cle = str_replace(['/', ' '], '_', $secteur);
            $modulesCoches = $request->input('modules_' . $cle, []);
            // Filtrer pour ne garder que des modules valides
            $config[$secteur] = array_values(array_intersect($modulesCoches, $tousModules));
        }

        file_put_contents($this->cheminConfig(), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return redirect()->route('superadmin.secteurs_modules.index')->with('succes', 'Configuration Secteurs ↔ Modules sauvegardée avec succès.');
    }
}
