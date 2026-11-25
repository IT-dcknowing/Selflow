<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 25/11/2026 : un relevé en échec passager se relance seul ; un mot de passe refusé, non. */
class RelanceDesEchecsDuRobotTest extends TestCase
{
    use RefreshDatabase;

    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function acces(string $ncc, ?string $statut): void
    {
        $e = Entreprise::create(['nom' => 'E ' . $ncc]);
        FneCredential::create(['entreprise_id' => $e->id, 'ncc_associe' => $ncc, 'acces_mot_de_passe' => 'secret', 'telechargement_actif' => true]);
        if ($statut !== null) {
            $f = base_path('SCRAPER-PORTAIL-FNE/etat-connexion/' . $ncc . '.json');
            @mkdir(dirname($f), 0775, true);
            file_put_contents($f, json_encode(['quand' => now()->subHours(2)->toIso8601String(), 'statut' => $statut, 'message' => '', 'portail' => '']));
            $this->fichiers[] = $f;
        }
    }

    public function test_seuls_les_echecs_passagers_sont_relances_une_fois_par_demi_heure(): void
    {
        $this->acces('9000001A', 'portail');
        $this->acces('9000002B', 'identifiants');
        $this->acces('9000003C', 'ok');
        $this->acces('9000004D', null); // jamais relevé

        $relances = ScraperPortailFneService::relancerLesEchecs();
        sort($relances);

        $this->assertSame(['9000001A', '9000004D'], $relances);
        $this->assertSame([], ScraperPortailFneService::relancerLesEchecs(), 'Pas deux relances dans la même demi-heure.');
    }
}
