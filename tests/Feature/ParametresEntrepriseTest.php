<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les paramètres de l'entreprise disent l'état réel.
 *
 * Le propriétaire a demandé de relire chaque carte. Quatre écarts en sont
 * sortis, et aucun n'était décoratif :
 *
 *  - **le régime d'imposition se refusait à l'enregistrement.** L'écran
 *    proposait les six régimes du modèle ; la règle de validation en portait
 *    quatre, écrits en dur, dont un — `RNE` — qui n'est pas un régime
 *    d'exonération mais le sigle du reçu normalisé. Une entreprise au TCE ou
 *    au régime des microentreprises choisissait son régime et se voyait
 *    refuser sans comprendre ;
 *  - **la forme juridique ne se corrigeait nulle part.** Demandée au seul
 *    formulaire d'inscription, elle n'était plus modifiable ensuite, et la
 *    passerelle Comptaflow retombait sur « SARL » pour tout le monde ;
 *  - **une carte s'annonçait « Lecture seule »** au-dessus de cinq champs qui
 *    se saisissent tous, dont le NCC et le régime — ceux-là mêmes qui
 *    décident du code TVA transmis à la DGI ;
 *  - **la procédure de conformité ne vérifiait rien.** Six points, cinq
 *    pastilles numérotées indiscernables d'un travail non fait, et une phrase
 *    d'introduction — « Les deux doivent dire la même chose » — restée d'une
 *    version où Selflow n'envoyait pas lui-même les factures.
 */
class ParametresEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-Knowing CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00042',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers', 'points_de_vente'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-param@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /** Le minimum que le formulaire exige, pour n'éprouver qu'un champ à la fois. */
    private function enregistrer(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('admin.entreprise.parametres.enregistrer'), array_merge([
            'nom'             => $this->entreprise->nom,
            'gerant_fonction' => 'Gérant',
            'adresse'         => 'Cocody, Abidjan',
            'rccm'            => 'CI-ABJ-2026-B-00042',
            'ncc'             => '2601234A',
        ], $extra));
    }

    // ── Le régime d'imposition ───────────────────────────────────────

    public function test_les_six_regimes_du_modele_s_enregistrent(): void
    {
        foreach (array_keys(Entreprise::REGIMES_IMPOSITION) as $code) {
            $this->enregistrer(['regime_imposition' => $code])
                ->assertSessionHasNoErrors();

            $this->assertSame($code, $this->entreprise->fresh()->regime_imposition,
                "Le régime {$code} est proposé à l'écran et refusé à l'enregistrement.");
        }
    }

    public function test_l_ecran_et_l_enregistrement_proposent_la_meme_liste(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        foreach (array_keys(Entreprise::REGIMES_IMPOSITION) as $code) {
            $this->assertStringContainsString('value="' . $code . '"', $corps);
        }
    }

    public function test_un_regime_invente_est_refuse(): void
    {
        // Simulation d'attaque : le régime décide du code TVA transmis à la
        // plateforme. Une valeur libre y ferait passer n'importe quoi.
        $this->enregistrer(['regime_imposition' => 'EXONERE_TOTAL'])
            ->assertSessionHasErrors('regime_imposition');
    }

    // ── La forme juridique ───────────────────────────────────────────

    public function test_la_forme_juridique_se_corrige_depuis_les_parametres(): void
    {
        $this->enregistrer(['forme_juridique' => 'SAS'])->assertSessionHasNoErrors();

        $this->assertSame('SAS', $this->entreprise->fresh()->forme_juridique);
    }

    public function test_la_forme_juridique_figure_au_recapitulatif(): void
    {
        $this->entreprise->update(['forme_juridique' => 'SARL Unipersonnelle']);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('SARL Unipersonnelle', false);
    }

    // ── Ce que les cartes annoncent ──────────────────────────────────

    public function test_aucune_carte_ne_s_annonce_en_lecture_seule(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        // Le gabarit porte sa propre mention « Lecture Seule » — celle du mode
        // aperçu, qui dit vrai. On ne lit donc que l'en-tête de la carte.
        $this->assertStringNotContainsString('Informations fiscales (Lecture', $corps);
        $this->assertStringContainsString('Identité fiscale', $corps);
    }

    public function test_la_procedure_de_conformite_ne_se_contredit_plus(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        // La phrase restait d'une version où Selflow et la plateforme étaient
        // deux systèmes à tenir d'accord ; depuis, Selflow certifie lui-même.
        $this->assertStringNotContainsString('Les deux doivent', $corps);
        $this->assertStringNotContainsString('les certifient', $corps);
    }

    // ── Ce que la procédure vérifie réellement ───────────────────────

    public function test_un_taux_de_tva_hors_bareme_est_signale(): void
    {
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'TVA-5',
            'nom' => 'Article au taux intermédiaire', 'type' => 'marchandise',
            'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 5,
        ]);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee("portent un taux que la plateforme ne sait pas représenter", false);
    }

    public function test_un_catalogue_au_bareme_ne_declenche_aucune_alerte(): void
    {
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'TVA-18',
            'nom' => 'Article au taux normal', 'type' => 'marchandise',
            'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 18,
        ]);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Aucun article hors barème', false);
    }

    public function test_un_article_archive_hors_bareme_ne_compte_pas(): void
    {
        // Il ne part plus sur aucune facture : le signaler enverrait corriger
        // ce qui n'a plus d'effet.
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'TVA-5-ARCH',
            'nom' => 'Ancien article', 'type' => 'marchandise', 'statut' => 'archive',
            'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 5,
        ]);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Aucun article hors barème', false);
    }

    public function test_le_constat_compte_les_clients_qui_portent_un_ncc(): void
    {
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan BTP', 'ncc' => '2609876B']);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Yao Particulier']);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('1 de vos clients portent un NCC', false);
    }

    public function test_le_constat_ne_compte_pas_les_clients_d_une_autre_entreprise(): void
    {
        $autre = Entreprise::create(['nom' => 'Voisine SARL', 'regime_imposition' => 'RSI']);
        Client::create(['entreprise_id' => $autre->id, 'nom' => 'Client du voisin', 'ncc' => '2600000Z']);

        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('0 de vos clients portent un NCC', false);
    }

    public function test_les_points_a_verifier_le_disent_au_lieu_de_paraitre_en_retard(): void
    {
        // Cinq points sur six affichaient une pastille grise numérotée,
        // indiscernable d'un travail non fait, alors que rien n'avait été
        // vérifié : l'API ne communique pas les noms de points de vente
        // déclarés chez la DGI, ni les options cochées sur l'espace.
        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('à vérifier sur votre espace FNE', false);
    }

    // ── La page occupe la largeur qu'on lui donne ────────────────────

    /**
     * Découpe la grille en ses deux colonnes, telles que le navigateur les
     * empilera.
     *
     * @return array{0: string, 1: string}
     */
    private function colonnes(string $corps): array
    {
        $morceaux = explode('class="colonne-parametres"', $corps);
        $this->assertCount(3, $morceaux, 'La grille ne porte plus exactement deux colonnes.');

        $droite = explode('pleine-largeur', $morceaux[2])[0];

        return [$morceaux[1], $droite];
    }

    public function test_les_deux_colonnes_portent_chacune_leur_part_des_cartes(): void
    {
        // Huit cartes sur dix tenaient à gauche : de « DGI & Local
        // professionnel » jusqu'à « Impression des factures », la moitié
        // droite de la page restait blanche sur quatre écrans de défilement.
        [$gauche, $droite] = $this->colonnes(
            $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent()
        );

        $aGauche = substr_count($gauche, 'class="card"');
        $aDroite = substr_count($droite, 'class="card"');

        $this->assertGreaterThanOrEqual(4, $aGauche);
        $this->assertGreaterThanOrEqual(4, $aDroite);
        $this->assertLessThanOrEqual(2, abs($aGauche - $aDroite),
            "Colonne gauche : {$aGauche} cartes, colonne droite : {$aDroite}.");
    }

    public function test_chaque_carte_se_trouve_dans_une_seule_colonne(): void
    {
        [$gauche, $droite] = $this->colonnes(
            $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent()
        );

        // Déplacer une carte d'une colonne à l'autre se fait en coupant du
        // Blade : une carte laissée en double, ou perdue en route, ne se verrait
        // qu'à l'écran.
        foreach (['identite' => $gauche, 'fiscal' => $gauche, 'dgi' => $gauche,
                  'tiers' => $gauche, 'compte-fne' => $droite, 'options' => $droite,
                  'impression' => $droite] as $ancre => $attendue) {
            $this->assertSame(1, substr_count($attendue, 'id="' . $ancre . '"'),
                "La carte « {$ancre} » n'est pas dans la colonne attendue, ou s'y trouve en double.");
        }
    }

    public function test_les_raccourcis_menent_a_toutes_les_cartes(): void
    {
        /*
         * Les raccourcis étaient une liste écrite à la main, et deux cartes
         * ajoutées depuis n'y figuraient pas — les libellés d'écriture et les
         * pièces refusées. Une barre de raccourcis incomplète est pire qu'aucune :
         * on la croit exhaustive, et l'on conclut que le réglage n'existe pas.
         */
        $page = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        // Chaque ancre posée dans la page doit avoir son raccourci.
        preg_match_all('/<span id="([a-z-]+)" style="scroll-margin-top/', $page, $ancres);

        $sans = [];
        foreach (array_unique($ancres[1]) as $ancre) {
            if (!str_contains($page, 'href="#' . $ancre . '"')) {
                $sans[] = $ancre;
            }
        }

        $this->assertSame([], $sans,
            "Ces sections n'ont pas de raccourci : " . implode(', ', $sans));
    }

    public function test_les_raccourcis_sont_ranges_par_sujet(): void
    {
        // Une seule rangée de onze pastilles indifférenciées ne se lit pas :
        // elle se parcourt, ce qui est ce qu'elle devait éviter.
        $page = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        foreach (["L&#039;entreprise", 'Fiscalité &amp; DGI', 'Comptabilité', 'Documents'] as $famille) {
            $this->assertStringContainsString($famille, $page, $famille);
        }
    }

    public function test_la_procedure_de_conformite_occupe_toute_la_largeur(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        // La plus longue des cartes : sur une demi-largeur, ses six points
        // formaient une colonne de texte de deux écrans de haut.
        $this->assertStringContainsString('class="card pleine-largeur"', $corps);
        $this->assertStringContainsString('class="conformite-etapes"', $corps);

        $this->assertGreaterThan(
            strpos($corps, 'id="impression"'),
            strpos($corps, 'id="conformite"'),
            'La conformité passe en pleine largeur : elle vient donc après les deux colonnes.'
        );
    }

    public function test_la_grille_se_replie_sur_un_ecran_etroit(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        // Un style écrit dans la balise l'emporte sur la feuille, media query
        // comprise : tant que la grille portait le sien, les deux colonnes
        // restaient côte à côte sur un portable.
        $this->assertStringNotContainsString(
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">', $corps);
        $this->assertStringContainsString('@media (max-width: 1024px)', $corps);
    }

    // ── La page se parcourt ──────────────────────────────────────────

    public function test_la_page_porte_ses_ancres(): void
    {
        $corps = $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        foreach (['identite', 'fiscal', 'comptaflow', 'dgi', 'compte-fne',
                  'conformite', 'options', 'tiers', 'impression', 'exercices',
                  'statut-fne'] as $ancre) {
            $this->assertStringContainsString('id="' . $ancre . '"', $corps,
                "L'ancre « {$ancre} » est annoncée en tête de page et ne mène nulle part.");
            $this->assertStringContainsString('href="#' . $ancre . '"', $corps);
        }
    }
}
