<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24/11/2026 : un même rayon (« Divers ») peut servir à deux métiers d'une entreprise. */
class CategoriesParSecteurTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_meme_nom_de_categorie_dans_deux_metiers(): void
    {
        $e = Entreprise::create(['nom' => 'Deux métiers']);
        Categorie::create(['entreprise_id' => $e->id, 'nom' => 'Divers', 'prefixe' => 'DIV', 'domaine_configuration' => 'Commerce', 'metier_configuration' => 'Épicerie']);
        Categorie::create(['entreprise_id' => $e->id, 'nom' => 'Divers', 'prefixe' => 'DIV2', 'domaine_configuration' => 'Commerce', 'metier_configuration' => 'Quincaillerie']);

        $this->assertSame(2, Categorie::where('entreprise_id', $e->id)->where('nom', 'Divers')->count());
    }
}
