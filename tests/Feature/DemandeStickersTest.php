<?php

namespace Tests\Feature;

use App\Mail\DemandeStickersRecue;
use App\Modules\Admin\Modeles\DemandeSticker;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * « Remettre la partie d'achat des stickers » — retest d'octobre 2026.
 *
 * Pas pour faire croire que Selflow vend les vignettes : DC-Knowing les
 * achète à la place de l'entreprise. Le choix est gardé, envoyé par courriel,
 * et suivi par le superadministrateur.
 */
class DemandeStickersTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['selflow.adresse_demandes_stickers' => 'stickers@dc-knowing.ci']);

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'ncc' => '1864699A', 'fne_sticker_balance' => 12,
            'modules_actifs' => ['principal', 'ventes', 'points_de_vente'],
        ]);
        PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function superadmin(): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    public function test_l_ecran_dit_que_dc_knowing_achete_et_que_selflow_ne_vend_pas(): void
    {
        $this->actingAs($this->admin)->get(route('admin.stickers.index'))->assertOk()
            ->assertSee('DC-Knowing les achète pour vous')
            ->assertSee('Selflow ne les vend pas');
    }

    public function test_la_demande_est_gardee_et_envoyee_a_dc_knowing_et_au_demandeur(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), [
            'quantite' => 1000, 'urgence' => 'urgente', 'contact_telephone' => '0707070707',
            'operateur_paiement' => 'WAVE', 'reference_paiement' => 'T-2026-ABC123',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.stickers.index'));

        $demande = DemandeSticker::sole();
        $this->assertSame(1000, $demande->quantite);
        $this->assertSame('recue', $demande->statut);
        $this->assertEqualsWithDelta(1000 * (float) config('selflow.sticker_prix_unitaire'), (float) $demande->montant_estime, 0.01);
        $this->assertTrue($demande->courriel_envoye);

        Mail::assertSent(DemandeStickersRecue::class, fn ($m) => $m->hasTo('stickers@dc-knowing.ci') && $m->pourDcKnowing);
        Mail::assertSent(DemandeStickersRecue::class, fn ($m) => $m->hasTo('eve@axenova.ci') && ! $m->pourDcKnowing);
    }

    public function test_une_quantite_trop_petite_est_refusee(): void
    {
        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), ['quantite' => 3, 'urgence' => 'normale', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'T-1234'])
            ->assertSessionHasErrors('quantite');
        $this->assertSame(0, DemandeSticker::count());
    }

    private function demander(): DemandeSticker
    {
        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), [
            'quantite' => 500, 'urgence' => 'urgente', 'operateur_paiement' => 'MTN', 'reference_paiement' => 'MP251016.1200.A1',
        ])->assertSessionHasNoErrors();

        return DemandeSticker::sole();
    }

    public function test_sans_reference_de_paiement_la_demande_est_refusee(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), ['quantite' => 500, 'urgence' => 'normale'])
            ->assertSessionHasErrors(['operateur_paiement', 'reference_paiement']);
        $this->assertSame(0, DemandeSticker::count());
    }

    public function test_le_superadmin_voit_la_reference_la_priorite_et_fait_avancer_la_demande(): void
    {
        Mail::fake();
        $demande = $this->demander();
        $super = $this->superadmin();

        $this->actingAs($super)->get(route('superadmin.stickers.index'))->assertOk()
            ->assertSee('Axenova')->assertSee('MP251016.1200.A1')->assertSee('Urgente')
            ->assertSee('En cours')->assertSee('Terminé')->assertSee('Échec');

        $this->actingAs($super)->put(route('superadmin.stickers.mettre_a_jour', $demande), ['action' => 'en_cours'])->assertSessionHasNoErrors();
        $this->assertSame('en_cours', $demande->fresh()->statut);
        $this->assertNotNull($demande->fresh()->en_cours_le);

        $this->actingAs($super)->put(route('superadmin.stickers.mettre_a_jour', $demande), [
            'action' => 'terminer', 'stock_communique' => 512, 'solde_communique' => 25000,
        ])->assertSessionHasNoErrors();

        $demande->refresh();
        $this->assertSame('achetee', $demande->statut);
        $this->assertSame(512, (int) $this->entreprise->fresh()->fne_sticker_balance);
        $this->assertSame($super->id, $demande->traitee_par);

        // L'entreprise voit chaque étape et son heure, et l'avis « stock renouvelé ».
        $page = $this->actingAs($this->admin)->get(route('admin.stickers.index'))->getContent();
        $this->assertStringContainsString('Achat en cours', $page);
        $this->assertStringContainsString('Achetée auprès de la DGI', $page);
        $this->assertStringContainsString($demande->achetee_le->format('d/m/Y H:i'), $page);
        $this->assertStringContainsString('Stock de stickers renouvelé', $page);
        $this->assertStringContainsString('Stock disponible', $page);

        $this->actingAs($this->admin)->post(route('admin.stickers.avis_vu', $demande));
        $this->assertStringNotContainsString('Stock de stickers renouvelé', $this->actingAs($this->admin)->get(route('admin.stickers.index'))->getContent());

        // Le compteur du menu ne compte plus la demande terminée.
        $this->assertSame(0, DemandeSticker::whereIn('statut', DemandeSticker::A_TRAITER)->count());
    }

    public function test_sans_stock_communique_l_avis_dit_que_tout_sera_a_jour_apres_normalisation(): void
    {
        Mail::fake();
        $demande = $this->demander();
        $this->actingAs($this->superadmin())->put(route('superadmin.stickers.mettre_a_jour', $demande), ['action' => 'terminer']);

        $this->actingAs($this->admin)->get(route('admin.stickers.index'))
            ->assertSee('vous pouvez normaliser')->assertSee('après votre première normalisation');
        $this->assertSame(12, (int) $this->entreprise->fresh()->fne_sticker_balance, 'Le solde n\'est pas touché.');
    }

    public function test_l_echec_exige_un_motif_visible_par_l_entreprise(): void
    {
        Mail::fake();
        $demande = $this->demander();
        $super = $this->superadmin();

        $this->actingAs($super)->put(route('superadmin.stickers.mettre_a_jour', $demande), ['action' => 'echec'])
            ->assertSessionHasErrors('motif_echec');
        $this->actingAs($super)->put(route('superadmin.stickers.mettre_a_jour', $demande), ['action' => 'echec', 'motif_echec' => 'Référence introuvable'])
            ->assertSessionHasNoErrors();

        $this->assertSame('echec', $demande->fresh()->statut);
        $this->actingAs($this->admin)->get(route('admin.stickers.index'))->assertSee('Référence introuvable')->assertSee('Échec');
    }

    public function test_une_entreprise_ne_touche_pas_la_demande_d_une_autre(): void
    {
        Mail::fake();
        $autre = Entreprise::create(['nom' => 'Autre', 'modules_actifs' => ['principal', 'ventes']]);
        $demande = DemandeSticker::create([
            'entreprise_id' => $autre->id, 'quantite' => 100, 'prix_unitaire' => 20, 'montant_estime' => 2000, 'statut' => 'recue',
        ]);

        $this->actingAs($this->admin)->post(route('admin.stickers.annuler', $demande))->assertNotFound();
        $this->assertSame('recue', $demande->fresh()->statut);
    }

    public function test_un_admin_d_entreprise_n_ouvre_pas_la_page_du_superadmin(): void
    {
        $this->actingAs($this->admin)->get(route('superadmin.stickers.index'))->assertForbidden();
    }
}
