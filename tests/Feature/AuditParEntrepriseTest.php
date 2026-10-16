<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\JournalAudit;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La traçabilité par entreprise (16/10/2026). */
class AuditParEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_action_est_rattachee_a_l_entreprise_de_son_auteur(): void
    {
        $e = Entreprise::create(['nom' => 'Trace SARL', 'modules_actifs' => ['principal', 'ventes', 'produits']]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'S', 'ville' => 'Abidjan', 'commune' => 'P']);
        $admin = Utilisateur::create([
            'nom' => 'K', 'prenom' => 'L', 'email' => 'trace@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id,
        ]);

        // Le trait de journalisation, utilisé partout, rattache l'action à
        // l'entreprise de son auteur.
        $this->actingAs($admin);
        $journaliseur = new class {
            use \App\Modules\Admin\Traits\JournaliseActions;
            public function faire(): void { $this->journaliser('creation_vente', 'Vente', 42); }
        };
        $journaliseur->faire();

        $entree = JournalAudit::where('entreprise_id', $e->id)->first();
        $this->assertNotNull($entree, 'L\'action porte l\'entreprise de son auteur.');
        $this->assertSame($admin->id, $entree->utilisateur_id);
        $this->assertSame('creation_vente', $entree->action);
    }

    public function test_l_ecran_d_audit_filtre_par_entreprise(): void
    {
        $e1 = Entreprise::create(['nom' => 'Alpha', 'modules_actifs' => ['principal']]);
        $e2 = Entreprise::create(['nom' => 'Beta', 'modules_actifs' => ['principal']]);
        JournalAudit::create(['entreprise_id' => $e1->id, 'action' => 'creation_vente', 'entite' => 'Vente', 'entite_id' => 1, 'adresse_ip' => '10.0.0.1']);
        JournalAudit::create(['entreprise_id' => $e2->id, 'action' => 'suppression_produit', 'entite' => 'Produit', 'entite_id' => 2, 'adresse_ip' => '10.0.0.2']);

        $su = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'A', 'email' => 'super-audit@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);

        $this->actingAs($su)->get(route('superadmin.tracabilite.audit'))->assertOk()
            ->assertSee('Création — Vente')->assertSee('Suppression — Produit');

        $this->get(route('superadmin.tracabilite.audit', ['entreprise' => $e1->id]))->assertOk()
            ->assertSee('Création — Vente')->assertDontSee('Suppression — Produit');
    }
}
