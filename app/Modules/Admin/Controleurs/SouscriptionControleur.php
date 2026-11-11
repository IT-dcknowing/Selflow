<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Admin\Modeles\Referentiel\Famille;
use App\Modules\Admin\Modeles\Referentiel\Profil;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Services\SouscriptionProfilService;
use App\Modules\Admin\Services\VerrouConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use App\Modules\Admin\Regles\Quantite;

/**
 * Le parcours de configuration d'une entreprise.
 *
 * Il se déplie : chaque étape n'apparaît qu'une fois la précédente validée, et
 * se recharge si l'utilisateur revient en arrière. Il se quitte et se reprend —
 * l'étape atteinte est retenue sur l'entreprise, pas dans la session, pour
 * qu'un changement de poste ou de navigateur ne fasse pas tout recommencer.
 *
 * Le superadministrateur peut le dérouler pour une entreprise qu'il vient de
 * créer (15/10/2026) : mêmes étapes, mêmes règles, de A à Z — seules les
 * adresses changent (`superadmin.entreprises.parcours`).
 *
 *   1. Catégorie      quel grand domaine
 *   2. Profils        le métier réel, plusieurs possibles, ou « Autre »
 *   3. Modules        ce que les profils ouvrent, décochable
 *   4. Familles       les rayons à retenir, tous cochés au départ
 *   5. Prix et stock  les seules colonnes que le référentiel laisse vides
 */
class SouscriptionControleur
{
    /** Nombre d'étapes du parcours. */
    private const DERNIERE_ETAPE = 5;

    public function index(Request $request): View
    {
        $entreprise = $this->entreprise();

        // Un module fermé qui porte des données est rouvert avant d'afficher
        // quoi que ce soit : le verrou empêche d'en refermer un, il ne répare
        // pas ceux qui l'ont été avant qu'il existe.
        VerrouConfigurationService::reconcilier($entreprise);

        $etape = $this->etapeDemandee($request, $entreprise->refresh());

        return view('admin::souscription.parcours', [
            'entreprise' => $entreprise,
            'routeEtape' => fn (int $numero) => $this->adresseDeLEtape($numero, $entreprise),
            'routeEnregistrer' => $this->pourLeSuperadmin()
                ? route('superadmin.entreprises.parcours.enregistrer', [$entreprise, $etape])
                : route('admin.souscription.enregistrer', $etape),
            'routeQuitter' => $this->pourLeSuperadmin()
                ? route('superadmin.entreprises')
                : route('admin.entreprise.parametres'),
            'etape'      => $etape,
            'derniere'   => self::DERNIERE_ETAPE,
            'choix'      => $this->choix($entreprise),
        ] + $this->donneesDeLEtape($etape, $entreprise));
    }

    /**
     * Enregistrer une étape et passer à la suivante.
     */
    public function enregistrer(Request $request, int $etape): RedirectResponse
    {
        $entreprise = $this->entreprise();

        // On ne peut pas valider une étape que l'on n'a pas atteinte : sans ce
        // contrôle, un formulaire forgé sauterait le choix des profils et
        // souscrirait à des familles qui n'appartiennent à aucun d'eux.
        if ($etape > $entreprise->souscription_etape + 1 || $etape < 1 || $etape > self::DERNIERE_ETAPE) {
            return redirect()->to($this->adresseDeLEtape(null, $entreprise))
                ->with('erreur', 'Cette étape n\'est pas encore accessible.');
        }

        $suite = match ($etape) {
            1 => $this->validerCategorie($request, $entreprise),
            2 => $this->validerProfils($request, $entreprise),
            3 => $this->validerModules($request, $entreprise),
            4 => $this->validerFamilles($request, $entreprise),
            5 => $this->validerPrix($request, $entreprise),
        };

        return $suite;
    }

    // ─────────────────────────────────────────────────────────────────
    // LES ÉTAPES
    // ─────────────────────────────────────────────────────────────────

