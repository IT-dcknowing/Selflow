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
            'categorie_id' => ['nullable', 'integer', 'exists:referentiel_categories,id', 'required_without:domaine_personnalise'],
            'domaine_personnalise' => ['nullable', 'string', 'max:120', 'required_without:categorie_id'],
        ], [
            'categorie_id.required_without' => 'Choisissez un domaine ou créez le vôtre.',
            'domaine_personnalise.required_without' => 'Saisissez le nom de votre domaine.',
        ]);

        $domaine = trim((string) ($donnees['domaine_personnalise'] ?? ''));
        if ($domaine !== '') {
            $personnalises = array_values(array_unique(array_filter(array_merge(
                (array) $entreprise->domaines_personnalises, [$domaine]
            ))));
            $entreprise->update(['domaines_personnalises' => $personnalises]);
        }

        $this->memoriser($entreprise, [
            'categorie_id' => isset($donnees['categorie_id']) ? (int) $donnees['categorie_id'] : null,
            'domaine_personnalise' => $domaine ?: null,
        ]);

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
        $acquis = VerrouConfigurationService::profilsAcquis($entreprise);

        $donnees = $request->validate([
            // Exiger un métier n'a de sens que la première fois. Une entreprise
            // qui a déjà souscrit en porte un : lui redemander d'en cocher un
            // bloquerait le parcours de quelqu'un qui vient seulement le
            // traverser pour rouvrir un module ou ajouter un rayon.
            'profils'          => ['nullable', 'array'],
            'profils.*'        => ['string', 'exists:referentiel_profils,code'],
            'secteurs_personnalises' => ['nullable', 'array'],
            'secteurs_personnalises.*' => ['nullable', 'string', 'max:150'],
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

        $secteursPersonnalises = array_values(array_unique(array_filter(array_map('trim', $donnees['secteurs_personnalises'] ?? []))));
        if ($secteursPersonnalises !== []) {
            $entreprise->update(['secteurs_personnalises' => array_values(array_unique(array_merge(
                (array) $entreprise->secteurs_personnalises, $secteursPersonnalises
            )))]);
        }

        $this->memoriser($entreprise, ['profils' => $retenus]);

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
        $donnees = $request->validate([
            'modules'   => ['array'],
            'modules.*' => ['string', 'in:' . implode(',', Entreprise::TOUS_LES_MODULES)],
            'modules_personnalises' => ['nullable', 'array'],
            'modules_personnalises.*' => ['nullable', 'string', 'max:120'],
        ]);

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
                $donnees['modules'] ?? [],
                Entreprise::MODULES_STRUCTURELS,
                array_keys(VerrouConfigurationService::modulesVerrouilles($entreprise))
            )),
            $entreprise->modulesAutorises()
        ));

        $modulesPersonnalises = array_values(array_unique(array_filter(array_map('trim', $donnees['modules_personnalises'] ?? []))));
        if ($modulesPersonnalises !== []) {
            $entreprise->update(['modules_personnalises' => array_values(array_unique(array_merge(
                (array) $entreprise->modules_personnalises, $modulesPersonnalises
            )))]);
        }

        $this->memoriser($entreprise, ['modules' => $retenus]);

        return $this->avancerVers(4, $entreprise);
    }

    private function validerFamilles(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'familles' => ['nullable', 'array'],
            'familles.*' => ['string', 'max:32'],
            'categories_personnalisees' => ['nullable', 'array'],
            'categories_personnalisees.*.nom' => ['nullable', 'string', 'max:120'],
        ]);

        $choix = $this->choix($entreprise);
        $retenues = $donnees['familles'] ?? [];
        $avant = now()->subSecond();
        $bilan = SouscriptionProfilService::souscrire($entreprise, $choix['profils'] ?? [], $retenues);

        // À l'étape 4, on choisit simplement les catégories proposées ou on
        // crée une catégorie. Leur personnalisation détaillée est à l'étape 5.
        foreach ($donnees['categories_personnalisees'] ?? [] as $ligne) {
            $nom = trim((string) ($ligne['nom'] ?? ''));
            if ($nom === '') continue;
            $prefixe = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nom) ?: 'CAT', 0, 8));
            \App\Modules\Admin\Modeles\Categorie::firstOrCreate(
                ['entreprise_id' => $entreprise->id, 'nom' => $nom],
                ['prefixe' => $prefixe]
            );
        }

        $ajoutes = Produit::where('entreprise_id', $entreprise->id)
            ->where('created_at', '>=', $avant)->pluck('id')->all();
        session(["souscription_articles.{$entreprise->id}" => $ajoutes ?: null]);

        if (isset($choix['modules'])) {
            $entreprise->update([
                'modules_actifs' => array_values(array_intersect(
                    $bilan['modules'],
                    array_merge($choix['modules'], Entreprise::MODULES_STRUCTURELS)
                )),
            ]);
        }

        VerrouConfigurationService::alignerLeSecteur($entreprise->refresh());

        return $this->avancerVers(5, $entreprise)->with('succes', sprintf(
            '%d catégorie(s) et %d article(s) ajoutés à votre catalogue.',
            $bilan['familles'], $bilan['articles']
        ));
    }

    private function validerPrix(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'articles'                 => ['array'],
            'articles.*.id'            => ['required', 'integer', Appartenance::a('produits')],
            'articles.*.nom'           => ['nullable', 'string', 'max:255'],
            'articles.*.prix_achat'    => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'articles.*.prix_vente'    => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'articles.*.stock_initial' => Quantite::facultative(),
            'categories_config' => ['nullable', 'array'],
            'categories_config.*.nom' => ['required', 'string', 'max:120'],
            'categories_config.*.prefixe' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9_-]+$/'],
            'categories_config.*.points_de_vente' => ['required', 'array', 'min:1'],
            'categories_config.*.points_de_vente.*' => ['integer', 'distinct', 'exists:points_de_vente,id'],
            'categories_nouvelles_config' => ['nullable', 'array'],
            'categories_nouvelles_config.*.nom' => ['required', 'string', 'max:120'],
            'categories_nouvelles_config.*.prefixe' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9_-]+$/'],
            'categories_nouvelles_config.*.points_de_vente' => ['required', 'array', 'min:1'],
            'categories_nouvelles_config.*.points_de_vente.*' => ['integer', 'distinct', 'exists:points_de_vente,id'],
            'categories_a_supprimer' => ['nullable', 'array'],
            'categories_a_supprimer.*' => ['integer', 'distinct'],
        ]);

        // La configuration des catégories se fait ici, à l'étape 5 :
        // nom, préfixe, références et périmètre des points de vente.
        foreach ($donnees['categories_config'] ?? [] as $id => $configuration) {
            $categorie = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                ->whereKey($id)->first();
            if (!$categorie) continue;

            $ancienNom = (string) $categorie->nom;
            $ancienPrefixe = strtoupper((string) $categorie->prefixe);
            $nom = trim($configuration['nom']);
            $prefixeSaisi = strtoupper(trim($configuration['prefixe']));
            $prefixe = ($nom !== $ancienNom && $prefixeSaisi === $ancienPrefixe)
                ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nom) ?: 'CAT', 0, 8))
                : $prefixeSaisi;
            $categorie->update(['nom' => $nom, 'prefixe' => $prefixe]);

            $sites = PointDeVente::where('entreprise_id', $entreprise->id)
                ->whereIn('id', $configuration['points_de_vente'])->pluck('id')->all();
            if ($sites === []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "categories_config.{$id}.points_de_vente" => 'Sélectionnez au moins un point de vente de votre entreprise.',
                ]);
            }
            $categorie->pointsDeVente()->sync($sites);

            if ($prefixe !== $ancienPrefixe || $nom !== $ancienNom) {
                $sequence = 1;
                foreach (Produit::where('entreprise_id', $entreprise->id)
                    ->where('categorie_id', $categorie->id)->orderBy('id')->get() as $produit) {
                    do {
                        $reference = $prefixe.'-'.str_pad((string) $sequence++, 5, '0', STR_PAD_LEFT);
                    } while (Produit::where('entreprise_id', $entreprise->id)
                        ->where('reference', $reference)->where('id', '!=', $produit->id)->exists());
                    $produit->update(['reference' => $reference]);
                }
            }
        }

        foreach ($donnees['categories_nouvelles_config'] ?? [] as $configuration) {
            $categorie = \App\Modules\Admin\Modeles\Categorie::create([
                'entreprise_id' => $entreprise->id,
                'nom' => trim($configuration['nom']),
                'prefixe' => strtoupper(trim($configuration['prefixe'])),
            ]);
            $sites = PointDeVente::where('entreprise_id', $entreprise->id)
                ->whereIn('id', $configuration['points_de_vente'])->pluck('id')->all();
            $categorie->pointsDeVente()->sync($sites);
        }

        foreach ($donnees['categories_a_supprimer'] ?? [] as $categorieId) {
            $categorie = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $entreprise->id)
                ->whereKey($categorieId)->first();
            if (!$categorie) continue;
            // Conserver les produits et leurs références : seule l'affectation
            // à cette catégorie est retirée.
            Produit::where('entreprise_id', $entreprise->id)
                ->where('categorie_id', $categorie->id)
                ->update(['categorie_id' => null]);
            $categorie->pointsDeVente()->detach();
            $categorie->delete();
        }

        $pointDeVenteId = $this->siteDuStock($entreprise)?->id;

        foreach ($donnees['articles'] ?? [] as $ligne) {
            // `Appartenance` a déjà écarté les produits d'une autre entreprise ;
            // la condition ci-dessous est la ceinture après les bretelles.
            $produit = Produit::where('id', $ligne['id'])
                ->where('entreprise_id', $entreprise->id)
                ->first();

            if (!$produit) {
                continue;
            }

            // Chaque clé est facultative : le corps de la requête n'est pas le
            // formulaire, et une ligne partielle ne doit pas faire tomber
            // l'écran. Une valeur absente laisse l'existante en place.
            $produit->update(array_filter([
                'nom'        => ($ligne['nom'] ?? null) ?: null,
                'prix_achat' => $ligne['prix_achat'] ?? null,
                'prix_vente' => $ligne['prix_vente'] ?? null,
            ], fn ($v) => $v !== null));

            $this->poserStockInitial($produit, $pointDeVenteId, $ligne['stock_initial'] ?? null);
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
        $profils = $entreprise->profils()->get();

        if ($profils->isEmpty()) {
            return [];
        }

        $choix = ['profils' => $profils->pluck('code')->all()];

        // Le domaine n'est pas stocké : il se relit du premier métier souscrit,
        // puisque c'est lui qui l'a fait apparaître à l'étape 2.
        if ($categorieId = $profils->first()->categorie_id) {
            $choix['categorie_id'] = $categorieId;
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

            2 => ['profils' => Profil::withCount(['familles', 'articles'])
                    ->when(isset($choix['categorie_id']), fn ($q) => $q->where('categorie_id', $choix['categorie_id']))
                    ->orderBy('nom')->get(),
                  'profilsAcquis' => VerrouConfigurationService::profilsAcquis($entreprise)],

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
                $nouveaux = array_values(array_diff($choix['profils'] ?? [], VerrouConfigurationService::profilsAcquis($entreprise)));
                $modules = $choix['modules'] ?? $entreprise->modules_actifs ?? [];

                return [
                    'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
                    'familles' => Famille::with(['typeArticle', 'profil'])
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
            5 => ['articles' => Produit::with(['categorieRelation', 'stocks'])
                    ->where('entreprise_id', $entreprise->id)
                    ->when(session("souscription_articles.{$entreprise->id}"), fn ($q, $ids) => $q->whereIn('id', $ids))
                    ->orderBy('reference')->get(),
                  'categoriesEntreprise' => \App\Modules\Admin\Modeles\Categorie::with('pointsDeVente')
                    ->where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
                  'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
                  'siteDuStock' => $this->siteDuStock($entreprise)],

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
