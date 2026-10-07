<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le parcours de configuration d'une entreprise.
 *
 * Il se déplie étape par étape, se quitte et se reprend, et le superadmin n'y
 * intervient jamais. Ces tests fixent l'enchaînement, et surtout ce qu'un
 * formulaire forgé ne doit pas pouvoir obtenir.
 */
class ParcoursSouscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);

        $this->entreprise = Entreprise::create(['nom' => 'Boutique du carrefour']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function commerce(): Categorie
    {
        return Categorie::where('nom', 'Commerce')->firstOrFail();
    }

    public function test_le_parcours_s_ouvre_sur_le_choix_du_domaine(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index'))
            ->assertOk()
            ->assertSee('Dans quel domaine travaillez-vous')
            ->assertSee('Commerce');
    }

    public function test_chaque_etape_ouvre_la_suivante(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 2]));

        $this->assertSame(1, $this->entreprise->fresh()->souscription_etape);

        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index', ['etape' => 2]))
            ->assertOk()
            ->assertSee('Quel est votre métier')
            ->assertSee('Boutique de quartier / Alimentation générale');
    }

    public function test_une_etape_non_atteinte_est_refusee(): void
    {
        // Sans ce controle, un formulaire forge sauterait le choix des metiers
        // et souscrirait a des familles qui n'appartiennent a aucun d'eux.
        $this->actingAs($this->admin)
            ->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']])
            ->assertRedirect(route('admin.souscription.index'))
            ->assertSessionHas('erreur');

        $this->assertSame(0, Produit::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_le_parcours_complet_remplit_le_catalogue(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes', 'stock']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV', 'BOI']])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 5]));

        // Seuls les deux rayons retenus, avec leurs articles.
        $this->assertSame(2, \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertGreaterThan(0, Produit::where('entreprise_id', $this->entreprise->id)->count());

        $entreprise = $this->entreprise->fresh();
        $this->assertTrue($entreprise->moduleEstActif('stock'));
        $this->assertFalse($entreprise->moduleEstActif('b2b'));
    }

    public function test_un_rayon_decoche_n_apporte_ni_article_ni_compte(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $categories = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $this->entreprise->id)->get();

        $this->assertSame(1, $categories->count());
        $this->assertSame('Vivres et alimentation', $categories->first()->nom);

        // Aucun article orphelin : un article dont le rayon n'a pas ete retenu
        // n'aurait nulle part ou aller, et resterait invisible dans les listes.
        $this->assertSame(0, Produit::where('entreprise_id', $this->entreprise->id)
            ->whereNull('categorie_id')->count());
    }

    public function test_l_activite_hors_referentiel_est_retenue(): void
    {
        // Le referentiel ne couvre pas tous les metiers : plutot que de forcer
        // l'utilisateur dans une case, on note ce qu'il dit.
        $this->actingAs($this->admin);

        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['activite_autre' => 'Atelier de reliure et dorure'])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 3]));

        $this->assertSame('Atelier de reliure et dorure', $this->entreprise->fresh()->activite_autre);
    }

    public function test_l_etape_du_metier_exige_un_choix_ou_une_description(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);

        $this->post(route('admin.souscription.enregistrer', 2), [])
            ->assertSessionHasErrors('profils');
    }

    public function test_un_module_non_autorise_ne_peut_pas_s_activer_par_le_formulaire(): void
    {
        // Les droits appartiennent au superadmin : un module ferme ne s'ouvre
        // pas en l'ajoutant a la requete.
        $this->entreprise->update(['modules_autorises' => ['principal', 'ventes', 'produits', 'tiers']]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes', 'b2b', 'fne']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $entreprise = $this->entreprise->fresh();
        $this->assertFalse($entreprise->moduleEstActif('b2b'));
        $this->assertFalse($entreprise->moduleEstActif('fne'));
        $this->assertTrue($entreprise->moduleEstActif('ventes'));
    }

    // ══════════════ Les points de vente, qui disparaissaient ══════════════

    public function test_l_etape_des_modules_propose_les_points_de_vente(): void
    {
        // **La liste de base l'oubliait**, et elle vivait en double : une copie
        // dans le contrôleur pour afficher les cases, une autre dans le service
        // pour écrire `modules_actifs`. Les deux avaient dérivé de la même
        // façon, et la section disparaissait de la barre latérale sitôt la
        // souscription enregistrée, sans que rien ne l'explique.
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);

        // On vise la case elle-même, pas le texte : « Points de vente » figure
        // aussi dans la barre latérale, qui affiche tout tant que
        // `modules_actifs` est vide. L'épreuve passerait sans rien prouver.
        $this->get(route('admin.souscription.index', ['etape' => 3]))
            ->assertOk()
            ->assertSee('name="modules[]" value="points_de_vente"', false);
    }

    public function test_les_points_de_vente_survivent_a_la_souscription(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $this->assertTrue($this->entreprise->fresh()->moduleEstActif('points_de_vente'));
    }

    public function test_les_points_de_vente_ne_se_decochent_pas(): void
    {
        // Ils ne portent pas que les sites : **le personnel et les
        // habilitations vivent derrière**. Les retirer priverait
        // l'administrateur de l'écran où il gère ses propres utilisateurs et
        // leurs droits — personne ne fait ce choix en connaissance de cause.
        //
        // La case est désactivée à l'écran, et une case désactivée n'est pas
        // transmise : le rattrapage se fait donc côté serveur, où un formulaire
        // forgé le rencontre aussi.
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $entreprise = $this->entreprise->fresh();

        $this->assertTrue($entreprise->moduleEstActif('points_de_vente'));
        $this->assertTrue($entreprise->moduleEstActif('principal'));
    }

    public function test_la_barre_laterale_garde_les_points_de_vente_apres_la_souscription(): void
    {
        // L'épreuve du symptôme, et non de sa cause : c'est l'entrée de menu
        // que l'utilisateur a vue disparaître.
        $this->admin->update(['habilitations' => ['gestion_pdv', 'tableau_de_bord']]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $this->get(route('admin.pdv.index'))
            ->assertOk()
            ->assertSee('<div class="nav-section"><span>Points de vente</span></div>', false);
    }

    public function test_un_superadmin_peut_toujours_fermer_les_points_de_vente(): void
    {
        // Structurel ne veut pas dire hors de contrôle : les droits restent au
        // superadmin, et un module non autorisé ne s'ouvre pas parce que le
        // parcours le juge indispensable.
        $this->entreprise->update(['modules_autorises' => ['principal', 'ventes', 'produits', 'tiers']]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $this->assertFalse($this->entreprise->fresh()->moduleEstActif('points_de_vente'));
    }

    public function test_la_liste_de_base_ne_vit_plus_en_double(): void
    {
        // C'est la duplication qui a produit l'anomalie : deux copies de la
        // même liste, dérivant chacune de son côté. Une seule, désormais, et
        // cette épreuve refuse qu'on en recrée une.
        foreach ([
            'app/Modules/Admin/Controleurs/SouscriptionControleur.php',
            'app/Modules/Admin/Services/SouscriptionProfilService.php',
        ] as $fichier) {
            $source = file_get_contents(base_path($fichier));

            $this->assertStringContainsString('Entreprise::MODULES_SOCLE', $source,
                "{$fichier} devrait lire la liste de base sur le modèle.");
            $this->assertDoesNotMatchRegularExpression(
                "/\[\s*'principal'\s*,\s*'ventes'/", $source,
                "{$fichier} recopie la liste de base au lieu de la lire."
            );
        }
    }

    public function test_les_prix_saisis_sont_enregistres_et_le_parcours_se_termine(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes', 'stock']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $riz = Produit::where('entreprise_id', $this->entreprise->id)->firstOrFail();

        $this->post(route('admin.souscription.enregistrer', 5), [
            'articles' => [[
                'id' => $riz->id, 'nom' => 'Riz parfumé 25 kg',
                'prix_achat' => 15000, 'prix_vente' => 17500,
            ]],
        ])->assertRedirect(route('admin.tableau_de_bord'));

        $riz->refresh();
        $this->assertSame('Riz parfumé 25 kg', $riz->nom);
        $this->assertEquals(17500, $riz->prix_vente);
        $this->assertNotNull($this->entreprise->fresh()->souscription_terminee_le);
    }

    public function test_on_ne_peut_pas_fixer_le_prix_d_un_produit_d_une_autre_entreprise(): void
    {
        // La vraie surface d'attaque n'est pas l'URL, c'est le corps de la
        // requete : il suffirait d'envoyer l'identifiant du produit du voisin.
        $voisine = Entreprise::create(['nom' => 'Quincaillerie du plateau']);
        $etranger = Produit::create([
            'entreprise_id' => $voisine->id, 'reference' => 'VOISIN-001',
            'nom' => 'Ciment', 'type' => 'marchandise', 'prix_achat' => 5000, 'prix_vente' => 6000,
        ]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $this->post(route('admin.souscription.enregistrer', 5), [
            'articles' => [['id' => $etranger->id, 'nom' => 'Détourné', 'prix_vente' => 1]],
        ])->assertSessionHasErrors('articles.0.id');

        $this->assertSame('Ciment', $etranger->fresh()->nom);
    }

    /**
     * Amener l'entreprise jusqu'à l'étape des prix, catalogue rempli.
     */
    private function allerJusquAuxPrix(array $modules = ['principal', 'ventes', 'stock']): void
    {
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => $modules]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);
    }

    private function unSite(): PointDeVente
    {
        return PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
    }

    public function test_le_stock_d_ouverture_est_pose_sur_le_site(): void
    {
        $site = $this->unSite();

        $this->actingAs($this->admin);
        $this->allerJusquAuxPrix();

        $riz = Produit::where('entreprise_id', $this->entreprise->id)->firstOrFail();

        $this->post(route('admin.souscription.enregistrer', 5), [
            'articles' => [['id' => $riz->id, 'prix_vente' => 17500, 'stock_initial' => 42]],
        ])->assertRedirect(route('admin.tableau_de_bord'));

        $this->assertDatabaseHas('stocks', [
            'produit_id' => $riz->id,
            'point_de_vente_id' => $site->id,
            'quantite_disponible' => 42,
        ]);
    }

    public function test_un_service_ne_recoit_aucune_fiche_de_stock(): void
    {
        // Une prestation ne s'epuise pas : lui creer une fiche la ferait
        // figurer en permanence dans « Alertes stock », sous un seuil de 5.
        $site = $this->unSite();

        $this->actingAs($this->admin);
        $this->allerJusquAuxPrix();

        $mission = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'PRESTA-001',
            'nom' => 'Livraison à domicile', 'type' => 'service',
            'prix_achat' => 0, 'prix_vente' => 2000,
        ]);

        $this->post(route('admin.souscription.enregistrer', 5), [
            'articles' => [['id' => $mission->id, 'stock_initial' => 99]],
        ]);

        $this->assertDatabaseMissing('stocks', ['produit_id' => $mission->id]);
    }

    public function test_sans_site_le_stock_d_ouverture_ne_s_affiche_pas(): void
    {
        // Le champ n'aurait nulle part ou ecrire : mieux vaut ne pas le montrer
        // que d'avaler la saisie en silence.
        $this->actingAs($this->admin);
        $this->allerJusquAuxPrix();

        $this->get(route('admin.souscription.index', ['etape' => 5]))
            ->assertOk()
            ->assertDontSee('Stock de départ');
    }

    public function test_le_stock_d_ouverture_ne_s_affiche_pas_sans_le_module(): void
    {
        $this->unSite();

        $this->actingAs($this->admin);
        $this->allerJusquAuxPrix(['principal', 'ventes']);

        $this->get(route('admin.souscription.index', ['etape' => 5]))
            ->assertOk()
            ->assertSee('Prix de vente')
            ->assertDontSee('Stock de départ');
    }

    public function test_le_stock_d_ouverture_s_affiche_avec_un_site_et_le_module(): void
    {
        $this->unSite();

        $this->actingAs($this->admin);
        $this->allerJusquAuxPrix();

        $this->get(route('admin.souscription.index', ['etape' => 5]))
            ->assertOk()
            ->assertSee('Stock de départ')
            ->assertSee('Magasin central');
    }

    public function test_le_parcours_est_ferme_aux_visiteurs(): void
    {
        $this->get(route('admin.souscription.index'))->assertRedirect();
    }

    // ══════════════ Le référentiel absent ══════════════

    public function test_sans_referentiel_la_page_dit_ce_qui_manque(): void
    {
        // `ReferentielSeeder` n'était appelé par aucun autre semeur : une
        // installation neuve arrivait sur « Dans quel domaine travaillez-vous ? »
        // avec la question, le bouton, et **rien entre les deux**. Le formulaire
        // exige pourtant un domaine — continuer était impossible, et rien ne
        // disait pourquoi.
        Categorie::query()->delete();

        $reponse = $this->actingAs($this->admin)->get(route('admin.souscription.index'));

        $reponse->assertOk();
        $reponse->assertSee('catalogue des domaines d\'activité n\'est pas chargé', false);
        $reponse->assertSee('ReferentielSeeder', false);
    }

    public function test_sans_referentiel_le_bouton_continuer_disparait(): void
    {
        // Un bouton qui ramène sur la même page n'aide personne.
        Categorie::query()->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index'))
            ->assertDontSee('Continuer <i class="fas fa-arrow-right"></i>', false);
    }

    // ── La reprise depuis les paramètres ─────────────────────────────

    /**
     * Faire suivre le parcours en entier, puis effacer la session — ce que
     * fait toute reconnexion.
     */
    private function parcoursTermineEtSessionPerdue(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes', 'stock']]);
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);
        $this->post(route('admin.souscription.enregistrer', 5), []);

        $this->flushSession();
        $this->actingAs($this->admin->fresh());
    }

    public function test_les_parametres_ouvrent_le_parcours_de_configuration(): void
    {
        // Le parcours n'etait atteignable qu'au demarrage : une entreprise qui
        // ajoute un metier ou veut rouvrir un module n'avait plus de chemin.
        $this->parcoursTermineEtSessionPerdue();

        $this->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->assertSee(route('admin.souscription.index'), false);
    }

    /**
     * La case portant cette valeur est-elle cochée ?
     *
     * Le gabarit rend l'attribut sur la ligne suivante ; une recherche de
     * chaîne littérale ne le verrait pas.
     */
    private function estCochee(string $corps, string $valeur): bool
    {
        return (bool) preg_match(
            '/value="' . preg_quote($valeur, '/') . '"\s+checked/',
            $corps
        );
    }

    public function test_la_reprise_retrouve_le_domaine_deja_choisi(): void
    {
        // Le parcours ne lisait que la session. Une fois celle-ci perdue,
        // l'etape 1 revenait vierge et l'utilisateur devait rechoisir son
        // domaine -- au risque d'en designer un autre.
        $this->parcoursTermineEtSessionPerdue();

        $corps = $this->get(route('admin.souscription.index', ['etape' => 1]))
            ->assertOk()->getContent();

        $this->assertTrue($this->estCochee($corps, (string) $this->commerce()->id),
            'Le domaine déjà choisi doit revenir coché.');
    }

    public function test_la_reprise_retrouve_les_metiers_deja_souscrits(): void
    {
        $this->parcoursTermineEtSessionPerdue();

        $corps = $this->get(route('admin.souscription.index', ['etape' => 2]))
            ->assertOk()->getContent();

        $this->assertTrue($this->estCochee($corps, 'boutique_quartier'),
            'Le métier déjà souscrit doit revenir coché.');
    }

    public function test_la_reprise_ne_rouvre_pas_un_module_ferme(): void
    {
        // Le pire cas : les modules revenaient tous coches, et valider
        // l'etape rouvrait ce que l'utilisateur avait volontairement ferme.
        $this->parcoursTermineEtSessionPerdue();

        $this->assertFalse($this->entreprise->fresh()->moduleEstActif('comptabilite'));

        $corps = $this->get(route('admin.souscription.index', ['etape' => 3]))
            ->assertOk()->getContent();

        $this->assertTrue($this->estCochee($corps, 'ventes'),
            'Un module ouvert doit revenir coché.');
        $this->assertFalse($this->estCochee($corps, 'comptabilite'),
            'Un module fermé ne doit pas se rouvrir tout seul.');
    }

    public function test_la_reprise_ne_double_ni_les_rayons_ni_les_articles(): void
    {
        $this->parcoursTermineEtSessionPerdue();

        $rayons   = \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $this->entreprise->id)->count();
        $articles = Produit::where('entreprise_id', $this->entreprise->id)->count();

        // Repasser l'etape qui souscrit reellement.
        $this->post(route('admin.souscription.enregistrer', 4), ['familles' => ['VIV']]);

        $this->assertSame($rayons, \App\Modules\Admin\Modeles\Categorie::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertSame($articles, Produit::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_un_parcours_termine_offre_une_sortie(): void
    {
        // Sans elle, celui qui vient completer un point devrait traverser les
        // cinq etapes pour retrouver ses parametres.
        $this->parcoursTermineEtSessionPerdue();

        $this->get(route('admin.souscription.index', ['etape' => 2]))
            ->assertOk()
            ->assertSee('Quitter');
    }

    public function test_un_parcours_en_cours_n_offre_pas_de_sortie(): void
    {
        // Tant que la configuration n'est pas achevee, la seule issue est de
        // la poursuivre : proposer une sortie laisserait une entreprise a
        // demi configuree, sans catalogue ni comptes.
        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index'))
            ->assertOk()
            ->assertDontSee('Quitter');
    }

    public function test_le_semeur_par_defaut_charge_le_referentiel(): void
    {
        // La cause première : `db:seed` ne chargeait pas le référentiel, donc
        // une base fraîchement installée ne pouvait pas franchir l'étape 1.
        // Le semeur complet ne se rejoue pas ici — il repose les données que ce
        // test a déjà créées — mais son enchaînement, lui, se lit.
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertMatchesRegularExpression(
            '/\$this->call\(\s*ReferentielSeeder::class\s*\)/',
            $source,
            'Le semeur par défaut doit charger le référentiel : sans lui, une '
            . 'installation neuve ne peut pas franchir la première étape de la souscription.'
        );
    }

    // ── Lot 41 : ce que l'étape 3 et l'étape 4 montrent encore ───────

    /**
     * Chantier 3.5. L'inscription ouvre dix modules d'office, et l'étape 3
     * les présentait cochés en annonçant qu'ils « allaient être activés ».
     */
    public function test_un_module_deja_ouvert_est_montre_comme_acquis(): void
    {
        $this->entreprise->update(['modules_actifs' => ['principal', 'ventes', 'points_de_vente']]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 3]));

        $corps = $this->get(route('admin.souscription.index', ['etape' => 3]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/value="ventes"\s+checked\s+disabled/', $corps,
            'Un module déjà ouvert ne se présente pas comme un choix.');
        $this->assertStringContainsString('déjà ouvert', $corps);
    }

    public function test_l_etape_3_se_saute_quand_tout_est_deja_ouvert(): void
    {
        $this->entreprise->update(['modules_actifs' => Entreprise::TOUS_LES_MODULES]);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 4]))
            ->assertSessionHas('succes');

        $this->assertSame(3, $this->entreprise->fresh()->souscription_etape);
    }

    /**
     * Chantier 3.4. Le numéro de compte s'affichait à côté de chaque rayon
     * — « Vivres et alimentation (311100) » — à qui ne tient pas de livres.
     */
    public function test_le_numero_de_compte_du_rayon_suit_la_comptabilite(): void
    {
        $compte = \App\Modules\Admin\Modeles\Referentiel\Famille::where('code', 'VIV')->value('compte_vente');
        $this->assertNotEmpty($compte);

        $this->actingAs($this->admin);
        $this->post(route('admin.souscription.enregistrer', 1), ['categorie_id' => $this->commerce()->id]);
        $this->post(route('admin.souscription.enregistrer', 2), ['profils' => ['boutique_quartier']]);
        $this->post(route('admin.souscription.enregistrer', 3), ['modules' => ['principal', 'ventes']]);

        $this->get(route('admin.souscription.index', ['etape' => 4]))
            ->assertOk()->assertSee('Vivres et alimentation')->assertDontSee($compte);

        $this->entreprise->update(['comptabilite_activee' => true]);
        $this->admin->unsetRelation('entreprise');

        $this->get(route('admin.souscription.index', ['etape' => 4]))
            ->assertOk()->assertSee($compte);
    }
}
