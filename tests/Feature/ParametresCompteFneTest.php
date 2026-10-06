<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paramètres — le compte sur la plateforme FNE, section 10 du plan.
 *
 * Précisé par le propriétaire le 05/10/2026 : ne demander que ce qui manque.
 */
class ParametresCompteFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Riviera II',
            'rccm' => 'CI-ABJ-2018-B-31734', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'points_de_vente'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-fne@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function page(): string
    {
        return $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
    }

    public function test_je_n_en_ai_pas_encore_ne_liste_que_ce_qui_manque(): void
    {
        $this->entreprise->update(['possede_compte_fne' => false]);

        $page = $this->page();

        // Le NCC, le RCCM, l'adresse et le régime sont là : ils ne se
        // redemandent pas. Le centre des impôts manque : il est listé.
        $this->assertStringContainsString('Informations à compléter', $page);
        $this->assertStringContainsString('Centre des impôts', $page);
        $this->assertStringNotContainsString('NCC — Numéro de Compte Contribuable', $page);
        $this->assertStringNotContainsString('Registre du Commerce et du Crédit Mobilier, exigé', $page);
    }

    public function test_j_ai_un_compte_demande_ncc_et_mot_de_passe_s_ils_manquent(): void
    {
        $this->entreprise->update(['possede_compte_fne' => true]);

        $this->assertStringContainsString('name="fne_mot_de_passe"', $this->page());

        $this->put(route('admin.entreprise.parametres.enregistrer'), [
            'nom' => $this->entreprise->nom, 'possede_compte_fne' => '1',
            'fne_ncc' => '1864699a', 'fne_mot_de_passe' => 'secret-portail',
        ])->assertSessionHasNoErrors();

        $acces = FneCredential::where('entreprise_id', $this->entreprise->id)->first();
        $this->assertSame('1864699A', $acces->ncc_associe);
        $this->assertNotNull($acces->acces_fourni_at);

        // Connus : la question est réglée, les champs disparaissent. (Une
        // nouvelle requête recharge l'entreprise ; l'épreuve, qui garde le même
        // utilisateur en mémoire, doit le rafraîchir pour en faire autant.)
        $this->admin->refresh();
        $this->assertStringNotContainsString('name="fne_mot_de_passe"', $this->page());
    }

    public function test_le_logo_fne_est_pose_par_le_systeme(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('logo-FNE.png', $page);
        $this->assertStringNotContainsString('name="logo_fne"', $page);
    }
}
