<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Regles\EtapesCreation;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Créer une entreprise, des deux côtés.
 *
 * Deux écrans le font : `/inscription`, où l'entreprise s'inscrit elle-même,
 * et celui du superadministrateur, où on l'inscrit pour elle. Ils ne
 * demandaient ni les mêmes choses, ni dans le même ordre — et l'un des deux ne
 * marchait pas du tout.
 *
 * ## Le défaut principal
 *
 * **L'écran du superadministrateur créait une entreprise et personne pour s'y
 * connecter.** Aucun `Utilisateur` n'était enregistré, et le formulaire ne
 * demandait pas même un mot de passe. Toute entreprise créée par cette voie
 * était donc inutilisable, sans qu'aucune erreur ne le signale : il fallait
 * s'en apercevoir, puis lui fabriquer un compte à la main.
 *
 * ## Les étapes
 *
 * Le formulaire d'inscription faisait vingt champs d'un seul tenant. Il se
 * parcourt maintenant pas à pas, et **deux étapes seulement bloquent la
 * création** : l'entreprise a besoin d'un nom, et il lui faut un responsable
 * qui puisse se connecter. La situation fiscale et le domaine se complètent
 * aussi bien une fois dans l'application — et le garde `inscription.complete`
 * retient ventes et achats tant qu'ils manquent.
 */
class CreationDeCompteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    private function superadmin(): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    /** Le strict nécessaire pour créer une entreprise depuis le superadmin. */
    private function creationSuperadmin(array $extra = []): array
    {
        return array_merge([
            'nom'                          => 'Quincaillerie du Plateau',
            'email'                        => 'gerant@quincaillerie.ci',
            'gerant_password'              => 'un-mot-de-passe',
            'gerant_password_confirmation' => 'un-mot-de-passe',
            'quota_points_de_vente'        => 5,
            'plan_abonnement'              => 'Starter',
        ], $extra);
    }

    /** Le strict nécessaire pour s'inscrire soi-même. */
    private function inscription(array $extra = []): array
    {
        return array_merge([
            'nom_entreprise'        => 'Boutique du carrefour',
            'nom'                   => 'Kouadio',
            'prenom'                => 'Lewis',
            'email'                 => 'lewis@exemple.ci',
            'password'              => 'un-mot-de-passe',
            'password_confirmation' => 'un-mot-de-passe',
            'conditions'            => '1',
        ], $extra);
    }

    // ── Le responsable, sans qui rien n'est atteignable ──────────────

    public function test_le_superadmin_cree_le_compte_du_responsable(): void
    {
        // Le defaut principal : l'entreprise naissait sans personne pour s'y
        // connecter, et rien ne le signalait.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'), $this->creationSuperadmin([
                'gerant_nom' => 'Konan', 'gerant_prenom' => 'Yao',
            ]))
            ->assertSessionHasNoErrors();

        $entreprise = Entreprise::where('nom', 'Quincaillerie du Plateau')->firstOrFail();
        $responsable = Utilisateur::where('entreprise_id', $entreprise->id)->first();

        $this->assertNotNull($responsable, "L'entreprise doit naître avec un responsable.");
        $this->assertSame('admin', $responsable->role);
        $this->assertSame('gerant@quincaillerie.ci', $responsable->email);
    }

    public function test_le_responsable_cree_par_un_tiers_doit_changer_son_mot_de_passe(): void
    {
        // Le mot de passe vient du superadministrateur, non de son
        // proprietaire : il ne doit pas rester en l'etat.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'), $this->creationSuperadmin());

        $responsable = Utilisateur::where('email', 'gerant@quincaillerie.ci')->firstOrFail();

        $this->assertTrue((bool) $responsable->doit_changer_password);
    }

    public function test_le_responsable_peut_reellement_se_connecter(): void
    {
        // L'epreuve qui compte : le mot de passe pose doit ouvrir la porte.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'), $this->creationSuperadmin());

        auth()->logout();

        $this->post(route('connexion.traitement'), [
            'email'    => 'gerant@quincaillerie.ci',
            'password' => 'un-mot-de-passe',
        ]);

        $this->assertAuthenticated();
    }

    public function test_sans_mot_de_passe_aucune_entreprise_n_est_creee(): void
    {
        $donnees = $this->creationSuperadmin();
        unset($donnees['gerant_password'], $donnees['gerant_password_confirmation']);

        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'), $donnees)
            ->assertSessionHasErrors('gerant_password');

        $this->assertDatabaseMissing('entreprises', ['nom' => 'Quincaillerie du Plateau']);
    }

    // ── Ce qui peut attendre ─────────────────────────────────────────

    public function test_le_domaine_ne_bloque_plus_la_creation_par_le_superadmin(): void
    {
        // Il etait obligatoire ici, et facultatif a l'inscription : deux ecrans
        // qui creent la meme chose ne peuvent pas exiger des choses
        // differentes.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'), $this->creationSuperadmin())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('entreprises', ['nom' => 'Quincaillerie du Plateau']);
    }

    public function test_une_inscription_sans_les_etapes_facultatives_aboutit(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('entreprises', ['nom' => 'Boutique du carrefour']);
        $this->assertAuthenticated();
    }

    public function test_une_entreprise_incomplete_ne_facture_pas_encore(): void
    {
        // Facultatif ne veut pas dire oublie : le garde retient ventes et
        // achats tant que la situation fiscale manque.
        $this->post(route('inscription.traitement'), $this->inscription());

        $entreprise = Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();

        $this->assertFalse($entreprise->estInscriptionComplete());
    }

    public function test_les_deux_ecrans_suivent_les_memes_etapes(): void
    {
        // La definition vit en un seul endroit : ajouter un champ, c'est le
        // poser une fois.
        $etapes = EtapesCreation::toutes();

        $this->assertCount(4, $etapes);
        $this->assertCount(2, EtapesCreation::obligatoires());
        $this->assertSame(['entreprise', 'responsable'],
            array_column(EtapesCreation::obligatoires(), 'cle'));
        $this->assertSame(['fne', 'domaine'],
            array_column(EtapesCreation::facultatives(), 'cle'));
    }

    public function test_la_page_d_inscription_se_parcourt_en_trois_etapes(): void
    {
        // Elles étaient quatre. La dernière posait le domaine d'activité par
        // une grille de cases — l'ancien mécanisme, retiré des paramètres au
        // lot 13 : deux écrans posaient la même question sans se parler.
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        foreach ([1, 2, 3] as $rang) {
            $this->assertStringContainsString('data-pas="' . $rang . '"', $corps);
        }

        $this->assertStringNotContainsString('data-pas="4"', $corps);
        $this->assertStringContainsString('btn-suivant', $corps);
    }

    public function test_l_inscription_ne_coche_plus_de_secteur_d_activite(): void
    {
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringNotContainsString('secteurs_activite', $corps);
    }

    public function test_un_secteur_poste_a_la_main_n_est_pas_retenu(): void
    {
        // Simulation d'attaque : le champ n'est plus proposé, mais rien
        // n'empêche de le poster. Il ne doit rien écrire — sans quoi on
        // rouvrirait la double saisie par la porte de derrière.
        $this->post(route('inscription.traitement'), $this->inscription([
            'secteurs_activite' => ['Santé'],
        ]))->assertSessionHasNoErrors();

        $entreprise = Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();

        $this->assertEmpty($entreprise->secteur_activite ?? []);
    }

    public function test_la_premiere_etape_ne_demande_que_le_nom(): void
    {
        // La forme juridique, l'adresse électronique et le téléphone y
        // vivaient : quatre questions pour créer une entreprise dont une seule
        // est indispensable, et l'identifiant de connexion réclamé avant qu'on
        // sache qui se connecte.
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $etape1 = substr($corps,
            strpos($corps, 'data-pas="1"'),
            strpos($corps, 'data-pas="2"') - strpos($corps, 'data-pas="1"'));

        $this->assertStringContainsString('name="nom_entreprise"', $etape1);
        $this->assertStringNotContainsString('name="email"', $etape1);
        $this->assertStringNotContainsString('name="telephone"', $etape1);
        $this->assertStringNotContainsString('name="forme_juridique"', $etape1);
    }

    public function test_l_adresse_de_connexion_est_demandee_avec_le_responsable(): void
    {
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $etape2 = substr($corps,
            strpos($corps, 'data-pas="2"'),
            strpos($corps, 'data-pas="3"') - strpos($corps, 'data-pas="2"'));

        $this->assertStringContainsString('name="email"', $etape2);
        $this->assertStringContainsString('name="telephone"', $etape2);
    }

    public function test_le_telephone_personnel_ne_se_demande_plus(): void
    {
        // Il était validé et jamais enregistré — ni sur l'entreprise, ni sur
        // l'utilisateur. Un champ demandé puis jeté.
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringNotContainsString('telephone_gerant', $corps);
    }

    public function test_le_bloc_google_se_referme_apres_le_responsable(): void
    {
        // S'inscrire par Google remplace le formulaire : passé le responsable,
        // il n'y a plus rien à remplacer, et la bascule perdrait la saisie.
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringContainsString('id="bloc-google"', $corps);
        $this->assertStringContainsString('DERNIERE_AVEC_GOOGLE = 2', $corps);
    }

    // ── L'accès à l'espace FNE ───────────────────────────────────────

    public function test_l_acces_fne_fourni_a_l_inscription_est_retenu(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '1',
            'fne_ncc'            => '2603210A',
            'fne_mot_de_passe'   => 'le-mot-de-passe-dgi',
        ]))->assertSessionHasNoErrors();

        $entreprise = Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();
        $identifiants = FneCredential::where('entreprise_id', $entreprise->id)->firstOrFail();

        $this->assertSame('2603210A', $identifiants->ncc_associe);
        $this->assertSame('le-mot-de-passe-dgi', $identifiants->acces_mot_de_passe);
        $this->assertNotNull($identifiants->acces_fourni_at);
        $this->assertTrue((bool) $entreprise->possede_compte_fne);
    }

    public function test_le_mot_de_passe_fne_est_chiffre_au_repos(): void
    {
        // C'est un acces a un service de l'Etat, pas un reglage : il est
        // traite comme les cles d'API, inexploitable sans APP_KEY.
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '1',
            'fne_ncc'            => '2603210A',
            'fne_mot_de_passe'   => 'le-mot-de-passe-dgi',
        ]));

        $brut = DB::table('fne_credentials')->value('acces_mot_de_passe');

        $this->assertNotSame('le-mot-de-passe-dgi', $brut);
        $this->assertStringNotContainsString('le-mot-de-passe-dgi', (string) $brut);
    }

    public function test_le_mot_de_passe_fne_n_est_rendu_par_aucun_ecran(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '1',
            'fne_ncc'            => '2603210A',
            'fne_mot_de_passe'   => 'le-mot-de-passe-dgi',
        ]));

        auth()->logout();

        $this->actingAs($this->superadmin())
            ->get(route('superadmin.fne.index'))
            ->assertOk()
            ->assertDontSee('le-mot-de-passe-dgi');
    }

    public function test_une_entreprise_sans_compte_fne_ne_fournit_aucun_acces(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '0',
            'rccm'               => 'CI-ABJ-2026-B-00321',
            'ncc'                => '2603210A',
        ]))->assertSessionHasNoErrors();

        $entreprise = Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();

        $this->assertFalse((bool) $entreprise->possede_compte_fne);
        $this->assertSame('2603210A', $entreprise->ncc);
        $this->assertDatabaseCount('fne_credentials', 0);
    }

    // ── Le régime d'imposition, une information fiscale ─────────────

    public function test_aucune_information_fiscale_n_est_demandee_avant_la_question_fne(): void
    {
        // Le régime d'imposition était réclamé à la première étape, obligatoire,
        // avant même qu'on demande si l'entreprise a un compte FNE. C'est une
        // information fiscale : elle appartient à l'étape 3, et à celles qui
        // n'ont pas encore de compte.
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $questionFne = strpos($corps, 'name="possede_compte_fne"');
        $regime      = strpos($corps, 'name="regime_imposition"');

        $this->assertNotFalse($questionFne);
        $this->assertNotFalse($regime);
        $this->assertGreaterThan($questionFne, $regime,
            'Le régime est demandé avant la question du compte FNE.');
    }

    public function test_le_regime_n_est_plus_obligatoire_a_l_inscription(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription())
            ->assertSessionHasNoErrors();

        $this->assertNull(
            Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail()->regime_imposition
        );
    }

    public function test_les_deux_ecrans_proposent_les_memes_regimes(): void
    {
        // L'écran du superadministrateur proposait « Réel Normal », « Bénéfice
        // Forfaitaire », « Exonéré »… des intitulés qu'aucune autre partie du
        // logiciel ne reconnaît. Le régime n'est pas une étiquette : c'est lui
        // qui décide du code TVA transmis à la plateforme.
        $inscription = $this->get(route('inscription'))->assertOk()->getContent();
        $superadmin  = $this->actingAs($this->superadmin())
            ->get(route('superadmin.entreprises.creer'))->assertOk()->getContent();

        foreach (array_keys(Entreprise::REGIMES_IMPOSITION) as $code) {
            $this->assertStringContainsString('value="' . $code . '"', $inscription);
            $this->assertStringContainsString('value="' . $code . '"', $superadmin);
        }

        foreach (['Réel Normal', 'Bénéfice Forfaitaire', 'Micro-Entreprise'] as $fantaisie) {
            $this->assertStringNotContainsString($fantaisie, $superadmin);
        }
    }

    public function test_un_regime_hors_referentiel_n_est_jamais_enregistre(): void
    {
        // Le régime reste contrôlé, mais il ne refuse plus la création (15/10/2026) :
        // un intitulé hors référentiel est écarté et signalé, jamais enregistré.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.entreprises.creer.enregistrer'),
                $this->creationSuperadmin(['regime_imposition' => 'Réel Normal']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('avertissement', fn ($m) => str_contains($m, 'régime'));

        $this->assertNull(Entreprise::where('nom', 'Quincaillerie du Plateau')->value('regime_imposition'));
    }

    public function test_une_entreprise_sur_l_ancien_vocabulaire_reste_modifiable(): void
    {
        // Sans ce second terme, une entreprise enregistrée sous « Réel Normal »
        // ne pourrait plus enregistrer aucune modification, même sans toucher à
        // son régime. Même raisonnement que pour les domaines d'activité.
        $entreprise = Entreprise::create([
            'nom' => 'Ancienne maison', 'regime_imposition' => 'Réel Normal',
        ]);

        $this->assertContains('Réel Normal', Entreprise::regimesAcceptesPour($entreprise));
        $this->assertNotContains('Réel Normal', Entreprise::regimesAcceptesPour());
    }

    public function test_l_ecran_de_vente_annonce_le_code_tva_que_le_payload_transmet(): void
    {
        // Les écrans portaient leur propre copie des régimes d'exonération
        // légale : ['TEE', 'RNE'], quand la constante gelée du serveur retient
        // TEE, TCE et RME. Une entreprise en RME voyait donc s'afficher un code
        // d'exonération conventionnelle là où le payload partait en exonération
        // légale — et l'inverse pour une entreprise en RNE.
        foreach ([
            'app/Modules/Admin/Vues/ventes/nouvelle.blade.php',
            'app/Modules/Admin/Vues/ventes/modifier.blade.php',
            'app/Modules/Admin/Vues/produits/index.blade.php',
        ] as $vue) {
            $contenu = file_get_contents(base_path($vue));

            $this->assertStringNotContainsString(
                "REGIMES_EXONERATION_LEGALE = ['TEE', 'RNE']",
                $contenu,
                "{$vue} porte encore sa propre copie des régimes d'exonération."
            );
            $this->assertStringContainsString('Produit::REGIMES_EXONERATION_LEGALE', $contenu);
        }
    }

    public function test_un_ncc_mal_forme_n_empeche_pas_de_creer_l_entreprise(): void
    {
        // Sept caractères alphanumériques suivis d'une lettre. `26032100` a la
        // bonne longueur mais finit par un chiffre. L'étape 3 est facultative :
        // le NCC mal formé est écarté, l'entreprise créée, et on le dit
        // (retour de production du 15/10/2026 — l'inscription était refusée).
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '1',
            'fne_ncc'            => '2603',
            'fne_mot_de_passe'   => 'secret-fne',
        ]))->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.tableau_de_bord'))
            ->assertSessionHas('avertissement', fn ($m) => str_contains($m, 'NCC « 2603 »'));

        $entreprise = Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();
        $this->assertNull($entreprise->ncc);
        $this->assertAuthenticated();
    }

    public function test_le_ncc_du_bloc_non_choisi_ne_compte_pas(): void
    {
        $this->post(route('inscription.traitement'), $this->inscription([
            'possede_compte_fne' => '0',
            'fne_ncc'            => 'abc',
            'ncc'                => '2603210a',
        ]))->assertSessionHasNoErrors()->assertSessionMissing('avertissement');

        $this->assertSame('2603210A', Entreprise::where('nom', 'Boutique du carrefour')->value('ncc'));
    }
}
