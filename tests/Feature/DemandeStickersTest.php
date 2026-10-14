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
        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), ['quantite' => 3, 'urgence' => 'normale'])
            ->assertSessionHasErrors('quantite');
        $this->assertSame(0, DemandeSticker::count());
    }

    public function test_le_superadmin_voit_la_demande_et_la_fait_avancer(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.stickers.enregistrer'), ['quantite' => 500, 'urgence' => 'normale']);
        $demande = DemandeSticker::sole();

        $super = $this->superadmin();
        $this->actingAs($super)->get(route('superadmin.stickers.index'))->assertOk()
            ->assertSee('Axenova')->assertSee('500');

        $this->actingAs($super)->put(route('superadmin.stickers.mettre_a_jour', $demande), [
            'statut' => 'livree', 'note_dcknowing' => 'Livrés sur votre espace FNE le 15/10',
        ])->assertSessionHasNoErrors();

        $this->assertSame('livree', $demande->fresh()->statut);
        $this->assertSame($super->id, $demande->fresh()->traitee_par);

        // L'entreprise voit l'avancement.
        $this->actingAs($this->admin)->get(route('admin.stickers.index'))
            ->assertSee('Livrée sur l')->assertSee('Livrés sur votre espace FNE le 15/10');
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
