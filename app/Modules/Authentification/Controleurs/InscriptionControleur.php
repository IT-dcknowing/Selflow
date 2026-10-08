<?php

namespace App\Modules\Authentification\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Modules\Admin\Services\AccesFneService;
use App\Modules\Admin\Services\TrousseauEntrepriseService;

class InscriptionControleur
{
    /** Afficher le formulaire d'inscription. */
    public function afficher(): View
    {
        // La liste des domaines n'est plus transmise : l'étape qui les cochait
        // est partie, le parcours de configuration s'en charge.
        return view('authentification::inscription');
    }

    /** Traiter la soumission du formulaire d'inscription. */
    public function inscrire(Request $request): RedirectResponse
    {
        // Deux étapes seulement bloquent la création : l'entreprise a besoin
        // d'un nom, et il lui faut un responsable qui puisse se connecter.
        // Tout le reste se renseigne aussi bien une fois dans l'application —
        // un formulaire de vingt champs se remplit mal, ou pas. Rien n'est
        // oublié pour autant : `estInscriptionComplete()` le signale, et le
        // garde `inscription.complete` retient ventes et achats tant que la
        // situation fiscale manque.
        //
        // Le NCC se met en majuscules **avant** la règle. Le champ s'affiche
        // en majuscules (text-transform) : qui tapait « 2603210a » voyait
        // « 2603210A » à l'écran, et se faisait refuser au motif que ce n'était
        // pas « 7 chiffres ou lettres suivis d'une lettre ». Les paramètres le
        // faisaient déjà ; l'inscription l'avait oublié (recette du 08/10/2026).
        foreach (['ncc', 'fne_ncc'] as $champNcc) {
            if ($request->filled($champNcc)) {
                $request->merge([$champNcc => strtoupper(preg_replace('/\s+/', '', (string) $request->input($champNcc)))]);
            }
        }

        $request->validate([
            'nom_entreprise'      => ['required', 'string', 'max:150'],
            // La liste vivait ici, et n'était pas celle des autres écrans :
            // elle omettait TCE et RME. Voir Entreprise::REGIMES_IMPOSITION.
            'regime_imposition'   => ['nullable', 'string', Rule::in(Entreprise::regimesAcceptesPour())],
            'nom'                 => ['required', 'string', 'max:100'],
            'prenom'              => ['required', 'string', 'max:100'],
            'email'               => ['required', 'string', 'email', 'max:191', 'unique:utilisateurs,email'],
            'password'            => ['required', 'string', 'min:8', 'confirmed'],
            'conditions'          => ['accepted'],
            // Optionnels
            'adresse'             => ['nullable', 'string', 'max:255'],
            'telephone'           => ['nullable', 'string', 'max:30'],
            'rccm'                => ['nullable', 'string', 'max:100'],
            'compte_contribuable' => ['nullable', 'string', 'max:100'],
            // La forme juridique a quitté le formulaire : on demandait quatre
            // choses pour créer une entreprise dont une seule est
            // indispensable. Elle se renseigne depuis les paramètres, avec le
            // reste de l'identité légale.
            'centre_impots'       => ['nullable', 'string', 'max:100'],
            'ncc'                 => ['nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],

            // ── L'étape « facture normalisée » ──
            //
            // L'entreprise déclare si elle a déjà un compte FNE. Si oui, son
            // NCC et le mot de passe de son espace suffisent : tout le reste
            // est déjà chez la DGI, et le lui faire ressaisir ne ferait
            // qu'introduire des écarts. Si non, on relève ce qu'il faut pour
            // lui en ouvrir un.
            //
            // Le mot de passe est chiffré au repos et n'est jamais rendu à
            // l'écran. Voir AccesFneService.
            'possede_compte_fne'  => ['nullable', 'in:0,1'],
            'fne_ncc'             => ['nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'fne_mot_de_passe'    => ['nullable', 'string', 'max:255'],
            // Le domaine ne se coche plus ici. Il se choisit à la première
            // étape du parcours de configuration, avec les métiers qui en
            // découlent, leurs rayons et leurs articles. Deux écrans posaient
            // la même question sans se parler : on pouvait déclarer « Santé »
            // à l'inscription et souscrire « Boulangerie » ensuite.
            'fonction_gerant'     => ['nullable', 'string', 'max:100'],
        ], [
            'nom_entreprise.required'    => 'Le nom de votre entreprise est obligatoire.',
            'regime_imposition.in'       => 'Régime invalide. Choisissez-en un dans la liste proposée.',
            'fne_ncc.size'               => 'Le NCC doit comporter exactement 8 caractères.',
            'fne_ncc.regex'              => 'Le NCC doit être composé de 7 chiffres ou lettres suivis d\'une lettre.',
            'ncc.size'                   => 'Le NCC doit comporter exactement 8 caractères.',
            'ncc.regex'                  => 'Le NCC doit être composé de 7 chiffres ou lettres suivis d\'une lettre.',
            'nom.required'               => 'Votre nom est obligatoire.',
            'prenom.required'            => 'Votre prénom est obligatoire.',
            'email.required'             => 'L\'adresse email est obligatoire.',
            'email.email'                => 'L\'adresse email n\'est pas valide.',
            'email.unique'               => 'Cette adresse email est déjà utilisée.',
            'password.required'          => 'Le mot de passe est obligatoire.',
            'password.min'               => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed'         => 'La confirmation du mot de passe ne correspond pas.',
            'conditions.accepted'        => 'Vous devez accepter les conditions d\'utilisation.',
        ]);

        DB::beginTransaction();
        try {
            // 1. Créer l'entreprise avec tous les champs
            $entreprise = Entreprise::create([
                'nom'                 => trim($request->nom_entreprise),
                'email'               => trim($request->email),
                'telephone'           => trim($request->telephone ?? ''),
                'adresse'             => trim($request->adresse ?? ''),
                'rccm'                => trim($request->rccm ?? ''),
                'compte_contribuable' => trim($request->compte_contribuable ?? ''),
                'regime_imposition'   => $request->regime_imposition,
                'ncc'                 => $request->filled('fne_ncc')
                    ? strtoupper(trim($request->fne_ncc))
                    : ($request->filled('ncc') ? strtoupper(trim($request->ncc)) : null),
                'centre_impots'       => trim($request->centre_impots ?? ''),
                // `secteur_activite` n'est plus écrit ici : la colonne se
                // remplit au parcours, dès le domaine choisi. Y poser un
                // tableau vide serait sans effet aujourd'hui, mais la ligne
                // survivrait au prochain écran qui la renseignerait avant.
                //
                // Tant qu'elle est vide, l'entreprise est « inscription
                // incomplète » et la bannière renvoie au parcours.
                // Trois états : la question peut n'avoir pas encore été posée,
                // l'étape étant facultative.
                'possede_compte_fne'  => $request->filled('possede_compte_fne')
                    ? $request->input('possede_compte_fne') === '1'
                    : null,
                'gerant_nom'          => trim($request->nom),
                'gerant_prenom'       => trim($request->prenom),
                'gerant_fonction'     => trim($request->fonction_gerant ?? ''),
                'modules_actifs'      => ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'],
                'statut'              => 'actif',
                'quota_points_de_vente' => 5,
                'plan_abonnement'     => 'Starter',
            ]);

            // Sans plan comptable ni journal, la premiere vente s'impute sur des
            // comptes inventes a la volee. L'entreprise recoit donc de quoi
            // travailler des le premier jour ; ce qui ne lui sert pas, elle
            // l'archivera.
            TrousseauEntrepriseService::doter($entreprise);

            // L'acces a l'espace FNE, si l'entreprise en a deja un. Chiffre au
            // repos, jamais rendu a l'ecran, efface une fois le parametrage
            // releve : c'est un acces a un service de l'Etat, pas un reglage.
            AccesFneService::enregistrer($entreprise, $request->fne_ncc, $request->fne_mot_de_passe);

            // 2. Créer l'utilisateur admin principal
            $utilisateur = Utilisateur::create([
                'entreprise_id'         => $entreprise->id,
                'nom'                   => trim($request->nom),
                'prenom'                => trim($request->prenom),
                'email'                 => trim($request->email),
                'password'              => Hash::make($request->password),
                'role'                  => 'admin',
                'fonction'              => trim($request->fonction_gerant ?? ''),
                'statut'                => 'actif',
                'doit_changer_password' => false,
            ]);

            DB::commit();

            // 3. Connecter directement
            Auth::login($utilisateur);
            $request->session()->regenerate();

            return redirect()->route('admin.tableau_de_bord')
                ->with('succes', 'Bienvenue sur Selflow ! Votre espace est prêt. Commencez par configurer votre entreprise.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()
                ->withErrors(['inscription_erreur' => 'Erreur lors de la création : ' . $e->getMessage()])
                ->withInput($request->except('password', 'password_confirmation'));
        }
    }
}
