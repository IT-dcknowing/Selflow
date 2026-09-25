<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Factures & Reçus émis/reçus » est un registre, pas un poste de travail.
 *
 * Il listait **tout** — normalisé ou non — et proposait un bouton
 * « Normaliser » sur ce qui ne l'était pas. Il faisait donc deux métiers : un
 * registre fiscal et un écran de travail. Les écrans des ventes et des achats
 * sont là pour le second, avec leurs filtres et leurs états ; celui-ci répond à
 * une seule question — **qu'est-ce que la plateforme détient à mon nom ?**
 *
 * Refonte demandée par le propriétaire le 25/09/2026, onglet par onglet.
 */
class RegistreFneNePorteQueLeCertifieTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'comptabilite', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-registre@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function vente(array $ajouts = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'numero_facture'    => 'VTE-' . random_int(1000, 9999),
            'date_vente'        => now()->toDateString(),
            'etape'             => 'Facture',
            'montant_ht'        => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'mode_paiement'     => 'Caisse', 'statut' => 'Payé',
        ], $ajouts));
    }

    private function achat(array $ajouts = []): Achat
    {
        return Achat::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id'    => $this->fournisseur->id,
            'numero_facture'    => 'ACH-' . random_int(1000, 9999),
            'date_achat'        => now()->toDateString(),
            'etape'             => 'Facture',
            'montant_ht'        => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'mode_paiement'     => 'Caisse', 'statut' => 'Payé',
        ], $ajouts));
    }

    /** @return array<int, array<string, mixed>> */
    private function lignes(string $flux, string $categorie): array
    {
        return $this->getJson(route('admin.fne.factures.donnees', [
            'flux' => $flux, 'categorie' => $categorie,
        ]))->assertOk()->json('documents');
    }

    // ══════════════ Les ventes ══════════════

    public function test_seules_les_ventes_certifiees_figurent_au_registre(): void
    {
        $certifiee = $this->vente(['normalise' => true, 'numero_fne' => '1864699A26000000001']);
        $brouillon = $this->vente(['numero_facture' => 'VTE-PAS-ENCORE']);

        $numeros = array_column($this->lignes('ventes', 'emis'), 'num_piece');

        $this->assertContains($certifiee->numero_facture, $numeros);
        $this->assertNotContains($brouillon->numero_facture, $numeros);
    }

    public function test_les_factures_emises_portent_aussi_les_recus(): void
    {
        // Le reçu n'est plus une pièce distincte depuis le lot 30 : c'est la
        // facture mise en page pour le ticket. L'onglet « Reçus (comptant) »
        // aurait montré la même pièce une seconde fois.
        $recu = $this->vente([
            'normalise' => true, 'numero_fne' => '1864699A26000000002',
            'type_piece' => Vente::TYPE_RECU,
        ]);

        $numeros = array_column($this->lignes('ventes', 'emis'), 'num_piece');

        $this->assertContains($recu->numero_facture, $numeros);
    }

    public function test_l_onglet_des_recus_comptant_a_disparu(): void
    {
        $page = $this->get(route('admin.fne.factures'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Reçus (comptant)', $page);
        $this->assertStringNotContainsString("data-cat=\"recu_recu\"", $page);
    }

    public function test_les_avoirs_clients_ont_leur_onglet(): void
    {
        $avoir = $this->vente([
            'normalise' => true, 'numero_fne' => '1864699A26000000003',
            'type_facture' => 'avoir',
        ]);
        $facture = $this->vente(['normalise' => true, 'numero_fne' => '1864699A26000000004']);

        $numeros = array_column($this->lignes('ventes', 'avoir_client'), 'num_piece');

        $this->assertContains($avoir->numero_facture, $numeros);
        $this->assertNotContains($facture->numero_facture, $numeros);
    }

    // ══════════════ Les achats ══════════════

    public function test_les_factures_recues_ne_viennent_que_du_releve(): void
    {
        /*
         * L'onglet listait aussi les factures d'achat saisies dans Selflow, qui
         * ne partent **jamais** à la DGI : elles s'y affichaient « non
         * normalisées » pour toujours, à côté de pièces que la plateforme
         * détient. Deux natures dans une même liste, dont une qui n'a rien à
         * y faire.
         */
        $saisie = $this->achat(['numero_facture' => 'ACH-SAISIE']);

        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A',
            'date_scraping' => now()->toDateString(), 'type' => ImportFacturesRecuesService::TYPE,
            'fichier_nom' => 'releve.json', 'fichier_empreinte' => hash('sha256', 'x'),
            'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        PortailFneFactureRecue::create([
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id,
            'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'reference' => 'B0000001X26000000042',
            'emetteur_nom' => 'SOCIÉTÉ ALPHA', 'emetteur_ncc' => '1122334B',
            'date_facture' => now()->toDateString(),
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
        ]);

        $lignes = $this->lignes('achats', 'recu');

        // La pièce du portail n'a pas de numéro interne — Selflow ne l'a pas
        // saisie : sa référence est celle de la DGI.
        $this->assertNotContains($saisie->numero_facture, array_column($lignes, 'num_piece'));
        $this->assertContains('B0000001X26000000042', array_column($lignes, 'num_fne'));
        $this->assertSame(['portail'], array_unique(array_column($lignes, 'origine')));
    }

    public function test_seuls_les_bapa_normalises_figurent(): void
    {
        $certifie = $this->achat([
            'numero_facture' => 'BA-CERTIFIE', 'type_facture' => 'bapa',
            'normalise' => true, 'numero_fne' => '1864699A26000000005',
        ]);
        $attente = $this->achat(['numero_facture' => 'BA-ATTENTE', 'type_facture' => 'bapa']);

        $numeros = array_column($this->lignes('achats', 'emis'), 'num_piece');

        $this->assertContains($certifie->numero_facture, $numeros);
        $this->assertNotContains($attente->numero_facture, $numeros);
    }

    public function test_seuls_les_avoirs_fournisseurs_normalises_figurent(): void
    {
        // L'avoir fournisseur ne s'établit plus depuis le lot 31 ; ceux qui
        // existent restent lisibles, et le registre ne porte que les certifiés.
        $certifie = $this->achat([
            'numero_facture' => 'AV-CERTIFIE', 'type_facture' => 'avoir',
            'normalise' => true, 'numero_fne' => '1864699A26000000006',
        ]);
        $autre = $this->achat(['numero_facture' => 'AV-ATTENTE', 'type_facture' => 'avoir']);

        $numeros = array_column($this->lignes('achats', 'avoir_fournisseur'), 'num_piece');

        $this->assertContains($certifie->numero_facture, $numeros);
        $this->assertNotContains($autre->numero_facture, $numeros);
    }

    public function test_une_facture_rapprochee_ne_disparait_pas_du_registre(): void
    {
        /*
         * Trou créé en restreignant l'onglet au seul relevé, et refermé
         * aussitôt : une facture reçue **rapprochée** d'un achat porte un
         * `achat_id` et quitte la liste des pièces non rattachées. Sans reprise
         * des achats que le portail certifie, elle disparaissait du registre
         * entier le jour où on la rangeait — alors que la DGI la détient
         * toujours.
         */
        $achat = $this->achat(['numero_facture' => 'ACH-RAPPROCHE']);

        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A',
            'date_scraping' => now()->toDateString(), 'type' => ImportFacturesRecuesService::TYPE,
            'fichier_nom' => 'releve2.json', 'fichier_empreinte' => hash('sha256', 'y'),
            'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        PortailFneFactureRecue::create([
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id,
            'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'reference' => 'B0000001X26000000099',
            'emetteur_nom' => 'SOCIÉTÉ ALPHA', 'emetteur_ncc' => '1122334B',
            'date_facture' => now()->toDateString(),
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'achat_id' => $achat->id,
            'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
        ]);

        $lignes = $this->lignes('achats', 'recu');

        // Elle y est, une fois, sous son achat et avec la référence de la DGI.
        $this->assertContains($achat->numero_facture, array_column($lignes, 'num_piece'));
        $this->assertContains('B0000001X26000000099', array_column($lignes, 'num_fne'));
        $this->assertCount(1, $lignes);
    }

    // ══════════════ Ce que l'écran ne fait plus ══════════════

    public function test_le_registre_ne_normalise_plus(): void
    {
        // Normaliser est un geste de travail : sa place est là où l'on
        // travaille la pièce, pas dans le registre de ce qui est déjà certifié.
        $page = $this->get(route('admin.fne.factures'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Normaliser auprès de la DGI', $page);
        $this->assertStringNotContainsString('doc.normaliser_url', $page);
    }

    public function test_les_colonnes_du_recu_lie_ont_disparu(): void
    {
        // Elles sont parties avec la pièce liée : la facture et son reçu sont
        // une seule pièce.
        $page = $this->get(route('admin.fne.factures'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<th>Reçu lié</th>', $page);
        $this->assertStringNotContainsString('<th>Fichier reçu</th>', $page);
        $this->assertStringContainsString('<th>Originale</th>', $page);
    }
}
