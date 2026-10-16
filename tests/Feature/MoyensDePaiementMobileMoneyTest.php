<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\TrousseauEntrepriseService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moyens de paiement, retour du 16/10/2026 : pas de numéros de compte dans les
 * listes, le mobile money séparé des banques, et l'encaissement en mobile
 * money imputé à son compte — il partait en caisse.
 */
class MoyensDePaiementMobileMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique', 'regime_imposition' => 'RNI',
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        TrousseauEntrepriseService::doter($this->entreprise);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    public function test_banques_et_mobile_money_sont_distincts(): void
    {
        $banques = CodeJournal::banques()->where('entreprise_id', $this->entreprise->id)->pluck('code')->all();
        $mobiles = CodeJournal::mobileMoney()->where('entreprise_id', $this->entreprise->id)->pluck('code')->all();

        $this->assertNotEmpty($mobiles);
        $this->assertSame([], array_values(array_intersect($banques, $mobiles)));
        foreach ($mobiles as $code) {
            $this->assertNotContains($code, $banques);
        }
    }

    public function test_la_caisse_n_affiche_ni_compte_ni_mobile_dans_les_banques(): void
    {
        $page = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringNotContainsString(' - 521', $page, 'Aucun numéro de compte dans la liste des banques.');
        $debut = strpos($page, 'id="banqueSelect"');
        $liste = substr($page, $debut, strpos($page, '</select>', $debut) - $debut);
        $this->assertStringNotContainsString('Wave', $liste, 'Le mobile money n\'est pas une banque.');
        $this->assertStringContainsString('name="mobile_money_journal_id"', $page);
    }

    public function test_un_encaissement_mobile_money_va_sur_son_compte_et_non_en_caisse(): void
    {
        $wave = CodeJournal::mobileMoney()->where('entreprise_id', $this->entreprise->id)->where('code', 'WAVE')->firstOrFail();
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-1', 'nom' => 'Prestation',
            'type' => 'service', 'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 18,
        ]);

        $this->post(route('admin.ventes.enregistrer'), [
            'etape' => 'Facture', 'client_id' => Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client'])->id,
            'mode_paiement' => 'Mobile Money', 'mobile_money_journal_id' => $wave->id, 'montant_paye' => 11800,
            'articles' => [['produit_id' => $produit->id, 'quantite' => 1, 'unite' => 'u']],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_debit', $wave->compte)->exists(),
            'Le règlement est débité au compte du mobile money.');
        $this->assertFalse(EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_debit', '571000')->exists(),
            'Rien en caisse.');
    }

    public function test_ajouter_une_banque_sans_compte_et_un_mobile_money(): void
    {
        $this->postJson(route('admin.banques.creer'), ['code' => 'SGCI', 'intitule' => 'Société Générale'])
            ->assertOk()->assertJsonPath('banque.numero_compte', 'SGCI');
        $this->assertSame('521000', CodeJournal::where('code', 'SGCI')->value('compte'));

        $this->post(route('admin.tresorerie.creer_moyen_paiement'), ['type' => 'Mobile Money', 'code' => 'DJM', 'intitule' => 'Djamo'])
            ->assertSessionHasNoErrors();
        $djamo = CodeJournal::where('code', 'DJM')->firstOrFail();
        $this->assertTrue($djamo->estMobileMoney());
    }
}