    private function validerCategorie(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'categorie_id' => ['nullable', 'integer', 'exists:referentiel_categories,id', 'required_without_all:domaine_personnalise,nouveau_domaine'],
            'domaine_personnalise' => ['nullable', 'string', 'max:120', 'required_without_all:categorie_id,nouveau_domaine'],
            'nouveau_domaine' => ['nullable', 'string', 'max:120', 'required_without_all:categorie_id,domaine_personnalise'],
        ], [
            'categorie_id.required_without' => 'Choisissez un domaine ou créez le vôtre.',
            'domaine_personnalise.required_without' => 'Saisissez le nom de votre domaine.',
        ]);

        // La création d'un domaine est une action distincte : on l'enregistre,
        // on revient à la liste, et l'utilisateur le sélectionne ensuite.
        $nouveauDomaine = (string) ($donnees['nouveau_domaine'] ?? '');
        if (trim($nouveauDomaine) !== '') {
            // Comparaison volontairement stricte : seule une chaîne strictement
            // identique est refusée. Une variante réellement différente passe.
            $domaineDejaCree = in_array($nouveauDomaine, (array) $entreprise->domaines_personnalises, true);
            $nomsDomainesReferentiel = Categorie::query()->pluck('nom')->all();
            $domaineDuReferentiel = in_array($nouveauDomaine, $nomsDomainesReferentiel, true);
            if ($domaineDejaCree || $domaineDuReferentiel) {
                return back()->withInput()->withErrors([
                    'nouveau_domaine' => 'Ce domaine existe déjà à l’identique. Sélectionnez-le dans la liste.',
                ]);
            }
            $personnalises = array_values(array_merge(
                (array) $entreprise->domaines_personnalises, [$nouveauDomaine]
            ));
            $entreprise->update(['domaines_personnalises' => $personnalises]);
            return redirect()->to($this->adresseDeLEtape(1, $entreprise))
                ->with('succes', 'Domaine créé. Sélectionnez-le pour continuer.');
        }

        // Les domaines du référentiel et les domaines personnalisés sont
        // deux groupes de radios HTML distincts. Si le navigateur transmet
        // les deux, le choix du référentiel explicitement envoyé est prioritaire ;
        // un ancien domaine personnalisé ne doit jamais prendre le dessus.
        $nouvelleCategorieId = !empty($donnees['categorie_id'])
            ? (int) $donnees['categorie_id']
            : null;
        $domaine = $nouvelleCategorieId
            ? ''
            : trim((string) ($donnees['domaine_personnalise'] ?? ''));
        $ancienChoix = $this->choix($entreprise);
        $domaineChange = ($ancienChoix['categorie_id'] ?? null) != $nouvelleCategorieId
            || ($ancienChoix['domaine_personnalise'] ?? null) !== ($domaine ?: null);

        // Conserver chaque domaine choisi en base, même sans profil standard.
        $nomDomaineSelectionne = $nouvelleCategorieId
            ? Categorie::whereKey($nouvelleCategorieId)->value('nom')
            : ($domaine ?: null);
        if ($nomDomaineSelectionne) {
            $entreprise->update([
                // Le domaine actif est placé en dernier : le parcours
                // retrouve ainsi le bon contexte après expiration de session,
                // même lorsqu'on revient vers un domaine déjà enregistré.
                'domaines_souscrits' => array_values(array_merge(
                    array_filter(
                        (array) $entreprise->domaines_souscrits,
                        fn ($domaineEnregistre) => $domaineEnregistre !== $nomDomaineSelectionne
                    ),
                    [$nomDomaineSelectionne]
                )),
            ]);
        }

        $valeurs = [
            'categorie_id' => $nouvelleCategorieId,
            'domaine_personnalise' => $domaine ?: null,
        ];
        if ($domaineChange) {
            // Un changement de domaine démarre une sélection métier neuve.
            // Effacer explicitement la sélection précédente évite que le
            // secteur radio reste coché et que les anciens profils soient repris.
            // Affectation avec remplacement : l'union PHP (+=) conserve
            // les anciennes clés déjà présentes et laissait donc l'ancien
            // secteur sélectionné dans la session après changement de domaine.
            $valeurs = array_merge($valeurs, [
                'profils' => [],
                'familles' => [],
                'modules' => [],
                'secteurs_personnalises_selection' => [],
                'secteur_domaine_selectionne' => $nouvelleCategorieId
                    ? 'referentiel:' . $nouvelleCategorieId
                    : 'personnalise:' . ($domaine ?: ''),
            ]);
        }
        $this->memoriser($entreprise, $valeurs);

        // Le domaine choisi vaut secteur d'activité dès maintenant, sans
        // attendre la souscription de l'étape 4. Sans cela, une entreprise
        // neuve reste « inscription incomplète » — bannière comprise — alors
        // qu'elle vient de répondre à la question, et l'ancien écran où elle
        // cochait son secteur n'existe plus pour la débloquer.
        //
        // L'étape 4 réalignera la colonne sur les métiers réellement souscrits.
        if (empty($entreprise->secteur_activite) && !empty($donnees['categorie_id'])) {
            $nom = !empty($donnees['categorie_id']) ? Categorie::find($donnees['categorie_id'])?->nom : $domaine;

            if ($nom) {
                $entreprise->update(['secteur_activite' => [$nom]]);
            }
        }

        return $this->avancerVers(2, $entreprise);
    }

    private function validerProfils(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $choixCourant = $this->choix($entreprise);
        $categorieCouranteId = isset($choixCourant['categorie_id']) ? (int) $choixCourant['categorie_id'] : null;
        $acquis = VerrouConfigurationService::profilsAcquis($entreprise, $categorieCouranteId);

        $donnees = $request->validate([
            // Exiger un métier n'a de sens que la première fois. Une entreprise
            // qui a déjà souscrit en porte un : lui redemander d'en cocher un
            // bloquerait le parcours de quelqu'un qui vient seulement le
            // traverser pour rouvrir un module ou ajouter un rayon.
            'profils'          => ['nullable', 'array'],
            'profils.*'        => ['string', 'exists:referentiel_profils,code'],
            'secteurs_personnalises' => ['nullable', 'array'],
            'secteurs_personnalises.*' => ['nullable', 'string', 'max:150'],
            'secteurs_personnalises_selection' => ['nullable', 'array'],
            'secteurs_personnalises_selection.*' => ['string', 'max:150'],
            'activite_autre'   => ['nullable', 'string', 'max:150'],
        ], [
            'profils.*.exists'         => 'Un des métiers choisis est inconnu.',
        ]);

        // Un métier déjà souscrit ne se décoche pas. Ce n'est pas un verrou de
        // principe : `souscrire()` n'a jamais retiré un profil, et décocher
        // n'enlevait donc rien — l'utilisateur croyait avoir fermé un métier
        // qui restait ouvert, avec ses rayons et ses articles. Le remettre
        // dans le choix retenu accorde l'écran et la base.
        $retenus = array_values(array_unique(array_merge($donnees['profils'] ?? [], $acquis)));

        $secteursSaisis = array_values(array_filter(
            array_map(static fn ($nom) => (string) $nom, $donnees['secteurs_personnalises'] ?? []),
            static fn ($nom) => trim($nom) !== ''
        ));
        // Refuser uniquement les doublons strictement identiques, dans la liste
        // existante comme dans les nouvelles lignes du formulaire.
        $choixCourant = $this->choix($entreprise);
        $cleDomaine = !empty($choixCourant['categorie_id'])
            ? 'referentiel:' . $choixCourant['categorie_id']
            : 'personnalise:' . (string) ($choixCourant['domaine_personnalise'] ?? '');
        $secteursParDomaine = (array) $entreprise->secteurs_personnalises_par_domaine;
        $secteursDejaEnregistres = (array) ($secteursParDomaine[$cleDomaine] ?? []);
        $doublonSecteur = count($secteursSaisis) !== count(array_unique($secteursSaisis, SORT_STRING));
        foreach ($secteursSaisis as $secteur) {
            if (in_array($secteur, $secteursDejaEnregistres, true)) {
                $doublonSecteur = true;
                break;
            }
        }
        if ($doublonSecteur) {
            return back()->withInput()->withErrors([
                'secteurs_personnalises' => 'Ce secteur précis existe déjà à l’identique. Modifiez son nom ou sélectionnez-le dans la liste.',
            ]);
        }
        if ($secteursSaisis !== []) {
            $secteursParDomaine[$cleDomaine] = array_merge($secteursDejaEnregistres, $secteursSaisis);
            $entreprise->update([
                'secteurs_personnalises_par_domaine' => $secteursParDomaine,
                'secteurs_personnalises' => array_values(array_unique(array_merge(
                    (array) $entreprise->secteurs_personnalises, $secteursSaisis
                ))),
            ]);
        }

        // Créer un secteur ne fait pas avancer le parcours : il revient dans
        // la liste des cartes et l'utilisateur le choisit ensuite.
        if ($request->input('action_secteur') === 'creer') {
            return redirect()->to($this->adresseDeLEtape(2, $entreprise))
                ->with('succes', 'Secteur(s) créé(s). Cochez un ou plusieurs secteurs pour continuer.');
        }

        $secteurSelectionne = array_values(array_unique($donnees['secteurs_personnalises_selection'] ?? []));
        $choixPourDomaine = $this->choix($entreprise);
        $cleDomaineSelectionne = !empty($choixPourDomaine['categorie_id'])
            ? 'referentiel:' . $choixPourDomaine['categorie_id']
            : 'personnalise:' . (string) ($choixPourDomaine['domaine_personnalise'] ?? '');
        $metiersSelectionnes = array_values(array_unique(array_merge(
            Profil::whereIn('code', $retenus)
                ->when($categorieCouranteId, fn ($q) => $q->where('categorie_id', $categorieCouranteId))
                ->orderBy('nom')->pluck('nom')->all(),
            $secteurSelectionne
        )));

        $selectionsParDomaine = (array) $entreprise->secteurs_selectionnes_par_domaine;
        $selectionsParDomaine[$cleDomaineSelectionne] = $metiersSelectionnes;
        $entreprise->update(['secteurs_selectionnes_par_domaine' => $selectionsParDomaine]);

        $this->memoriser($entreprise, [
            'profils' => $retenus,
            'secteurs_personnalises_selection' => $secteurSelectionne,
            'secteur_domaine_selectionne' => $cleDomaineSelectionne,
        ]);

        // Le référentiel ne couvre pas tous les métiers. Plutôt que de forcer
        // l'utilisateur dans une case, on retient ce qu'il dit — et cela
        // alimentera les versions suivantes du classeur.
        if (array_key_exists('activite_autre', $donnees) && filled($donnees['activite_autre'])) {
            $entreprise->update(['activite_autre' => trim($donnees['activite_autre'])]);
        }

        return $this->avancerVers(3, $entreprise);
    }

    private function validerModules(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([]);

        // Un module qui n'est pas autorisé ne peut pas s'activer, même envoyé
        // par un formulaire : les droits appartiennent au superadmin.
        //
        // Les modules structurels rejoignent le choix quoi qu'il arrive. Leurs
        // cases sont désactivées à l'écran, et une case désactivée **n'est pas
        // transmise** : sans ce rattrapage, valider l'étape les retirerait.
        //
        // Les modules qui portent des données rejoignent le choix de la même
        // façon : c'est la seule étape du parcours qui **défait** quelque
        // chose, et refermer « Comptabilité » sur six mois d'écritures les
        // ferait disparaître de la barre latérale sans un mot d'avertissement.
        $retenus = array_values(array_intersect(
            array_unique(array_merge(
                $this->modulesProposes($this->choix($entreprise)['profils'] ?? []),
                Entreprise::MODULES_STRUCTURELS,
                array_keys(VerrouConfigurationService::modulesVerrouilles($entreprise)),
                (array) $entreprise->modules_actifs
            )),
            $entreprise->modulesAutorises()
        ));

        $this->memoriser($entreprise, ['modules' => $retenus]);
        // Les modules sont déterminés par les choix métier, jamais par une
        // sélection utilisateur sur cette étape désormais informative.
        $entreprise->update(['modules_actifs' => $retenus]);

        return $this->avancerVers(4, $entreprise);
    }

    private function validerFamilles(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'familles' => ['nullable', 'array'],
            'familles.*' => ['string', 'max:32'],
            'categories_personnalisees' => ['nullable', 'array'],
            'categories_personnalisees.*.nom' => ['nullable', 'string', 'max:120'],
            'categories_personnalisees.*.metier_configuration' => ['nullable', 'string', 'max:150'],
            'supprimer_categorie_personnalisee' => ['nullable', 'integer'],
        ]);

        // La corbeille ne peut supprimer qu'une catégorie personnalisée
        // appartenant à cette entreprise et encore inutilisée.
        if (!empty($donnees['supprimer_categorie_personnalisee'])) {
            $categorie = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                ->whereKey($donnees['supprimer_categorie_personnalisee'])
                ->first();

            if (!$categorie) {
                return back()->with('erreur', 'Cette catégorie est introuvable.');
            }

            if ($categorie->produits()->exists() || $categorie->sousCategories()->exists()) {
                return back()->with('erreur', 'Cette catégorie contient déjà des produits ou des sous-catégories ; elle ne peut pas être supprimée ici.');
            }

            $categorie->delete();

            return redirect()->to($this->adresseDeLEtape(4, $entreprise))
                ->with('succes', 'Catégorie supprimée.');
        }

        $choix = $this->choix($entreprise);
        $retenues = $donnees['familles'] ?? [];
        $avant = now()->subSecond();
        // Un domaine/secteur personnalisé n'hérite pas du catalogue standard.
        // Le référentiel n'est copié que si l'utilisateur a réellement choisi
        // des profils et des familles proposés.
        $bilan = ['familles' => 0, 'articles' => 0, 'modules' => []];
        if (!empty($choix['profils'])) {
            $bilan = SouscriptionProfilService::souscrire($entreprise, $choix['profils'], $retenues);
        }

        // Les catégories créées restent rattachées au domaine et au métier
        // qui les ont fait naître.
        $domaineCourant = !empty($choix['categorie_id'])
            ? Categorie::whereKey($choix['categorie_id'])->value('nom')
            : ($choix['domaine_personnalise'] ?? null);
        $metiersCourants = array_values(array_unique(array_merge(
            Profil::whereIn('code', $choix['profils'] ?? [])->orderBy('nom')->pluck('nom')->all(),
            (array) ($choix['secteurs_personnalises_selection'] ?? [])
        )));

        // Une catégorie personnalisée appartient à un seul domaine et à un seul secteur.
        foreach ($donnees['categories_personnalisees'] ?? [] as $ligne) {
            $nom = trim((string) ($ligne['nom'] ?? ''));
            if ($nom === '') continue;

            $metierCategorie = trim((string) ($ligne['metier_configuration'] ?? ''));
            if ($domaineCourant === null || $metierCategorie === ''
                || !in_array($metierCategorie, $metiersCourants, true)) {
                return back()->withInput()->withErrors([
                    'categories_personnalisees' => 'Le secteur de cette catégorie ne correspond pas aux secteurs sélectionnés.',
                ]);
            }

            $prefixeBase = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nom) ?: 'CAT', 0, 8));
            $prefixe = $prefixeBase;
            $suffixe = 2;
            while (\App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                ->where('prefixe', $prefixe)->exists()) {
                $prefixe = substr($prefixeBase, 0, 6) . $suffixe++;
            }

            \App\Modules\Admin\Modeles\Categorie::firstOrCreate(
                [
                    'entreprise_id' => $entreprise->id,
                    'nom' => $nom,
                    'domaine_configuration' => $domaineCourant,
                    'metier_configuration' => $metierCategorie,
                ],
                ['prefixe' => $prefixe]
            );
        }

        $ajoutes = Produit::where('entreprise_id', $entreprise->id)
            ->where('created_at', '>=', $avant)->pluck('id')->all();
        $dejaDansLeParcours = (array) session("souscription_articles.{$entreprise->id}", []);
        $tousLesArticlesDuParcours = array_values(array_unique(array_merge($dejaDansLeParcours, $ajoutes)));
        session(["souscription_articles.{$entreprise->id}" => $tousLesArticlesDuParcours ?: null]);

        if (isset($choix['modules']) && !empty($bilan['modules'])) {
            $entreprise->update([
                'modules_actifs' => array_values(array_intersect(
                    $bilan['modules'],
                    array_merge($choix['modules'], Entreprise::MODULES_STRUCTURELS)
                )),
            ]);
        }

        if (!empty($choix['profils'])) {
            VerrouConfigurationService::alignerLeSecteur($entreprise->refresh());
        }

        return $this->avancerVers(5, $entreprise)->with('succes', sprintf(
            '%d catégorie(s) et %d article(s) ajoutés à votre catalogue.',
            $bilan['familles'], $bilan['articles']
        ));
    }

    private function validerPrix(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $choixCourant = $this->choix($entreprise);
        $domaineCourant = !empty($choixCourant['categorie_id'])
            ? Categorie::whereKey($choixCourant['categorie_id'])->value('nom')
            : ($choixCourant['domaine_personnalise'] ?? null);
        $metiersCourants = !empty($choixCourant['secteurs_personnalises_selection'])
            ? array_values($choixCourant['secteurs_personnalises_selection'])
            : Profil::whereIn('code', $choixCourant['profils'] ?? [])->pluck('nom')->all();
        $idsCategoriesCourantes = \App\Modules\Admin\Modeles\Categorie::query()
            ->where('entreprise_id', $entreprise->id)
            ->where('domaine_configuration', $domaineCourant)
            ->whereIn('metier_configuration', $metiersCourants)
            ->pluck('id')->all();

        $donnees = $request->validate([
            'articles'                 => ['array'],
            'articles.*.id'            => ['nullable', 'integer', Appartenance::a('produits')],
            'articles.*.nouveau'       => ['nullable', 'boolean'],
            'articles.*.nom'           => ['nullable', 'string', 'max:255'],
            'articles.*.type'          => ['nullable', 'string', 'in:' . implode(',', array_keys(Produit::TYPES))],
            'articles_supprimer'       => ['nullable', 'array'],
            'articles_supprimer.*'     => ['integer', 'distinct'],
            'articles.*.categorie_id' => [
                'required', 'integer',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('entreprise_id', $entreprise->id)
                    ->whereIn('id', $idsCategoriesCourantes)),
            ],
            'articles.*.point_de_vente_id' => ['nullable', 'integer', 'exists:points_de_vente,id'],
            'articles.*.prix_achat'    => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'articles.*.prix_vente'    => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'articles.*.stock_initial' => Quantite::facultative(),
        ]);

        // À l'étape 5, l'utilisateur choisit une catégorie déjà créée à l'étape 4
        // et renseigne les prix, le point de vente et le stock d'ouverture.
        $pointDeVenteId = $this->siteDuStock($entreprise)?->id;

        $idsArticlesEnvoyes = collect($donnees['articles'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $produitsExistants = Produit::with('categorieRelation')
            ->where('entreprise_id', $entreprise->id)
            ->whereIn('categorie_id', $idsCategoriesCourantes)
            ->whereIn('id', $idsArticlesEnvoyes)
            ->get()->keyBy('id');
        $idsCreesDansCeParcours = array_filter((array) session("souscription_articles.{$entreprise->id}", []));
        foreach ($donnees['articles_supprimer'] ?? [] as $idASupprimer) {
            if (in_array((int) $idASupprimer, $idsCreesDansCeParcours, true)) {
                Produit::where('entreprise_id', $entreprise->id)->whereKey($idASupprimer)->delete();
            }
        }

        foreach ($donnees['articles'] ?? [] as $ligne) {
            $produit = !empty($ligne['id'])
                ? $produitsExistants->get((int) $ligne['id'])
                : null;

            // Une ligne ajoutée dans le tableau devient un article seulement
            // si son nom, sa catégorie et son type ont été renseignés.
            $categorie = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                ->findOrFail((int) $ligne['categorie_id']);

            if (!$produit && !empty($ligne['nouveau']) && filled($ligne['nom'] ?? null)
                && filled($ligne['type'] ?? null)) {
                $produit = Produit::create([
                    'entreprise_id' => $entreprise->id,
                    'nom' => trim($ligne['nom']),
                    'type' => $ligne['type'],
                    'categorie_id' => $categorie->id,
                    'prix_achat' => $ligne['prix_achat'] ?? 0,
                    'prix_vente' => $ligne['prix_vente'] ?? 0,
                ]);
                $produit->setRelation('categorieRelation', $categorie);
                $idsCreesDansCeParcours[] = $produit->id;
                session(["souscription_articles.{$entreprise->id}" => array_values(array_unique($idsCreesDansCeParcours))]);
            }

            if (!$produit) continue;

            // La catégorie est un choix explicite : elle ne se crée ni ne se
            // renomme à cette étape. Une catégorie absente se crée à l'étape 4.
            $produit->update(array_filter([
                'nom'          => ($ligne['nom'] ?? null) ?: null,
                'categorie_id' => $categorie->id,
                'prix_achat'   => $ligne['prix_achat'] ?? null,
                'prix_vente'   => $ligne['prix_vente'] ?? null,
            ], fn ($v) => $v !== null));

            $siteArticleId = isset($ligne['point_de_vente_id']) && $ligne['point_de_vente_id'] !== ''
                ? PointDeVente::where('entreprise_id', $entreprise->id)->whereKey($ligne['point_de_vente_id'])->value('id')
                : $pointDeVenteId;
            if ($siteArticleId && $produit->estStockable()) {
                \App\Modules\Admin\Modeles\Stock::firstOrCreate(
                    ['produit_id' => $produit->id, 'point_de_vente_id' => $siteArticleId],
                    ['quantite_disponible' => 0, 'stock_minimum' => 5, 'stock_maximum' => 100, 'reference' => true]
                );
            }
            $this->poserStockInitial($produit, $siteArticleId, $ligne['stock_initial'] ?? null);
        }

        $entreprise->update([
            'souscription_etape'       => self::DERNIERE_ETAPE,
            'souscription_terminee_le' => now(),
        ]);
        session()->forget("souscription_articles.{$entreprise->id}");

        if ($this->pourLeSuperadmin()) {
            return redirect()->route('superadmin.entreprises')
                ->with('succes', "Le parcours de « {$entreprise->nom} » est terminé : domaine, métiers, modules, rayons et articles sont en place.");
        }

        return redirect()->route('admin.tableau_de_bord')
            ->with('succes', 'Votre entreprise est configurée. Bonne vente !');
    }

    /**
     * Le site sur lequel s'inscrit le stock d'ouverture.
     *
     * Celui de la session si l'utilisateur en a choisi un et qu'il appartient
     * bien à son entreprise — un identifiant resté en session après un
     * changement de compte ne doit pas désigner le dépôt du voisin. À défaut,
     * le premier site enregistré. Aucun site, aucun stock : la colonne
     * disparaît de l'écran plutôt que d'avaler la saisie.
     *
     * Le formulaire et l'enregistrement lisent la même méthode : la colonne
     * affichée est exactement celle qui sera écrite.
     */
    private function siteDuStock(Entreprise $entreprise): ?PointDeVente
    {
        if (!$entreprise->moduleEstActif('stock')) {
            return null;
        }

        $sites = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('id');

        if ($choisi = session('point_de_vente_actif_id')) {
            $site = (clone $sites)->where('id', $choisi)->first();

            if ($site) {
                return $site;
            }
        }

        return $sites->first();
    }

    /**
     * Poser le stock d'ouverture d'un article.
     *
     * Seuls les articles qui se comptent en reçoivent un : un service n'a ni
     * quantité disponible, ni seuil, ni rupture. Créer sa fiche le ferait
     * figurer en permanence dans les alertes de stock — pour un cabinet
     * comptable, dont tous les articles sont des missions, le tableau de bord
     * n'annoncerait que des ruptures sur des choses qui ne s'épuisent pas.
     *
     * C'est un inventaire d'ouverture : la quantité constatée au jour du
     * démarrage, sur le point de vente actif.
     */
    private function poserStockInitial(Produit $produit, ?int $pointDeVenteId, $quantite): void
    {
        if (!$produit->estStockable() || !$pointDeVenteId || $quantite === null || $quantite === '') {
            return;
        }

        Stock::updateOrCreate(
            ['produit_id' => $produit->id, 'point_de_vente_id' => $pointDeVenteId],
            ['quantite_disponible' => round((float) $quantite, Stock::DECIMALES),
             'stock_minimum' => 5, 'stock_maximum' => 100]
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // OUTILS
    // ─────────────────────────────────────────────────────────────────

    private function entreprise(): Entreprise
    {
        if ($this->pourLeSuperadmin()) {
            return request()->route('entreprise');
        }

        $entreprise = Auth::user()?->entreprise;

        abort_unless($entreprise, 403, 'Vous n\'êtes rattaché à aucune entreprise.');

        return $entreprise;
    }

    /**
     * Étape à afficher : celle demandée si elle a été atteinte, sinon la
     * dernière atteinte.
     */
    private function etapeDemandee(Request $request, Entreprise $entreprise): int
    {
        $atteinte = min((int) $entreprise->souscription_etape + 1, self::DERNIERE_ETAPE);
        $demandee = (int) $request->query('etape', $atteinte);

        return max(1, min($demandee, $atteinte));
    }

    private function avancerVers(int $etape, Entreprise $entreprise): RedirectResponse
    {
        if ($etape - 1 > $entreprise->souscription_etape) {
            $entreprise->update(['souscription_etape' => $etape - 1]);
        }

        return redirect()->to($this->adresseDeLEtape($etape, $entreprise));
    }

    /** Le parcours déroulé par le superadministrateur pour une entreprise. */
    private function pourLeSuperadmin(): bool
    {
        return request()->route('entreprise') instanceof Entreprise && (bool) Auth::user()?->estSuperAdmin();
    }

    private function adresseDeLEtape(?int $etape, Entreprise $entreprise): string
    {
        $parametres = $etape ? ['etape' => $etape] : [];

        return $this->pourLeSuperadmin()
            ? route('superadmin.entreprises.parcours', [$entreprise] + $parametres)
            : route('admin.souscription.index', $parametres);
    }

    /** Le même parcours, déroulé par le superadministrateur. */
    public function indexPour(Request $request, Entreprise $entreprise): View
    {
        return $this->index($request);
    }

    public function enregistrerPour(Request $request, Entreprise $entreprise, int $etape): RedirectResponse
    {
        return $this->enregistrer($request, $etape);
    }

    /**
     * Les choix faits jusqu'ici.
     *
     * La session porte le parcours en cours ; la base porte ce que
     * l'entreprise a réellement retenu. **Les deux comptent.**
     *
     * Le parcours ne lisait que la session. Tant qu'on le suivait d'une
     * traite, cela suffisait — mais y revenir plus tard, depuis les
     * paramètres, une fois la session expirée ou après une reconnexion,
     * affichait **tout décoché** : le domaine à rechoisir, les métiers
     * oubliés, les modules apparemment fermés. L'utilisateur venu compléter
     * son paramétrage se serait retrouvé à le refaire, avec le risque de
     * valider une étape en ayant perdu ce qu'il avait coché.
     *
     * La session prime, parce qu'elle est ce que l'utilisateur vient de
     * choisir ; la base la complète partout où elle se tait.
     *
     * @return array<string, mixed>
     */
    private function choix(Entreprise $entreprise): array
    {
        return array_merge(
            $this->choixDejaRetenus($entreprise),
            session("souscription.{$entreprise->id}", [])
        );
    }

    /**
     * Ce que l'entreprise a déjà souscrit, relu en base.
     *
     * @return array<string, mixed>
     */
    private function choixDejaRetenus(Entreprise $entreprise): array
    {
        $choix = [];

        // Reprendre uniquement les métiers du dernier domaine ouvert.
        $domaines = (array) $entreprise->domaines_souscrits;
        if ($domaines === []) {
            $domaines = (array) $entreprise->secteur_activite;
        }
        $dernierDomaine = $domaines !== [] ? end($domaines) : null;
        $categorieId = $dernierDomaine
            ? Categorie::where('nom', $dernierDomaine)->value('id')
            : null;

        if ($categorieId) {
            $choix['categorie_id'] = $categorieId;
            $profils = $entreprise->profils()
                ->where('referentiel_profils.categorie_id', $categorieId)->get();
            $choix['profils'] = $profils->pluck('code')->all();
        } elseif ($dernierDomaine && in_array($dernierDomaine, (array) $entreprise->domaines_personnalises, true)) {
            $choix['domaine_personnalise'] = $dernierDomaine;
            $choix['profils'] = [];

            // Un secteur personnalisé choisi doit lui aussi survivre à la
            // disparition de session : ses catégories et produits restent
            // alors rattachés au bon contexte au retour dans le parcours.
            $cleDomaine = 'personnalise:' . $dernierDomaine;
            $secteursChoisis = (array) (($entreprise->secteurs_selectionnes_par_domaine ?? [])[$cleDomaine] ?? []);
            if ($secteursChoisis !== []) {
                $choix['secteurs_personnalises_selection'] = $secteursChoisis;
            }
        }

        // Restaurer tous les secteurs personnalisés cochés pour le domaine,
        // qu'il soit standard ou personnalisé. Ne jamais réduire la sélection
        // à un seul secteur lors d'une reprise sans session.
        $cleDomaineActif = $categorieId
            ? 'referentiel:' . $categorieId
            : ($dernierDomaine ? 'personnalise:' . $dernierDomaine : null);
        if ($cleDomaineActif) {
            $secteursChoisis = (array) (($entreprise->secteurs_selectionnes_par_domaine ?? [])[$cleDomaineActif] ?? []);
            if ($secteursChoisis !== []) {
                $choix['secteurs_personnalises_selection'] = $secteursChoisis;
            }
        }

        if (!empty($entreprise->modules_actifs)) {
            $choix['modules'] = $entreprise->modules_actifs;
        }

        return $choix;
    }

    private function memoriser(Entreprise $entreprise, array $valeurs): void
    {
        session(["souscription.{$entreprise->id}" => array_merge($this->choix($entreprise), $valeurs)]);
    }

    /**
     * Ce que l'étape a besoin d'afficher.
     *
     * @return array<string, mixed>
     */
    private function donneesDeLEtape(int $etape, Entreprise $entreprise): array
    {
        $choix = $this->choix($entreprise);

        return match ($etape) {
            1 => ['categories' => Categorie::withCount('profils')->orderBy('ordre')->get(),
                  'domainesDejaLa' => VerrouConfigurationService::domainesSouscrits($entreprise)],

            2 => ['secteursPersonnalises' => (array) (($entreprise->secteurs_personnalises_par_domaine ?? [])[
                    !empty($choix['categorie_id']) ? 'referentiel:' . $choix['categorie_id'] : 'personnalise:' . (string) ($choix['domaine_personnalise'] ?? '')
                ] ?? []),
                  'profils' => !empty($choix['domaine_personnalise'])
                    ? collect()
                    : Profil::withCount(['familles', 'articles'])
                        ->when(isset($choix['categorie_id']), fn ($q) => $q->where('categorie_id', $choix['categorie_id']))
                        ->orderBy('nom')->get(),
                  'profilsAcquis' => VerrouConfigurationService::profilsAcquis(
                      $entreprise,
                      isset($choix['categorie_id']) ? (int) $choix['categorie_id'] : null
                  )],

            // Un module verrouillé rejoint la liste affichée même si aucun des
            // métiers choisis ne l'ouvre : l'enregistrement le rétablira de
            // toute façon, et une case qui revient cochée sans avoir été
            // montrée serait la même surprise que celle qu'on corrige ici.
            3 => ['modulesProposes' => array_values(array_unique(array_merge(
                    $this->modulesProposes($choix['profils'] ?? []),
                    array_keys(VerrouConfigurationService::modulesVerrouilles($entreprise))
                  ))),
                  'modulesAutorises' => $entreprise->modulesAutorises(),
                  'modulesVerrouilles' => VerrouConfigurationService::modulesVerrouilles($entreprise)],

            // Seuls les rayons des métiers **ajoutés** cette fois-ci (16/10/2026) :
            // ceux des métiers déjà en place sont déjà dans le catalogue, les
            // remontrer laissait croire qu'il fallait les choisir de nouveau.
            // Le numéro de compte, lui, suit la case « Comptabilité » de
            // l'étape 3.
            4 => (function () use ($choix, $entreprise) {
                $categorieCouranteId = isset($choix['categorie_id']) ? (int) $choix['categorie_id'] : null;
                $profilsAcquisDuDomaine = VerrouConfigurationService::profilsAcquis($entreprise, $categorieCouranteId);
                $nouveaux = array_values(array_diff($choix['profils'] ?? [], $profilsAcquisDuDomaine));
                $modules = $choix['modules'] ?? $entreprise->modules_actifs ?? [];
                $domaineCourant = !empty($choix['categorie_id'])
                    ? Categorie::whereKey($choix['categorie_id'])->value('nom')
                    : ($choix['domaine_personnalise'] ?? null);

                return [
                    'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
                    'categoriesPersonnalisees' => \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                        ->where('domaine_configuration', $domaineCourant)
                        ->whereIn('metier_configuration', array_values(array_unique(array_merge(
                            Profil::whereIn('code', $choix['profils'] ?? [])->pluck('nom')->all(),
                            (array) ($choix['secteurs_personnalises_selection'] ?? [])
                        ))))
                        ->orderBy('metier_configuration')->orderBy('nom')->get(),
                    'metiersCourants' => array_values(array_unique(array_merge(
                        Profil::whereIn('code', $choix['profils'] ?? [])->orderBy('nom')->pluck('nom')->all(),
                        (array) ($choix['secteurs_personnalises_selection'] ?? [])
                    ))),
                    'familles' => !empty($choix['domaine_personnalise'])
                        ? collect()
                        : Famille::with(['typeArticle', 'profil'])
                            ->whereHas('profil', fn ($q) => $q->whereIn('code', $nouveaux !== [] ? $nouveaux : ($choix['profils'] ?? [])))
                            ->withCount('articles')
                            ->orderBy('profil_id')->orderBy('code')->get(),
                    'seulementLesNouveaux' => $nouveaux !== [],
                    'comptaChoisie' => $entreprise->comptabiliteOuverte() && in_array('comptabilite', (array) $modules, true),
                ];
            })(),

            // Le stock d'ouverture se pose sur un point de vente : sans site
            // enregistré, la colonne n'aurait nulle part où écrire, et le champ
            // ne ferait qu'avaler la saisie. On la retire plutôt que de mentir.
            // Les articles qui viennent d'arriver, s'il y en a : les prix des
            // anciens se reprennent depuis le catalogue.
            5 => (function () use ($choix, $entreprise) {
                $domaineCourant = !empty($choix['categorie_id'])
                    ? Categorie::whereKey($choix['categorie_id'])->value('nom')
                    : ($choix['domaine_personnalise'] ?? null);
                $metiersCourants = array_values(array_unique(array_merge(
                    Profil::whereIn('code', $choix['profils'] ?? [])->pluck('nom')->all(),
                    (array) ($choix['secteurs_personnalises_selection'] ?? [])
                )));
                $categories = \App\Modules\Admin\Modeles\Categorie::with('pointsDeVente')
                    ->where('entreprise_id', $entreprise->id)
                    ->where('domaine_configuration', $domaineCourant)
                    ->whereIn('metier_configuration', $metiersCourants)
                    ->orderBy('nom')->get();
                $idsCategories = $categories->pluck('id')->all();

                return [
                  'articles' => Produit::with(['categorieRelation', 'stocks'])
                    ->where('entreprise_id', $entreprise->id)
                    ->whereIn('categorie_id', $idsCategories)
                    ->orderBy('reference')->get(),
                  'categoriesEntreprise' => $categories,
                  'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
                  'siteDuStock' => $this->siteDuStock($entreprise),
                ];
            })(),

            default => [],
        };
    }

    /**
     * Modules que les profils choisis ouvrent, plus le socle commun.
     *
     * @param  array<int, string>  $codesProfils
     * @return array<int, string>
     */
    private function modulesProposes(array $codesProfils): array
    {
        $modules = Entreprise::MODULES_SOCLE;

        foreach (Profil::whereIn('code', $codesProfils)->get() as $profil) {
            $modules = array_merge($modules, $profil->modulesOuverts());
        }

        return array_values(array_unique($modules));
    }
}
