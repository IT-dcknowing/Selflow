<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\RapprochementAutomatiqueService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rapprochement se fait tout seul.
 *
 * `rapprochementPropose()` cherchait déjà l'achat correspondant à une facture
 * relevée au portail. Mais il **proposait** : il fallait cliquer « Rattacher »,
 * facture par facture.
 *
 * Le propriétaire l'a tranché le 25/09/2026 : « en réalité je veux aucune
 * intervention manuelle de l'utilisateur si possible ». Trois règles :
 *
 * | Ce qu'on trouve | Ce qu'on fait |
 * |---|---|
 * | Un achat, même fournisseur, même date, même TTC | rattaché, sans un mot |
 * | Un achat, mais le TTC diffère | rattaché **et l'écart est écrit** |
 * | Aucun achat en face | on ne touche à rien |
 *
 * Et le rapprochement joue **dans les deux sens** : le relevé arrive souvent
 * avant la saisie, mais pas toujours.
 */
class RapprochementSansGesteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Fournisseur $fournisseur;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'achats', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-rappro@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);
    }

    private function unAchat(float $ttc = 11800, string $numero = 'ACH-0001'): Achat
    {
        return Achat::create([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id'    => $this->fournisseur->id,
            'numero_facture'    => $numero,
            'date_achat'        => '2026-08-27',
            'etape'             => 'Facture',
            'montant_ht'        => round($ttc / 1.18, 2),
            'montant_tva'       => round($ttc - $ttc / 1.18, 2),
            'montant_ttc'       => $ttc,
            'mode_paiement'     => 'Caisse',
            'statut'            => 'Payé',
        ]);
    }

    private function uneFactureRecue(array $ajouts = []): PortailFneFactureRecue
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->entreprise->id,
            'login'             => '1864699A',
            'date_scraping'     => '2026-08-27',
            'type'              => ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => 'releve.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        return PortailFneFactureRecue::create(array_merge([
            'import_id'     => $import->id,
            'entreprise_id' => $this->entreprise->id,
            'login'         => '1864699A',
            'date_scraping' => '2026-08-27',
            'reference'     => 'B0000001X26000000042',
            'emetteur_nom'  => 'SOCIÉTÉ ALPHA',
            'emetteur_ncc'  => '1122334B',
            'date_facture'  => '2026-08-27',
            'montant_ht'    => 10000,
            'montant_tva'   => 1800,
            'montant_ttc'   => 11800,
        ], $ajouts));
    }

    // ══════════════ Les trois règles ══════════════

    public function test_une_correspondance_exacte_se_rattache_sans_un_mot(): void
    {
        $achat = $this->unAchat();
        $facture = $this->uneFactureRecue();

        RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);

        $facture->refresh();

        $this->assertSame($achat->id, $facture->achat_id);
        $this->assertSame(PortailFneFactureRecue::RAPPROCHEE, $facture->statut_rapprochement);
        // Le site vient de l'achat : c'est la même pièce.
        $this->assertSame($this->site->id, $facture->point_de_vente_id);
    }

    public function test_un_ecart_de_montant_se_rattache_mais_ne_se_tait_pas(): void
    {
        // Un montant qui diffère est soit une remise oubliée à la saisie, soit
        // une facture qui n'est pas celle qu'on croit. Ce n'est pas une
        // décision à prendre — c'est une anomalie à regarder.
        $this->unAchat(12000);
        $facture = $this->uneFactureRecue();

        RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);

        $facture->refresh();

        $this->assertNotNull($facture->achat_id);
        $this->assertStringContainsString('écart', $facture->note_rapprochement);
    }

    public function test_sans_achat_en_face_on_ne_touche_a_rien(): void
    {
        $facture = $this->uneFactureRecue();

        $bilan = RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);

        $facture->refresh();

        $this->assertNull($facture->achat_id);
        $this->assertSame(1, $bilan['sans_correspondance']);
        // **L'achat n'est pas créé.** Le propriétaire l'a précisé : cela ne
        // vaut que « dans le cas où on n'enregistre pas ». Tant qu'une
        // entreprise saisit ses achats, en fabriquer un second depuis le relevé
        // ferait le doublon que le rapprochement cherche à éviter.
        $this->assertSame(0, Achat::count());
    }

    // ══════════════ Ce que le geste automatique ne doit pas défaire ══════════════

    public function test_une_facture_ecartee_reste_ecartee(): void
    {
        // Un écartement est une décision humaine, et elle prime.
        $this->unAchat();
        $facture = $this->uneFactureRecue([
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
        ]);

        RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);

        $facture->refresh();

        $this->assertNull($facture->achat_id);
        $this->assertSame(PortailFneFactureRecue::ECARTEE, $facture->statut_rapprochement);
    }

    public function test_une_facture_deja_rattachee_ne_change_pas_d_achat(): void
    {
        $premier = $this->unAchat(11800, 'ACH-0001');
        $facture = $this->uneFactureRecue(['achat_id' => $premier->id]);

        $this->unAchat(11800, 'ACH-0002');

        RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);

        $this->assertSame($premier->id, $facture->refresh()->achat_id);
    }

    // ══════════════ Les deux sens ══════════════

    public function test_un_achat_saisi_apres_le_releve_retrouve_sa_facture(): void
    {
        // Le relevé arrive souvent **avant** la saisie : le fournisseur
        // certifie le jour même, le client enregistre le lendemain. La facture
        // attendait alors qu'on vienne la rattacher à la main.
        $facture = $this->uneFactureRecue();

        $this->assertNull($facture->achat_id);

        $achat = $this->unAchat();

        $this->assertSame($achat->id, $facture->refresh()->achat_id);
    }

    public function test_un_achat_d_une_autre_entreprise_ne_rapproche_rien(): void
    {
        $facture = $this->uneFactureRecue();

        $autre = Entreprise::create([
            'nom' => 'Voisine SARL', 'regime_imposition' => 'RSI', 'adresse' => 'Yopougon',
            'rccm' => 'CI-ABJ-2020-B-1', 'ncc' => '9999999X', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
        ]);
        $sonSite = PointDeVente::create([
            'entreprise_id' => $autre->id, 'nom' => 'Ailleurs', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);
        $sonFournisseur = Fournisseur::create([
            'entreprise_id' => $autre->id, 'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B',
        ]);

        Achat::create([
            'point_de_vente_id' => $sonSite->id,
            'fournisseur_id'    => $sonFournisseur->id,
            'numero_facture'    => 'ACH-VOISIN',
            'date_achat'        => '2026-08-27',
            'etape'             => 'Facture',
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $this->assertNull($facture->refresh()->achat_id);
    }
}
