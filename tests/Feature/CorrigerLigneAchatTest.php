<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24/11/2026 : 154 100 F saisis au lieu de 1 541 F sur une ligne d'un BCF. */
class CorrigerLigneAchatTest extends TestCase
{
    use RefreshDatabase;

    public function test_seule_la_ligne_visee_change_et_les_totaux_suivent(): void
    {
        $e = Entreprise::create(['nom' => "O'SUPER PRIX"]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => "O'Superprix yakro", 'ville' => 'Yamoussoukro', 'commune' => 'Yamoussoukro']);
        $f = Fournisseur::create(['entreprise_id' => $e->id, 'nom' => 'ARMAND HOUSS']);
        $achat = Achat::create(['point_de_vente_id' => $site->id, 'fournisseur_id' => $f->id, 'numero_facture' => 'BCF-24-11-2026-0001',
            'etape' => 'Bon de commande', 'statut' => 'En attente de confirmation', 'mode_paiement' => 'Crédit',
            'montant_ht' => 18492000 + 262500, 'montant_tva' => 0, 'montant_ttc' => 18492000 + 262500, 'date_achat' => now()->toDateString()]);
        $fausse = AchatDetail::create(['achat_id' => $achat->id, 'libelle_virtuel' => 'Article', 'quantite' => 120, 'prix_unitaire' => 154100, 'montant_tva' => 0, 'montant_ttc' => 18492000]);
        $bonne = AchatDetail::create(['achat_id' => $achat->id, 'libelle_virtuel' => 'GABI 22kg', 'quantite' => 30, 'prix_unitaire' => 8750, 'montant_tva' => 0, 'montant_ttc' => 262500]);

        $args = ['numero' => 'BCF-24-11-2026-0001', '--entreprise' => 'super prix', '--prix-actuel' => 154100, '--nouveau-prix' => 1541];

        $this->artisan('selflow:corriger-ligne-achat', $args)->assertExitCode(0);
        $this->assertEquals(154100, (float) $fausse->fresh()->prix_unitaire, 'Sans --appliquer, rien ne change.');

        $this->artisan('selflow:corriger-ligne-achat', $args + ['--appliquer' => true])->assertExitCode(0);

        $this->assertEquals(1541, (float) $fausse->fresh()->prix_unitaire);
        $this->assertEquals(184920, (float) $fausse->fresh()->montant_ttc);
        $this->assertEquals(8750, (float) $bonne->fresh()->prix_unitaire);
        $this->assertEquals(184920 + 262500, (float) $achat->fresh()->montant_ttc);
    }
}
