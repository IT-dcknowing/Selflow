<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 25/11/2026 : le NCC saisi par le superadmin apparaît sur la fiche de l'entreprise. */
class NccDesAccesFneTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_ncc_des_acces_passe_sur_une_fiche_vide_sans_ecraser_une_fiche_remplie(): void
    {
        $vide = Entreprise::create(['nom' => 'ALBARETTE']);
        FneCredential::create(['entreprise_id' => $vide->id, 'ncc_associe' => ' 2169728n ']);
        $this->assertSame('2169728N', $vide->fresh()->ncc);

        $remplie = Entreprise::create(['nom' => 'Déjà', 'ncc' => '1111111A']);
        FneCredential::create(['entreprise_id' => $remplie->id, 'ncc_associe' => '2222222B']);
        $this->assertSame('1111111A', $remplie->fresh()->ncc);
    }
}
