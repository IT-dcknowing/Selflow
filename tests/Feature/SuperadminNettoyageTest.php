<?php

namespace Tests\\Feature;

use App\\Modules\\Admin\\Modeles\\Entreprise;
use App\\Modules\\Admin\\Modeles\\PointDeVente;
use App\\Modules\\Admin\\Modeles\\Vente;
use App\\Modules\\Authentification\\Modeles\\Utilisateur;
use App\\Modules\\Authentification\\Regles\\Habilitations;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class SuperadminNettoyageTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;
    private Entreprise $entreprise;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = Utilisateur::create([
            'nom' => 'Admin', 'prenom' => 'Plateforme', 'email' => 'nettoyage@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
        $this->actingAs($this->superadmin);

        $this->entreprise = Entreprise::create(['nom' => 'Entreprise Alpha']);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Site principal', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
    }

    private function vente(array $extra = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'numero_facture' => 'FAC-' . uniqid(),
            'date_vente' => '2026-11-01',
            'montant_ht' => 1000,
            'montant_tva' => 180,
            'montant_ttc' => 1180,
            'mode_paiement' => 'Caisse',
            'statut' => 'Payé',
            'etape' => 'Facture',
            'archived' => false,
        ], $extra));
    }

    public function test_le_superadmin_peut_archiver_les_ventes_d_une_seule_entreprise(): void
    {
        $venteCible = $this->vente();
        $autreEntreprise = Entreprise::create(['nom' => 'Entreprise Beta']);
        $autreSite = PointDeVente::create([
            'entreprise_id' => $autreEntreprise->id,
            'nom' => 'Site Beta', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);
        $venteAutre = Vente::create([
            'point_de_vente_id' => $autreSite->id, 'numero_facture' => 'FAC-BETA',
            'date_vente' => '2026-11-01', 'montant_ht' => 1000, 'montant_tva' => 180,
            'montant_ttc' => 1180, 'mode_paiement' => 'Caisse', 'statut' => 'Payé',
            'etape' => 'Facture', 'archived' => false,
        ]);

        $this->post(route('superadmin.nettoyage.executer'), [
            'entreprise_id' => $this->entreprise->id,
            'cible' => 'ventes', 'etat' => 'actifs', 'action' => 'archiver',
            'confirmation' => $this->entreprise->nom, 'confirmer' => '1',
        ])->assertRedirect(route('superadmin.nettoyage.index'));

        $this->assertTrue((bool) $venteCible->fresh()->archived);
        $this->assertFalse((bool) $venteAutre->fresh()->archived);
    }

    public function test_la_purge_ne_supprime_pas_une_facture_certifiee(): void
    {
        $vente = $this->vente(['archived' => true, 'normalise' => true, 'numero_fne' => 'FNE-2026-001']);

        $this->post(route('superadmin.nettoyage.executer'), [
            'entreprise_id' => $this->entreprise->id,
            'cible' => 'ventes', 'etat' => 'archives', 'action' => 'purger',
            'confirmation' => $this->entreprise->nom, 'confirmer' => '1',
        ])->assertRedirect(route('superadmin.nettoyage.index'))
          ->assertSessionHas('succes');

        $this->assertDatabaseHas('ventes', ['id' => $vente->id, 'numero_fne' => 'FNE-2026-001']);
    }

    public function test_le_nom_exact_de_l_entreprise_est_requis_avant_toute_action(): void
    {
        $vente = $this->vente();

        $this->post(route('superadmin.nettoyage.executer'), [
            'entreprise_id' => $this->entreprise->id,
            'cible' => 'ventes', 'etat' => 'actifs', 'action' => 'archiver',
            'confirmation' => 'Entreprise Beta', 'confirmer' => '1',
        ])->assertStatus(422);

        $this->assertFalse((bool) $vente->fresh()->archived);
    }
}
