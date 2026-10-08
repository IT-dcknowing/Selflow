<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Referentiel\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le socle comptable et ce que le superadministrateur accorde au-delà
 * (propriétaire, 08/10/2026).
 */
class SocleComptableTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Socle SARL', 'regime_imposition' => 'RNI', 'adresse' => 'Plateau',
            'rccm' => 'CI-ABJ-2026-B-04040', 'ncc' => '2604040A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'comptabilite_activee' => true,
            'modules_actifs' => ['principal', 'ventes', 'comptabilite', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Super', 'prenom' => 'Admin', 'email' => 'super-socle@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    private function enregistrer(array $attributions): void
    {
        $this->actingAs($this->superadmin)->put(route('superadmin.entreprises.modifier.enregistrer', $this->entreprise), [
            'nom' => $this->entreprise->nom, 'quota_points_de_vente' => 3, 'plan_abonnement' => 'Starter',
            'secteur_activite' => [Categorie::domaines()[0]], 'modules_actifs' => ['principal', 'ventes', 'comptabilite'],
            'attributions_formulaire' => '1',
        ] + ($attributions ? ['attributions' => $attributions] : []))->assertSessionHasNoErrors();
    }

    public function test_la_carte_du_superadmin_garde_ce_qu_elle_ne_montre_pas(): void
    {
        $this->entreprise->forceFill(['attributions' => ['comptabilite', 'balance']])->save();

        $this->enregistrer(['grand_livre']);

        $accordees = $this->entreprise->fresh()->attributions;
        sort($accordees);
        $this->assertSame(['comptabilite', 'grand_livre'], $accordees);
    }

    public function test_tout_decocher_retire_bien_tout(): void
    {
        $this->entreprise->forceFill(['attributions' => ['comptabilite', 'balance', 'lettrage']])->save();

        $this->enregistrer([]);

        $this->assertSame(['comptabilite'], array_values($this->entreprise->fresh()->attributions));
    }

    public function test_le_caissier_garde_ses_encaissements_l_admin_les_recoit_du_superadmin(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'Caisse', 'prenom' => 'Awa', 'email' => 'awa-socle@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
            'habilitations' => ['tresorerie_encaissements'],
        ]);
        $admin = Utilisateur::create([
            'nom' => 'Admin', 'prenom' => 'Ali', 'email' => 'ali-socle@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($caissier)->get(route('caissier.tresorerie.encaissements'))->assertOk();

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('admin.tresorerie.encaissements'))->assertNotFound();

        $this->entreprise->forceFill(['attributions' => ['encaissements']])->save();
        $admin->unsetRelation('entreprise');

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('admin.tresorerie.encaissements'))->assertOk();
    }
}
