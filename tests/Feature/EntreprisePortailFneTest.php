<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Services\EntreprisePortailFne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un relevé du portail va à l'entreprise dont les accès ont servi.
 *
 * Le 23/11/2026, deux « DC-KNOWING CGA » (n° 10 et 29) portaient le NCC
 * 1864699A : les 29 factures relevées partaient à la première, et l'on
 * travaillait dans la seconde.
 */
class EntreprisePortailFneTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(string $nom, string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-' . uniqid(), 'ncc' => $ncc, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes'],
        ]);
    }

    public function test_le_releve_va_a_l_entreprise_dont_les_acces_sont_autorises(): void
    {
        $this->entreprise('DC-KNOWING TEST', '1864699A');
        $vraie = $this->entreprise('DC-KNOWING CGA', '1864699A');

        FneCredential::create([
            'entreprise_id' => $vraie->id, 'statut' => 'test', 'ncc_associe' => '1864699A',
            'acces_mot_de_passe' => 'secret', 'telechargement_actif' => true,
        ]);

        $this->assertSame($vraie->id, EntreprisePortailFne::pour('1864699A')?->id);
    }

    public function test_des_acces_non_autorises_ne_designent_personne(): void
    {
        $premiere = $this->entreprise('DC-KNOWING CGA', '1864699A');
        $autre = $this->entreprise('DC-KNOWING TEST', '1864699A');

        FneCredential::create([
            'entreprise_id' => $autre->id, 'statut' => 'test', 'ncc_associe' => '1864699A',
            'acces_mot_de_passe' => 'secret', 'telechargement_actif' => false,
        ]);

        $this->assertSame($premiere->id, EntreprisePortailFne::pour('1864699A')?->id, 'Sans accès autorisé, le repli reste la première par NCC.');
    }

    public function test_le_ncc_se_compare_sans_espaces_ni_casse(): void
    {
        $e = $this->entreprise('DC-KNOWING', '1864699 a');

        $this->assertSame($e->id, EntreprisePortailFne::pour('1864699A')?->id);
        $this->assertNull(EntreprisePortailFne::pour(''));
    }
}
