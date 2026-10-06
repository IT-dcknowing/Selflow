<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les écrans d'entrée — section 9 du plan.
 */
class EcransDEntreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_icones_de_l_inscription_viennent_d_une_police_servie_par_l_application(): void
    {
        // 9.1 — les boutons « Retour », « Suivant », « Terminer sans remplir
        // la suite » montraient un carré vide : la police Tabler venait de
        // jsdelivr, que la politique de sécurité refusait dans `font-src`.
        $page = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringContainsString('vendor/fontawesome/css/all.min.css', $page);
        $this->assertStringContainsString('fa-solid fa-arrow-right', $page);
        $this->assertStringContainsString('fa-solid fa-forward-step', $page);
        $this->assertStringNotContainsString('tabler', $page);
    }

    public function test_les_deux_champs_de_mot_de_passe_portent_un_oeil_lie(): void
    {
        // 9.2 — on compare deux saisies : les deux doivent pouvoir se lire, et
        // ensemble.
        $page = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringContainsString('id="toggle-password"', $page);
        $this->assertStringContainsString('id="toggle-password-confirmation"', $page);
        $this->assertStringContainsString("['password', 'password_confirmation'].forEach", $page);
    }
}
