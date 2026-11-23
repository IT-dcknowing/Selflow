<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\DemandeDemo;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La page d'accueil 3D, la politique d'utilisation et « Voir une démo »
 * (23/11/2026).
 */
class AccueilEtDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_d_accueil_parle_de_normalisation_et_ne_garde_que_ce_qui_est_demande(): void
    {
        $html = $this->get(route('vitrine'))->assertOk()->getContent();

        $this->assertStringContainsString('La facture normalisée', $html);
        $this->assertStringContainsString('Voir une démo', $html);
        $this->assertStringContainsString('vendor/three/three.min.js', $html, 'La 3D est servie par le domaine : la CSP refuse les CDN.');
        $this->assertStringContainsString('prefers-reduced-motion', $html);
        $this->assertStringContainsString(route('vitrine.politique'), $html);

        // Retirés à la demande du propriétaire.
        $this->assertStringNotContainsString('Voir la documentation', $html);
        $this->assertStringNotContainsString('Ceux qui construisent', $html);
        $this->assertStringNotContainsString('Directeur général', $html);
    }

    public function test_le_texte_visible_n_a_ni_tiret_long_ni_souligne(): void
    {
        foreach ([route('vitrine'), route('vitrine.politique')] as $adresse) {
            $html = $this->get($adresse)->assertOk()->getContent();
            $texte = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $html));

            $this->assertStringNotContainsString('—', $texte, "Pas de tiret long sur {$adresse}.");
            $this->assertStringNotContainsString('_', $texte, "Pas de souligné sur {$adresse}.");
        }
    }

    public function test_une_demande_de_demo_est_enregistree(): void
    {
        $this->postJson(route('vitrine.demo'), [
            'email' => 'Patron@Entreprise.ci', 'whatsapp' => '07 67 13 19 93', 'profils' => ['chef_entreprise'],
        ])->assertOk();

        $demande = DemandeDemo::firstOrFail();
        $this->assertSame('patron@entreprise.ci', $demande->email);
        $this->assertSame('2250767131993', $demande->numeroWhatsappInternational());
    }

    public function test_une_demande_sans_case_cochee_est_refusee(): void
    {
        $r = $this->postJson(route('vitrine.demo'), ['email' => 'a@b.ci', 'whatsapp' => '0700000000', 'profils' => []]);
        $this->assertSame(422, $r->status());
        $this->assertSame(0, DemandeDemo::count());
    }

    public function test_le_superadmin_range_par_client_et_transmet_la_demo(): void
    {
        Mail::fake();
        DemandeDemo::create(['email' => 'patron@entreprise.ci', 'whatsapp' => '0767131993', 'profils' => ['chef_entreprise']]);
        $demande = DemandeDemo::create(['email' => 'patron@entreprise.ci', 'whatsapp' => '0767131993', 'profils' => ['chef_entreprise', 'employe']]);
        ParametrePlateforme::ecrire('demo_lien', 'https://exemple.ci/demo-selflow');

        $superadmin = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'sa@x.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'statut' => 'actif', 'habilitations' => ['gestion_vitrine']]);

        $this->actingAs($superadmin)->get(route('superadmin.demos.index'))
            ->assertOk()->assertSee('patron@entreprise.ci')->assertSee('2 demande(s)');

        $this->actingAs($superadmin)->post(route('superadmin.demos.transmettre', $demande))
            ->assertSessionHas('succes')
            ->assertSessionHas('ouvrir_whatsapp', fn ($lien) => str_starts_with($lien, 'https://wa.me/2250767131993?text='));

        $this->assertSame('transmise', $demande->fresh()->statut);
    }
}
