<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Dès qu'une pièce est refusée, le scraper part au portail.
 *
 * Demandé par le propriétaire du projet le 31/08/2026 : *« dès qu'il y a une
 * erreur le scraper se met en action »*. Un refus dit que le portail déclare
 * autre chose que la pièce ; aller le lire est exactement ce qu'il faut faire.
 *
 * ## Pourquoi dans `FneRejet::consigner()` et pas dans un écran
 *
 * Le geste vivait dans `VenteControleur` : une facture normalisée depuis cet
 * écran-là déclenchait un relevé, **et rien d'autre**. Ni un bordereau d'achat,
 * ni une normalisation par lot, ni le tableau de bord FNE, ni le job en file.
 * Six chemins mènent à un refus et un seul réveillait le scraper.
 *
 * `consigner()` est le point par lequel ils passent tous. Ce qui s'y trouve est
 * vrai partout, et n'est à maintenir qu'une fois.
 *
 * ## Ce que ces épreuves fixent
 *
 * | Situation | Ce qui doit se produire |
 * |---|---|
 * | refus DGI sur une vente | le verrou est posé — un relevé est parti |
 * | refus DGI sur un achat | pareil : aucun écran n'est en cause |
 * | vingt refus du même lot | **un seul** relevé, pas vingt navigateurs |
 * | coupure réseau | rien : la DGI n'a pas vu la pièce, le portail n'a rien à en dire |
 * | scraper éteint | rien, et sans erreur |
 *
 * Le verrou de cache est ce qui s'observe : `lancerPourLogin()` détache un vrai
 * processus, qu'une épreuve n'a pas à ouvrir. Ce qui se vérifie ici est la
 * **décision** de lancer — le lancement lui-même est le même appel que le
 * planificateur utilise depuis le lot 22.
 */
class ReleveDesQuUnePieceEstRefuseeTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';

    private Entreprise $entreprise;
    private PointDeVente $magasin;

    /** La clé que `relancerApresRejet()` pose pour ne pas rouvrir dix navigateurs. */
    private const VERROU = 'portail_fne_releve_rejet_' . self::NCC;

    protected function setUp(): void
    {
        parent::setUp();

        // Le scraper est éteint dans la suite (`phpunit.xml`) : on l'allume ici,
        // et on pointe `node` sur un chemin qui n'existe pas. Le verrou se pose
        // avant le lancement — c'est lui qu'on observe —, et le processus
        // détaché échoue sans conséquence pour l'épreuve.
        config([
            'selflow.portail_fne.scraper.actif'  => true,
            'selflow.portail_fne.scraper.node'   => 'node-qui-n-existe-pas',
            'selflow.portail_fne.scraper.script' => 'script-qui-n-existe-pas.js',
        ]);

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => self::NCC, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'FACTURATION SIEGES',
            'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        Cache::forget(self::VERROU);
    }

    public function test_un_refus_de_la_dgi_sur_une_vente_declenche_un_releve(): void
    {
        $this->assertFalse(Cache::has(self::VERROU));

        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());

        $this->assertTrue(Cache::has(self::VERROU), 'Aucun relevé n\'a été déclenché.');
    }

    /**
     * Un bordereau d'achat refusé compte autant qu'une facture.
     *
     * C'est le chemin que l'ancien geste, posé dans `VenteControleur`, ne
     * voyait pas.
     */
    public function test_un_refus_sur_un_achat_declenche_aussi_un_releve(): void
    {
        FneRejet::consigner($this->unAchat(), $this->refusDgi());

        $this->assertTrue(Cache::has(self::VERROU));
    }

    /**
     * Vingt refus d'un même lot : un seul relevé.
     *
     * Sans verrou, une normalisation par lot ouvrirait vingt navigateurs sur le
     * portail de la DGI — vingt connexions avec le mot de passe du client, pour
     * lire vingt fois la même chose.
     */
    public function test_un_lot_de_refus_n_ouvre_qu_un_seul_releve(): void
    {
        $lances = 0;

        for ($i = 1; $i <= 20; $i++) {
            Cache::forget('sonde');

            $avant = Cache::has(self::VERROU);
            FneRejet::consigner($this->uneVente(sprintf('FA-%04d', $i)), $this->refusDgi());

            if (!$avant && Cache::has(self::VERROU)) {
                $lances++;
            }
        }

        $this->assertSame(20, FneRejet::count(), 'Les vingt refus doivent tous être consignés.');
        $this->assertSame(1, $lances, 'Un seul relevé pour tout le lot.');
    }

    /**
     * Une coupure réseau n'appelle aucun relevé.
     *
     * La DGI n'a jamais vu la pièce : le portail n'a rien à en dire, et une
     * session sur l'espace FNE se paie d'une connexion avec le mot de passe du
     * client. C'est la même distinction qui empêche déjà d'ouvrir une demande.
     */
    public function test_une_coupure_reseau_ne_reveille_pas_le_scraper(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0001'), [
            'success' => false,
            // Le libellé que `FneService` produit quand le transport échoue :
            // c'est lui que `classer()` reconnaît, pas une phrase inventee.
            'message' => "Exception lors de l'appel API FNE : Connection refused",
            'errors'  => [],
        ]);

        $this->assertSame(FneRejet::CAUSE_RESEAU, $rejet->cause);
        $this->assertFalse(Cache::has(self::VERROU));
    }

    public function test_scraper_eteint_le_refus_se_consigne_quand_meme(): void
    {
        config(['selflow.portail_fne.scraper.actif' => false]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());

        // Le rejet compte, le relevé est un confort : l'un ne doit pas
        // dépendre de l'autre.
        $this->assertNotNull($rejet);
        $this->assertSame(FneRejet::CAUSE_DGI, $rejet->cause);
        $this->assertFalse(Cache::has(self::VERROU));
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function refusDgi(): array
    {
        return [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : Invalid point of sale.',
            'errors'  => ['api_error' => json_encode([
                'errors' => ['pointOfSale' => ['invalid' => 'Point of sale is invalid']],
            ])],
        ];
    }

    private function uneVente(string $numero): Vente
    {
        return Vente::create([
            'point_de_vente_id' => $this->magasin->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);
    }

    private function unAchat(): Achat
    {
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => 'Coopérative agricole',
        ]);

        return Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'ACH-0001',
            'date_achat'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 50000, 'montant_tva' => 0, 'montant_ttc' => 50000,
        ]);
    }
}
