<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une colonne du modèle = un champ réellement saisi — chantier 11.4.
 *
 * Le prochain champ retiré d'un écran ne doit pas rester dans un modèle :
 * une colonne que rien ne lit fait saisir pour rien, et laisse croire que la
 * donnée est entrée.
 */
class ModelesImportRefletentLesFormulairesTest extends TestCase
{
    use RefreshDatabase;

    /** Le modèle, l'écran qui porte le formulaire de création. */
    private const CORRESPONDANCES = [
        'clients'      => 'admin.clients.index',
        'fournisseurs' => 'admin.fournisseurs.index',
    ];

    public static function lesModeles(): array
    {
        return array_map(fn ($m) => [$m], array_keys(self::CORRESPONDANCES));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesModeles')]
    public function test_chaque_colonne_du_modele_est_un_champ_du_formulaire(string $modele): void
    {
        $entreprise = Entreprise::create([
            'nom' => 'Boutique', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan', 'rccm' => 'CI-1',
            'ncc' => '1234567A', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Site', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'modeles@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id]);

        $csv = $this->get(route('admin.import.exemple', ['type' => $modele]))->assertOk()->getContent();
        $entetes = str_getcsv(strtok(ltrim($csv, "\xEF\xBB\xBF"), "\r\n"), ';');

        $formulaire = $this->get(route(self::CORRESPONDANCES[$modele]))->assertOk()->getContent();

        foreach ($entetes as $colonne) {
            $this->assertStringContainsString('name="' . $colonne . '"', $formulaire,
                "La colonne « {$colonne} » du modèle {$modele} ne correspond à aucun champ du formulaire.");
        }
    }
}
