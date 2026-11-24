<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 24/11/2026 (urgence) : une facture de 3 000 F affichait 551 704 F
 * « réglés ». La trésorerie n'a pas d'entreprise : le « déjà payé » sommait
 * tous les encaissements portant le même numéro de facture, toutes
 * entreprises confondues.
 */
class DejaPayeParEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_deja_paye_ne_compte_que_l_entreprise_de_la_facture(): void
    {
        $mienne = Entreprise::create(['nom' => 'O\'SUPER PRIX']);
        $autre = Entreprise::create(['nom' => 'Autre']);
        $monSite = PointDeVente::create(['entreprise_id' => $mienne->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Abobo']);
        $sonSite = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create(['nom' => 'A', 'prenom' => 'B', 'email' => 'deja-paye@exemple.ci', 'password' => bcrypt('x-secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $mienne->id, 'point_de_vente_id' => $monSite->id]);

        $piece = ['numero_facture' => 'FAC-2026-0001', 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces',
            'montant_ht' => 3000, 'montant_tva' => 0, 'montant_ttc' => 3000, 'statut' => 'Payé', 'etape' => 'Facture'];
        $vente = Vente::create($piece + ['point_de_vente_id' => $monSite->id]);
        Vente::create(array_merge($piece, ['point_de_vente_id' => $sonSite->id, 'montant_ht' => 548704, 'montant_ttc' => 548704]));

        foreach ([[$monSite, 3000], [$sonSite, 548704]] as [$site, $montant]) {
            TresorerieJournal::withoutGlobalScopes()->forceCreate([
                'point_de_vente_id' => $site->id, 'date_operation' => now()->toDateString(), 'type_operation' => 'Encaissement',
                'libelle' => 'Règlement', 'mode_paiement' => 'Espèces', 'montant_entree' => $montant, 'montant_sortie' => 0,
                'solde_resultat' => 0, 'reference_document' => 'FAC-2026-0001',
            ]);
        }

        $this->assertSame(3000.0, TresorerieJournal::encaissePour($vente));
        $this->assertSame(3000.0, (float) $vente->fresh()->montant_paye);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $monSite->id])
            ->get(route('admin.ventes.imprimer', $vente))->assertOk()
            ->assertSee('deja_paye: 3000', false);
    }
}
