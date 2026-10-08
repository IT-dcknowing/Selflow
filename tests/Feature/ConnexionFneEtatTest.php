<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\SynchronisationPortailFneService;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le compte FNE : son état, ce que l'écran redemande, ce que le portail
 * remplit, et les accès que voit le superadministrateur.
 *
 * Décisions du propriétaire du 08/10/2026.
 */
class ConnexionFneEtatTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Riviera II',
            'rccm' => 'CI-ABJ-2018-B-31734', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-etat-fne@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super-etat-fne@exemple.ci',
            'password' => bcrypt('code-du-super'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    private function etat(): string
    {
        return $this->entreprise->fresh()->etatConnexionFne()['code'];
    }

    private function acces(array $champs): FneCredential
    {
        return FneCredential::updateOrCreate(['entreprise_id' => $this->entreprise->id], $champs);
    }

    private function parametres(): string
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
    }

    private function releve(array $champs): PortailFneFiche
    {
        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'date_scraping' => '2026-10-08',
            'type' => PortailFneImport::TYPE_FICHE, 'fichier_nom' => '1864699A_20261008.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)), 'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        return PortailFneFiche::create(array_merge([
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id,
            'login' => '1864699A', 'date_scraping' => '2026-10-08',
        ], $champs));
    }

    // ── L'état ───────────────────────────────────────────────────────

    public function test_les_etats_de_j_ai_deja_un_compte(): void
    {
        $this->assertSame('a_choisir', $this->etat());

        $this->entreprise->update(['possede_compte_fne' => true]);
        $this->assertSame('acces_a_fournir', $this->etat());

        // Accès insérés : la connexion est « en cours », le temps de la clé.
        $this->acces(['ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'mdp-espace', 'acces_fourni_at' => now()]);
        $this->assertSame('en_cours', $this->etat());

        $this->acces(['cle_test' => 'cle-de-test', 'statut' => 'test']);
        $this->assertSame('test', $this->etat());

        $this->acces(['cle_reelle' => 'cle-de-prod', 'statut' => 'validee']);
        $this->assertSame('etablie', $this->etat());
    }

    public function test_les_etats_de_je_n_en_ai_pas_encore(): void
    {
        $this->entreprise->update(['possede_compte_fne' => false]);
        $this->assertSame('informations_a_completer', $this->etat(), 'Le centre des impôts, le gérant… manquent.');

        $this->entreprise->update([
            'centre_impots' => 'Cocody', 'telephone' => '0707070707', 'email' => 'contact@dck.ci',
            'gerant_nom' => 'Kouadio', 'gerant_prenom' => 'Lewis',
        ]);
        $this->assertSame('creation_en_cours', $this->etat());

        // Puis les mêmes étapes que pour un compte existant.
        $this->acces(['cle_test' => 'cle-de-test', 'statut' => 'test']);
        $this->assertSame('test', $this->etat());
    }

    // ── L'écran des paramètres ───────────────────────────────────────

    public function test_une_question_reglee_a_l_inscription_n_est_plus_posee(): void
    {
        $this->entreprise->update(['possede_compte_fne' => true]);
        $this->acces(['ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'mdp-espace', 'acces_fourni_at' => now()]);

        $page = $this->parametres();

        $this->assertStringContainsString('Connexion FNE en cours', $page);
        $this->assertStringNotContainsString('name="possede_compte_fne"', $page);
        $this->assertStringNotContainsString('name="fne_mot_de_passe"', $page);
    }

    public function test_sans_reponse_le_choix_ouvre_ses_deux_volets(): void
    {
        $page = $this->parametres();

        $this->assertStringContainsString('data-choix-fne="oui"', $page);
        $this->assertStringContainsString('data-volet-fne="oui"', $page);
        $this->assertStringContainsString('name="fne_mot_de_passe"', $page, 'Les deux champs, comme à l\'inscription.');
        $this->assertStringContainsString('data-volet-fne="non"', $page);
        $this->assertStringContainsString('Renseignez vos informations fiscales', $page);
        $this->assertStringContainsString('seront remplis automatiquement', $page);
    }

    public function test_avec_un_compte_les_champs_du_portail_sont_grises(): void
    {
        $this->assertStringNotContainsString('data-champ-portail readonly', $this->parametres());

        $this->entreprise->update(['possede_compte_fne' => true]);
        $this->admin->unsetRelation('entreprise');

        $page = $this->parametres();
        foreach (Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE as $champ) {
            $this->assertMatchesRegularExpression('/name="' . $champ . '" data-champ-portail\s+readonly/', $page, "« {$champ} » n'est pas grisé.");
        }
        // Le RCCM, que le portail ne rend pas, reste à saisir.
        $this->assertDoesNotMatchRegularExpression('/name="rccm"[^>]*readonly/', $page);
    }

    // ── La reprise du portail ────────────────────────────────────────

    public function test_le_releve_remplit_la_fiche_d_une_entreprise_qui_a_un_compte(): void
    {
        $this->entreprise->update(['possede_compte_fne' => true, 'timbre_quittance' => false]);
        $this->releve(['email' => 'portail@dck.ci', 'commune' => 'Cocody', 'idu' => 'IDU-42', 'timbre_quittance' => true]);

        $changements = SynchronisationPortailFneService::reprendre($this->entreprise->fresh());

        $e = $this->entreprise->fresh();
        $this->assertSame('portail@dck.ci', $e->email);
        $this->assertSame('Cocody', $e->commune);
        $this->assertSame('IDU-42', $e->idu);
        $this->assertSame('Riviera II', $e->adresse, 'Un champ que le portail n\'a pas rendu n\'efface rien.');
        $this->assertFalse((bool) $e->timbre_quittance, 'Le timbre reste au superadministrateur.');
        $this->assertSame(['email', 'commune', 'idu'], array_keys($changements));
    }

    public function test_sans_compte_le_releve_ne_touche_a_rien(): void
    {
        $this->entreprise->update(['possede_compte_fne' => false, 'email' => 'moi@dck.ci']);
        $this->releve(['email' => 'portail@dck.ci']);

        $this->assertSame([], SynchronisationPortailFneService::reprendre($this->entreprise->fresh()));
        $this->assertSame('moi@dck.ci', $this->entreprise->fresh()->email);
    }

    public function test_enregistrer_les_parametres_garde_les_valeurs_du_portail(): void
    {
        $this->entreprise->update(['possede_compte_fne' => true]);
        $this->releve(['email' => 'portail@dck.ci']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->put(route('admin.entreprise.parametres.enregistrer'), [
                'nom' => $this->entreprise->nom, 'email' => 'saisi-a-la-main@dck.ci',
            ])->assertSessionHasNoErrors();

        $this->assertSame('portail@dck.ci', $this->entreprise->fresh()->email);
    }

    // ── Le superadministrateur voit les accès ────────────────────────

    public function test_le_superadmin_voit_les_acces_derriere_son_mot_de_passe(): void
    {
        $this->entreprise->update(['possede_compte_fne' => true]);
        $this->acces(['ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'Mdp-Espace-Fne-2026', 'acces_fourni_at' => now()]);

        $this->actingAs($this->superadmin);

        // La page porte l'état et le bouton — jamais le mot de passe.
        $this->get(route('superadmin.entreprises'))->assertOk()
            ->assertSee('Connexion FNE en cours')
            ->assertSee('Voir les accès FNE')
            ->assertDontSee('Mdp-Espace-Fne-2026');

        $this->postJson(route('superadmin.fne.voir_acces', $this->entreprise), ['mot_de_passe' => 'mauvais'])
            ->assertForbidden();

        $this->postJson(route('superadmin.fne.voir_acces', $this->entreprise), ['mot_de_passe' => 'code-du-super'])
            ->assertOk()
            ->assertJson(['success' => true, 'ncc' => '1864699A', 'mot_de_passe' => 'Mdp-Espace-Fne-2026']);
    }

    public function test_une_entreprise_ne_voit_pas_les_acces(): void
    {
        $this->acces(['ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'Mdp-Espace-Fne-2026', 'acces_fourni_at' => now()]);

        $reponse = $this->actingAs($this->admin)
            ->postJson(route('superadmin.fne.voir_acces', $this->entreprise), ['mot_de_passe' => 'secret-de-test']);

        $this->assertContains($reponse->status(), [302, 403, 404]);
        $this->assertStringNotContainsString('Mdp-Espace-Fne-2026', $reponse->getContent());
    }

    public function test_le_mot_de_passe_ne_se_serialise_jamais(): void
    {
        $acces = $this->acces(['ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'Mdp-Espace-Fne-2026']);

        $this->assertArrayNotHasKey('acces_mot_de_passe', $acces->toArray());
        $this->assertStringNotContainsString('Mdp-Espace-Fne-2026', json_encode($this->entreprise->fresh()->load('fneCredential')));
    }
}
