<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Referentiel\Compte;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Second retest d'octobre 2026 : les comptes créés par l'entreprise se
 * modifient et se suppriment ; « Gestion FNE » dit d'où viennent ses chiffres
 * et ne range plus les proformas parmi les pièces normalisées.
 */
class FiscaliteEtPlanComptableTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'TEE', 'adresse' => 'Cocody', 'rccm' => 'CI-ABJ-2026-B-1',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'comptabilite_activee' => true,
            'modules_actifs' => ['principal', 'ventes', 'comptabilite', 'points_de_vente'],
        ]);
        $this->entreprise->forceFill(['attributions' => ['comptabilite']])->save();
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);

        Compte::create(['numero' => '411000', 'racine' => '411', 'intitule' => 'Clients', 'classe' => 4, 'commun' => true]);
    }

    private function compte(string $numero, string $libelle = 'Compte'): PlanComptable
    {
        return PlanComptable::create(['entreprise_id' => $this->entreprise->id, 'numero' => $numero, 'libelle' => $libelle]);
    }

    // ── Plan comptable ───────────────────────────────────────────────

    public function test_seuls_les_comptes_crees_par_l_entreprise_offrent_modifier_et_supprimer(): void
    {
        $livre = $this->compte('411000', 'Clients');
        $cree = $this->compte('471900', 'Compte d\'attente maison');

        $this->assertFalse($livre->estCreeParLEntreprise());
        $this->assertTrue($cree->estCreeParLEntreprise());

        $html = $this->get(route('admin.comptabilite.plan_comptable', ['numero' => '4']))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.comptabilite.supprimer_compte_comptable', $cree), $html);
        $this->assertStringNotContainsString(route('admin.comptabilite.supprimer_compte_comptable', $livre), $html);
    }

    public function test_un_compte_cree_se_modifie_et_se_supprime(): void
    {
        $cree = $this->compte('471900', 'Attente');

        $this->put(route('admin.comptabilite.modifier_compte_comptable', $cree), ['numero' => '471950', 'libelle' => 'Attente — à régulariser'])
            ->assertSessionHasNoErrors()->assertSessionHas('succes');
        $this->assertSame('471950', $cree->fresh()->numero);

        $this->delete(route('admin.comptabilite.supprimer_compte_comptable', $cree))->assertSessionHas('succes');
        $this->assertNull($cree->fresh());
    }

    public function test_un_compte_employe_garde_son_numero_et_ne_se_supprime_pas(): void
    {
        $cree = $this->compte('521100', 'Banque maison');
        CodeJournal::create(['entreprise_id' => $this->entreprise->id, 'code' => 'BQ1', 'intitule' => 'Banque', 'type' => 'Banque', 'compte' => '521100']);

        $this->put(route('admin.comptabilite.modifier_compte_comptable', $cree), ['numero' => '521200', 'libelle' => 'Banque maison'])
            ->assertSessionHas('erreur');
        $this->assertSame('521100', $cree->fresh()->numero);

        // Le libellé, lui, change.
        $this->put(route('admin.comptabilite.modifier_compte_comptable', $cree), ['numero' => '521100', 'libelle' => 'Banque Atlantique'])
            ->assertSessionHas('succes');
        $this->assertSame('Banque Atlantique', $cree->fresh()->libelle);

        $this->delete(route('admin.comptabilite.supprimer_compte_comptable', $cree))->assertSessionHas('erreur');
        $this->assertNotNull($cree->fresh());
    }

    public function test_le_plan_livre_et_le_compte_d_une_autre_entreprise_restent_intouchables(): void
    {
        $livre = $this->compte('411000', 'Clients');
        $this->delete(route('admin.comptabilite.supprimer_compte_comptable', $livre))->assertSessionHas('erreur');
        $this->assertNotNull($livre->fresh());

        $autre = PlanComptable::create(['entreprise_id' => Entreprise::create(['nom' => 'Autre'])->id, 'numero' => '471900', 'libelle' => 'X']);
        $this->delete(route('admin.comptabilite.supprimer_compte_comptable', $autre))->assertNotFound();
    }

    // ── Gestion FNE ──────────────────────────────────────────────────

    public function test_gestion_fne_dit_pourquoi_ses_chiffres_sont_vides(): void
    {
        Vente::create(['point_de_vente_id' => $this->site->id, 'numero_facture' => 'VTE-1', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Caisse', 'montant_ht' => 1000, 'montant_tva' => 0, 'montant_ttc' => 1000, 'statut' => 'Payé', 'etape' => 'Facture']);

        $json = $this->getJson(route('admin.fne.gestion.donnees'))->assertOk()->json();

        $this->assertSame(0, $json['factures_certifiees_total']);
        $this->assertSame(1, $json['factures_non_certifiees']);

        $this->get(route('admin.fne.gestion'))->assertOk()
            ->assertSee('id="bandeau-certification"', false)
            ->assertSee('devis et commandes — non transmis');
    }
}
