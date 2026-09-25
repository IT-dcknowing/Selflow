<?php

namespace App\Modules\Admin\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Validation\Rule;

class EntrepriseControleur
{
    use JournaliseActions;
    /**
     * Afficher la page des paramètres de l'entreprise.
     */
    public function parametres(): View
    {
        $entreprise = Auth::user()->entreprise;
        $periodes = \App\Modules\Admin\Modeles\Periode::where('entreprise_id', $entreprise->id)
            ->orderBy('date_debut', 'desc')
            ->get();

        // Statut FNE uniquement — jamais la clé elle-même (voir SuperadminFneControleur
        // pour la gestion des clés, réservée au superadmin).
        $fneCredential = $entreprise->fneCredential;
        $fneStatut = [
            'statut'       => $fneCredential?->statut ?? 'non_configure',
            'label'        => $fneCredential?->statutLabel() ?? 'Non connecté',
            'derniere_verification' => $fneCredential?->derniere_verification_at,
            'derniere_verification_resultat' => $fneCredential?->derniere_verification_resultat,
        ];

        // Ce que le parcours de configuration a réellement retenu. L'écran
        // l'affiche en lecture seule : les secteurs se cochaient ici, dans une
        // liste qui ne parlait pas au parcours, et les deux réponses pouvaient
        // se contredire sans que rien ne le signale.
        //
        // La réconciliation vient d'abord : un module fermé qui porte des
        // données est rouvert avant que l'écran ne dise ce qui est ouvert.
        // Sans cela, le panneau annonçait « Stock porte vos données » juste
        // au-dessus d'une liste de modules ouverts où Stock ne figurait pas.
        $rouverts = \App\Modules\Admin\Services\VerrouConfigurationService::reconcilier($entreprise);

        $configuration = [
            'domaines' => \App\Modules\Admin\Services\VerrouConfigurationService::domainesSouscrits($entreprise),
            'metiers'  => $entreprise->profils()->orderBy('nom')->pluck('nom')->all(),
            'modules'  => $entreprise->fresh()->modules_actifs ?? [],
            'verrous'  => \App\Modules\Admin\Services\VerrouConfigurationService::modulesVerrouilles($entreprise),
            'rouverts' => $rouverts,
        ];

        return view('admin::entreprise.parametres', compact('entreprise', 'periodes', 'fneStatut', 'configuration'));
    }

    /**
     * Enregistrer les modifications des paramètres de l'entreprise.
     */
    public function enregistrerParametres(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        // Normaliser le NCC : suppression des espaces et mise en majuscule
        $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);

        // Mentions transmises à la FNE : un copier-coller depuis un document
        // apporte des retours à la ligne et des espaces multiples qui font
        // dépasser la limite pour rien. On les ramène à une seule ligne, ce que
        // la plateforme attend de toute façon.
        foreach (['pied_de_page_facture', 'facture_autres_mentions'] as $champ) {
            if ($request->filled($champ)) {
                $request->merge([
                    $champ => trim(preg_replace('/\s+/u', ' ', $request->input($champ))),
                ]);
            }
        }

