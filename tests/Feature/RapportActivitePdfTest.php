<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le rapport d'activité s'exporte en PDF (23/11/2026). */
class RapportActivitePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_rapport_d_activite_sort_en_pdf(): void
    {
        $e = Entreprise::create(['nom' => 'DC-KNOWING CGA', 'ncc' => '1864699A', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'comptabilite', 'points_de_vente']]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $admin = Utilisateur::create(['nom' => 'K', 'prenom' => 'C', 'email' => 'k@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id]);
        Vente::create(['point_de_vente_id' => $site->id, 'utilisateur_id' => $admin->id, 'numero_facture' => 'VTE-1', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'montant_ht' => 40000, 'montant_tva' => 7200, 'montant_ttc' => 47200, 'etape' => 'Facture', 'statut' => 'Payé']);

        $this->actingAs($admin);
        $reponse = app(\App\Modules\Admin\Controleurs\RapportControleur::class)
            ->exporterActivite(request(), app(\App\Modules\Admin\Services\DocumentPdfService::class));

        $this->assertSame(200, $reponse->getStatusCode());
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        file_put_contents(storage_path('framework/testing/rapport-activite.pdf'), $reponse->getContent());

        // Le rapport d'un seul site : il ne garde que ce site.
        $autre = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'PDV-plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        Vente::create(['point_de_vente_id' => $autre->id, 'utilisateur_id' => $admin->id, 'numero_facture' => 'VTE-2', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'montant_ht' => 1000, 'montant_tva' => 180, 'montant_ttc' => 1180, 'etape' => 'Facture', 'statut' => 'Payé']);
        \Illuminate\Support\Facades\Cache::flush();

        $requete = \Illuminate\Http\Request::create('/admin/rapports/analyse-activite/pdf', 'GET', ['site' => $autre->id]);
        $vue = app(\App\Modules\Admin\Controleurs\RapportControleur::class)->analyseActivite($requete)->getData();
        $this->assertSame([$autre->id], $vue['pointsDeVente']->pluck('id')->all());
        $this->assertEqualsWithDelta(1180, (float) $vue['totalVentes'], 0.01);
    }
}