        $request->validate([
            'nom'                    => ['required', 'string', 'max:150'],
            'gerant_nom'             => ['nullable', 'string', 'max:100'],
            'gerant_prenom'          => ['nullable', 'string', 'max:150'],
            'gerant_fonction'        => ['nullable', 'string', 'max:150'],
            'adresse'                => ['nullable', 'string', 'max:255'],
            'telephone'              => ['nullable', 'string', 'max:30'],
            'email'                  => ['nullable', 'email', 'max:150'],
            'ref_bancaire'           => ['nullable', 'string', 'max:1000'],
            'logo'                   => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'logo_fne'               => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            // `comptaflow_sync_key` était ici, en champ libre. Coller la clé
            // d'une autre entreprise ouvrait la liaison vers ses livres : le
            // secret partagé est détenu par le serveur, il ne dit pas qui
            // appelle. La clé est désormais délivrée par Comptaflow à la
            // validation du superadministrateur, et n'est plus jamais saisie.
            // Nouveaux champs d'inscription complète dégrisés
            'rccm'                   => ['nullable', 'string', 'max:100'],
            'ncc'                    => ['nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            // La liste vivait ici, écrite en dur, et n'était pas celle que
            // l'écran propose : le `<select>` offre les six régimes du modèle,
            // la règle n'en acceptait que quatre. Une entreprise au TCE ou au
            // régime des microentreprises choisissait son régime, enregistrait,
            // et se voyait refuser sans comprendre — les deux régimes que la
            // DGI cite pourtant pour l'exonération légale.
            'regime_imposition'      => ['nullable', 'string', Rule::in(Entreprise::regimesAcceptesPour($entreprise))],
            // La forme juridique ne se saisissait nulle part une fois
            // l'entreprise créée : elle n'était demandée qu'au formulaire
            // d'inscription, et la passerelle Comptaflow retombait sur « SARL »
            // pour tout le monde.
            'forme_juridique'        => ['nullable', 'string', 'max:60'],
            'centre_impots'          => ['nullable', 'string', 'max:100'],
            'compte_contribuable'    => ['nullable', 'string', 'max:100'],
            // `secteurs_activite` ne se saisit plus ici. Le domaine se choisit
            // au parcours de configuration, à sa première étape, et se déduit
            // ensuite des métiers réellement souscrits. Deux écrans posaient la
            // même question sans se parler : on pouvait cocher « Santé » dans
            // les paramètres et souscrire au métier « Boulangerie » dans le
            // parcours, et les deux réponses cohabitaient.
            // Champs DGI
            'idu'                    => ['nullable', 'string', 'max:50'],
            'reference_cadastrale'   => ['nullable', 'string', 'max:100'],
            'proprietaire_local'     => ['nullable', 'string', 'max:150'],
            'commune'                => ['nullable', 'string', 'max:100'],
            'quartier'               => ['nullable', 'string', 'max:100'],
            'sticker_solde_alerte'   => ['nullable', 'integer', 'min:1', 'max:9999'],
            'possede_compte_fne'     => ['nullable', 'in:0,1'],
            // 248 caractères : longueur maximale acceptée par la FNE pour les
            // champs `footer` et `commercialMessage`. Le texte est ramené à une
            // seule ligne juste avant (voir normaliserMentions) : un copier-
            // coller depuis un document apporte sinon des retours à la ligne et
            // des espaces multiples qui font dépasser la limite pour rien.
            'pied_de_page_facture'   => ['nullable', 'string', 'max:248'],
            'facture_autres_mentions'=> ['nullable', 'string', 'max:248'],
            // La forme des numeros de tiers. Une valeur inconnue laisserait le
            // service sans instruction devant la prochaine fiche creee.
            'numerotation_tiers'     => ['nullable', 'string', 'in:' . implode(',', array_keys(\App\Modules\Admin\Services\NumerotationTiersService::CONVENTIONS))],
        ], [
            'pied_de_page_facture.max'    => 'Le pied de page ne peut pas dépasser 248 caractères.',
            'facture_autres_mentions.max' => 'Les autres mentions ne peuvent pas dépasser 248 caractères.',
            'ncc.size'                    => 'Le NCC doit comporter exactement 8 caractères.',
            'ncc.regex'                   => 'Le NCC doit être composé de 7 chiffres ou lettres suivis d\'une lettre.',
        ]);

        $data = $request->only([
            'nom', 'gerant_nom', 'gerant_prenom', 'gerant_fonction',
            'adresse', 'telephone', 'email', 'ref_bancaire',
            'rccm', 'ncc', 'regime_imposition', 'centre_impots', 'compte_contribuable',
            'forme_juridique',
            // Champs DGI
            'idu', 'reference_cadastrale', 'proprietaire_local', 'commune', 'quartier',
            'sticker_solde_alerte', 'pied_de_page_facture', 'facture_autres_mentions',
        ]);

        // `secteur_activite` n'est **pas** touché ici, et la ligne qui l'écrivait
        // a été retirée plutôt que neutralisée : elle valait `$request->
        // secteurs_activite ?? []`, donc un formulaire d'où le champ a disparu
        // aurait vidé la colonne à chaque enregistrement — et une entreprise
        // sans secteur retombe en « inscription incomplète », bannière comprise.
        //
        // C'est le parcours de configuration qui l'aligne, sur les métiers
        // souscrits (voir `VerrouConfigurationService::alignerLeSecteur`).


        // `timbre_quittance` n'est plus lu ici, et ne doit pas l'être : c'est
        // un réglage de la plateforme FNE, et la configuration de la
        // plateforme appartient au superadministrateur, comme les clés.
        //
        // Ce n'était pas une case informative. Elle décide si le droit de
        // timbre est réclamé au client — donc du net à payer imprimé sur la
        // facture et du montant débité en caisse. La cocher à tort faisait
        // payer au client un droit que la DGI ne retenait pas.

        // Trois etats : la question peut n'avoir pas encore ete posee.
        if ($request->filled('possede_compte_fne')) {
            $data['possede_compte_fne'] = $request->input('possede_compte_fne') === '1';
        }
        // `bapa` ne se regle plus ici : la case ne commandait rien, et
        // l'ouverture du bordereau se fait sur l'espace FNE de l'entreprise.
        // La colonne reste en base, inchangee, pour ne rien perdre.

        // Quand certifier : des l'emission, ou a la main apres verification.
        // Une seule case commande les deux colonnes : le recu emprunte la
        // porte de la facture, et les regler separement laissait croire a deux
        // decisions la ou il n'y en a qu'une.
        $auto = $request->boolean('normalisation_auto');
        $data['normalisation_auto_factures'] = $auto;
        $data['normalisation_auto_recus']    = $auto;

        // La convention de numerotation des tiers. Absente du formulaire, elle
        // ne change pas : les fiches deja creees gardent leur numero, et rien
        // ne justifie de basculer la suivante sans qu'on l'ait demande.
        if ($request->filled('numerotation_tiers')) {
            $data['numerotation_tiers'] = $request->input('numerotation_tiers');
        }

        // Le statut de la liaison Comptaflow se déduisait de la présence du
        // champ dans la requête : enregistrer les paramètres sans y toucher
        // faisait donc passer une liaison active à `inactive`, et les
        // écritures cessaient de partir sans que rien ne le dise. Le statut
        // n'appartient plus à ce formulaire — il appartient à
        // `LiaisonComptaflowService`.

        // Traitement du logo principal
        if ($request->hasFile('logo')) {
            // Supprimer l'ancien logo s'il existe
            if ($entreprise->logo_path && Storage::disk('public')->exists($entreprise->logo_path)) {
                Storage::disk('public')->delete($entreprise->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos/entreprises', 'public');
        }

        // Traitement du logo FNE / secondaire
        if ($request->hasFile('logo_fne')) {
            if ($entreprise->logo_fne_path && Storage::disk('public')->exists($entreprise->logo_fne_path)) {
                Storage::disk('public')->delete($entreprise->logo_fne_path);
            }
            $data['logo_fne_path'] = $request->file('logo_fne')->store('logos/entreprises', 'public');
        }

        $ancien = $entreprise->only(array_keys($data));
        $entreprise->update($data);
        $this->journaliser('modification_parametres', 'Entreprise', $entreprise->id, $ancien, $data);

        // L'enregistrement des paramètres ouvrait la liaison Comptaflow dès
        // que la clé changeait. C'est ce chemin qui permettait de se lier aux
        // livres d'une autre entreprise en collant sa clé. La liaison s'ouvre
        // maintenant par `LiaisonComptaflowService::valider()`, appelé par le
        // seul superadministrateur.

        return back()->with('succes', 'Paramètres de l\'entreprise mis à jour avec succès.');
    }

    /**
     * Changer de période active.
     */
    public function switchPeriode(Request $request): RedirectResponse
    {
        $request->validate([
            'periode_id' => ['required', 'integer', Appartenance::a('periodes', 'id')],
        ]);

        $entreprise = Auth::user()->entreprise;
        $periode = \App\Modules\Admin\Modeles\Periode::where('entreprise_id', $entreprise->id)
            ->findOrFail($request->periode_id);

        session([
            'active_periode_id'    => $periode->id,
            'active_periode_nom'   => $periode->nom,
            'active_periode_debut' => $periode->date_debut instanceof \Carbon\Carbon ? $periode->date_debut->toDateString() : (is_string($periode->date_debut) ? $periode->date_debut : \Carbon\Carbon::parse($periode->date_debut)->toDateString()),
            'active_periode_fin'   => $periode->date_fin instanceof \Carbon\Carbon ? $periode->date_fin->toDateString() : (is_string($periode->date_fin) ? $periode->date_fin : \Carbon\Carbon::parse($periode->date_fin)->toDateString()),
        ]);

        return back()->with('succes', "Exercice basculé sur {$periode->nom}.");
    }

    /**
     * Créer manuellement une période.
     */
    public function creerPeriode(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'date_debut' => ['required', 'date'],
            'date_fin'   => ['required', 'date', 'after_or_equal:date_debut'],
        ], [
            'date_debut.required' => 'La date de début est requise.',
            'date_fin.required'   => 'La date de fin est requise.',
            'date_fin.after_or_equal' => 'La date de fin doit être après ou égale à la date de début.',
        ]);

        $year = date('Y', strtotime($request->date_debut));
        $nom = "Exercice " . $year;

        // Si c'est déjà utilisé, on peut l'appeler Période Année ou Exercice Année
        // Par exemple: Exercice 2026
        // Créer la période
        $period = \App\Modules\Admin\Modeles\Periode::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => $nom,
            'date_debut'    => $request->date_debut,
            'date_fin'      => $request->date_fin,
            'est_active'    => false,
        ]);
        $this->journaliser('creation_exercice', 'Periode', $period->id, null, $period->toArray());
 
        return back()->with('succes', "La période « {$nom} » a été créée avec succès.");
    }

    /**
     * Clôturer un exercice comptable (période).
     */
    public function cloturerPeriode(\App\Modules\Admin\Modeles\Periode $periode): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($periode->entreprise_id === $entreprise->id, 404);

        // Si la période à clôturer est la période active en session, on la retire
        if (session('active_periode_id') == $periode->id) {
            session()->forget([
                'active_periode_id',
                'active_periode_nom',
                'active_periode_debut',
                'active_periode_fin',
            ]);
        }

        $ancien = $periode->toArray();
        $periode->update([
            'est_cloture' => true,
            'est_active'  => false,
        ]);

        $this->journaliser('cloture_exercice', 'Periode', $periode->id, $ancien, $periode->toArray());

        return back()->with('succes', "L'exercice « {$periode->nom} » a été clôturé définitivement.");
    }

    /**
     * Demander un dossier comptable Comptaflow.
     *
     * Un bouton, aucun champ. La clé est délivrée par Comptaflow quand le
     * superadministrateur valide, et l'entreprise ne la voit jamais.
     *
     * Cette méthode remplace `simulerSyncComptaflow()`, qui annonçait
     * « Synchronisation bidirectionnelle réussie ! Les écritures comptables et
     * les statuts des factures ont été synchronisés » sans qu'aucun appel ne
     * parte — et écrivait au passage `comptaflow_sync_status = 'Actif'`, une
     * valeur qu'aucun autre code ne reconnaît : le déversement, qui attend
     * `active`, s'arrêtait donc net après cette « réussite ».
     */
    public function demanderComptaflow(): RedirectResponse
    {
        $utilisateur = Auth::user();
        $entreprise  = $utilisateur->entreprise;

        $resultat = \App\Modules\Admin\Services\LiaisonComptaflowService::demander($entreprise, $utilisateur);

        return $resultat['success']
            ? back()->with('succes', $resultat['message'])
            : back()->withErrors(['comptaflow' => $resultat['message']]);
    }

    /**
     * Déverse le référentiel de l'entreprise dans COMPTAFLOW.
     *
     * L'écran parlait de « synchronisation », et l'appel tirait effectivement
     * les données de Comptaflow pour écraser celles de Selflow. Le sens est
     * rétabli : l'entreprise verse son plan comptable, ses journaux et ses
     * tiers chez son comptable, comme elle y verserait un fichier d'import.
     */
    public function synchroniserComptaflow(Request $request): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $result = \App\Modules\Admin\Services\DeversementReferentielService::deverser($entreprise);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'last_sync' => now()->format('d/m/Y \à H:i:s'),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ]);
    }

    /**
     * Vérifie la joignabilité de l'API FNE avec la clé active de l'entreprise
     * (test ou réelle selon le statut). Ne révèle JAMAIS la clé — la lecture
     * de la clé pour l'appel se fait côté serveur uniquement.
     *
     * NB : ceci est un contrôle de JOIGNABILITÉ réseau conservateur (le
     * serveur DGI répond-il ?), pas une validation complète d'authentification
     * — on évite volontairement de soumettre une facture de test réelle ici
     * pour ne jamais risquer d'émettre un document fiscal par erreur en
     * environnement de production. La validation complète de la clé se fait
     * naturellement via la normalisation d'une vraie facture de test.
     */
    public function testerConnexionFne(): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $cred = $entreprise->fneCredential;

        if (!$cred || !$cred->estConfiguree()) {
            return response()->json(['success' => false, 'message' => "Aucune clé FNE n'est configurée pour votre entreprise. Contactez votre administrateur Selflow."]);
        }

        $urlSandbox = config('selflow.fne_api_url_sandbox', 'https://fne-sandbox.dgi.gouv.ci');
        $urlProd    = config('selflow.fne_api_url_production', 'https://fne.dgi.gouv.ci');
        $url = $cred->statut === 'validee' ? $urlProd : $urlSandbox;

        $resultat = 'echec';
        $message = "❌ Impossible de joindre le serveur DGI ({$url}).";

        try {
            $reponse = Http::timeout(6)->get($url);
            // Peu importe le code retourné (401/403 inclus) : si on obtient une
            // réponse HTTP, le serveur est joignable — c'est tout ce qu'on teste ici.
            $resultat = 'succes';
            $message = "✅ Serveur DGI joignable (" . ($cred->statut === 'validee' ? 'production' : 'sandbox') . ").";
        } catch (\Throwable $e) {
            Log::warning("[FNE] Test de connexion échoué pour l'entreprise #{$entreprise->id} : " . $e->getMessage());
        }

        $cred->update([
            'derniere_verification_resultat' => $resultat,
            'derniere_verification_at' => now(),
        ]);

        return response()->json(['success' => $resultat === 'succes', 'message' => $message]);
    }

    /**
     * Enregistrer rapidement le nom de l'entreprise (onboarding Google).
     */
    public function enregistrerNomOnboarding(Request $request): RedirectResponse
    {
        $request->validate([
            'nom_entreprise' => ['required', 'string', 'max:150'],
        ], [
            'nom_entreprise.required' => 'Le nom de votre entreprise est obligatoire.',
        ]);

        $user = Auth::user();
        if ($user && $user->entreprise) {
            $user->entreprise->update([
                'nom' => trim($request->nom_entreprise)
            ]);
            
            // Log de l'action
            $this->journaliser('onboarding_entreprise_nom', 'Entreprise', $user->entreprise->id, null, [
                'nouveau_nom'   => trim($request->nom_entreprise)
            ]);


            return redirect()->back()->with('succes', 'Le nom de votre entreprise a été enregistré avec succès !');
        }

        return redirect()->back()->withErrors(['nom_entreprise' => 'Action impossible.']);
    }
}

