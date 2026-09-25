<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trois natures d'achat, et une seule se normalise.
 *
 * Le propriétaire les a énoncées le 25/09/2026 :
 *
 *  - la **facture enregistrée** : celle qu'on saisit pour suivre une dépense.
 *    Elle ne part nulle part — c'est le fournisseur qui certifie la sienne ;
 *  - le **bordereau (BAPA)** : la seule pièce d'achat qu'un client de Selflow
 *    ait le droit de normaliser, parce que c'est lui qui l'établit, auprès d'un
 *    producteur qui n'émet rien ;
 *  - la **facture d'achat DGI** : celle que le fournisseur a certifiée et que
 *    le relevé du portail rapporte. Il n'y a rien à lui faire — seulement à la
 *    rapprocher.
 *
 * Les trois vivaient dans un seul tableau, où la colonne « Normalisé (DGI) »
 * mentait pour les deux tiers des lignes : une facture fournisseur ordinaire y
 * affichait « En cours » pour toujours, alors qu'elle ne part jamais.
 *
 * **L'avoir fournisseur a été retiré** : la DGI ne prévoit pas qu'un acheteur
 * établisse l'avoir de son fournisseur. Selflow offrait un document que rien ne
 * rendait opposable, et qui décrémentait pourtant les stocks.
 */
class TroisNaturesDAchatTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-achats@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function achat(string $typeFacture, string $numero): Achat
    {
        return Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'utilisateur_id'    => $this->admin->id,
            'fournisseur_id'    => $this->fournisseur->id,
            'numero_facture'    => $numero,
            'date_achat'        => now()->toDateString(),
            'etape'             => 'Facture',
            'type_facture'      => $typeFacture,
            'montant_ht'        => 10000,
            'montant_tva'       => 1800,
            'montant_ttc'       => 11800,
            'mode_paiement'     => 'Caisse',
            'statut'            => 'Payé',
        ]);
    }

    private function factureDuPortail(string $reference): PortailFneFactureRecue
    {
        // Une facture reçue appartient toujours à un relèvement : c'est lui qui
        // dit quand et d'où elle vient.
        $import = \App\Modules\Admin\Modeles\PortailFneImport::create([
            'entreprise_id'     => $this->entreprise->id,
            'login'             => '1864699A',
            'date_scraping'     => now()->toDateString(),
            'type'              => \App\Modules\Admin\Services\ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => '1864699A_achats.json',
            'fichier_empreinte' => hash('sha256', $reference),
            'statut'            => \App\Modules\Admin\Modeles\PortailFneImport::STATUT_IMPORTE,
        ]);

        return PortailFneFactureRecue::create([
            'import_id'     => $import->id,
            'entreprise_id' => $this->entreprise->id,
            'login'         => '1864699A',
            'date_scraping' => now()->toDateString(),
            'reference'     => $reference,
            'emetteur_nom'  => 'SOCIÉTÉ ALPHA',
            'emetteur_ncc'  => '1122334B',
            'date_facture'  => now()->toDateString(),
            'montant_ht'    => 10000,
            'montant_tva'   => 1800,
            'montant_ttc'   => 11800,
        ]);
    }

    private function section(string $section): string
    {
        return $this->get(route('admin.achats.factures', ['etape' => 'Facture', 'section' => $section]))
            ->assertOk()->getContent();
    }

    // ══════════════ Chaque section ne montre que ce qui la concerne ══════════════

    public function test_la_facture_enregistree_ne_cotoie_pas_le_bordereau(): void
    {
        $this->achat('normale', 'ACH-001');
        $this->achat('bapa', 'BA-001');

        $enregistrees = $this->section('enregistrees');
        $this->assertStringContainsString('ACH-001', $enregistrees);
        $this->assertStringNotContainsString('BA-001', $enregistrees);

        $bapa = $this->section('bapa');
        $this->assertStringContainsString('BA-001', $bapa);
        $this->assertStringNotContainsString('ACH-001', $bapa);
    }

    public function test_la_section_dgi_ne_porte_que_ce_que_le_portail_a_rapporte(): void
    {
        $achat = $this->achat('normale', 'ACH-001');
        $this->factureDuPortail('FNE-RECUE-001');

        $dgi = $this->section('dgi');

        $this->assertStringContainsString('FNE-RECUE-001', $dgi);

        // La facture enregistrée n'y a pas sa ligne. Son numéro peut y
        // apparaître — la proposition de rapprochement le nomme, et c'est
        // justement ce qu'on attend d'elle — mais pas son document.
        $this->assertStringNotContainsString(route('admin.achats.imprimer', $achat), $dgi);
    }

    public function test_une_section_inventee_retombe_sur_les_factures_enregistrees(): void
    {
        // Une valeur inventée dans l'adresse ne doit pas rendre une liste vide :
        // un écran vide se lit « il n'y a rien », ce qui serait faux.
        $this->achat('normale', 'ACH-001');

        $this->assertStringContainsString('ACH-001', $this->section('n-importe-quoi'));
    }

    public function test_un_achat_a_un_vendeur_sans_ncc_est_un_bordereau(): void
    {
        // **Deux chemins mènent au bordereau, et un seul se lit dans
        // `type_facture`.** `validerFacture()` envoie à la normalisation BAPA
        // toute facture d'un fournisseur sans NCC. Filtrer sur le seul type
        // aurait rangé ces pièces — le cas le plus courant — sous
        // « Factures enregistrées », où les colonnes DGI annoncent
        // « Aucune donnée » alors qu'elles sont normalisées.
        $producteur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'KONAN Kofi',
        ]);

        $achat = Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $producteur->id,
            'numero_facture'    => 'ACH-SANS-NCC',
            'date_achat'        => now()->toDateString(),
            'etape'             => 'Facture',
            'type_facture'      => 'normale',
            'montant_ht'        => 5000, 'montant_tva' => 0, 'montant_ttc' => 5000,
            'mode_paiement'     => 'Caisse', 'statut' => 'Payé',
        ]);

        $this->assertTrue($achat->fresh()->estBapa());
        $this->assertStringContainsString('ACH-SANS-NCC', $this->section('bapa'));
        $this->assertStringNotContainsString('ACH-SANS-NCC', $this->section('enregistrees'));
    }

    // ══════════════ Ce que chaque section dit de la DGI ══════════════

    public function test_la_facture_enregistree_n_annonce_aucune_donnee_dgi(): void
    {
        $this->achat('normale', 'ACH-001');

        $page = $this->section('enregistrees');

        // Elle affichait « En cours » avec une roue qui tourne, pour une pièce
        // qui ne partirait jamais.
        $this->assertStringContainsString('Aucune donnée', $page);
        $this->assertStringContainsString('Ces factures ne partent pas à la DGI', $page);
    }

    public function test_seul_le_bordereau_porte_le_bouton_normaliser(): void
    {
        $bapa = $this->achat('bapa', 'BA-001');
        $normale = $this->achat('normale', 'ACH-001');

        $this->assertStringContainsString(
            route('admin.achats.normaliser', $bapa), $this->section('bapa')
        );
        $this->assertStringNotContainsString(
            route('admin.achats.normaliser', $normale), $this->section('enregistrees')
        );
    }

    public function test_la_normalisation_par_lot_ne_vise_que_les_bordereaux(): void
    {
        $this->achat('normale', 'ACH-001');
        $this->achat('bapa', 'BA-001');

        $this->assertStringNotContainsString('Normaliser la sélection', $this->section('enregistrees'));
        $this->assertStringContainsString('Normaliser la sélection', $this->section('bapa'));
    }

    // ══════════════ L'avoir fournisseur a disparu ══════════════

    public function test_aucune_adresse_ne_cree_plus_d_avoir_fournisseur(): void
    {
        foreach (['admin.achats.avoir', 'admin.achats.avoir.creer_nouveau',
                  'admin.achats.factures.rechercher', 'admin.achats.factures.details'] as $nom) {
            $this->assertNull(
                app('router')->getRoutes()->getByName($nom),
                "La route {$nom} devrait avoir disparu : la DGI ne prévoit pas l'avoir du fournisseur."
            );
        }
    }

    public function test_le_menu_ne_propose_plus_l_avoir_fournisseur(): void
    {
        $page = $this->section('enregistrees');

        $this->assertStringNotContainsString('Avoirs fournisseurs', $page);
        // Les avoirs clients, eux, restent : c'est le vendeur qui les établit,
        // et la plateforme les certifie.
        $this->assertStringContainsString('Avoirs clients', $page);
    }

    public function test_un_avoir_deja_enregistre_n_est_pas_detruit(): void
    {
        // Retirer la fonctionnalité n'efface pas les pièces : elles sortent des
        // listes, elles restent en base.
        $avoir = $this->achat('avoir', 'AV-001');

        $this->assertStringNotContainsString('AV-001', $this->section('enregistrees'));
        $this->assertDatabaseHas('achats', ['id' => $avoir->id, 'type_facture' => 'avoir']);
    }

    // ══════════════ L'écran séparé des factures reçues ══════════════

    public function test_l_ecran_separe_des_factures_recues_a_disparu(): void
    {
        $this->assertNull(app('router')->getRoutes()->getByName('admin.achats.factures_recues'));

        // Mais les gestes du rapprochement, eux, restent : c'est la section
        // « Factures achat DGI » qui les appelle désormais.
        foreach (['rattacher', 'detacher', 'ecarter', 'affecter', 'reintegrer'] as $geste) {
            $this->assertNotNull(
                app('router')->getRoutes()->getByName("admin.achats.factures_recues.{$geste}"),
                "Le geste {$geste} doit rester atteignable."
            );
        }
    }

    public function test_la_section_dgi_porte_les_gestes_du_rapprochement(): void
    {
        $recue = $this->factureDuPortail('FNE-RECUE-001');

        $page = $this->section('dgi');

        $this->assertStringContainsString(route('admin.achats.factures_recues.ecarter', $recue), $page);
    }

    public function test_le_message_du_vide_ne_parle_plus_de_ligne_de_commande(): void
    {
        // « lancer node achats.js <NCC> » s'affichait à un commerçant qui n'a
        // pas de terminal, et à qui il n'appartient pas de lancer le scraper.
        $page = $this->section('dgi');

        $this->assertStringNotContainsString('achats.js', $page);
        $this->assertStringContainsString('Aucune facture d\'achat relevée au portail', $page);
    }

    // ══════════════ L'écran de saisie ══════════════

    public function test_la_nature_de_l_achat_se_choisit_en_tete(): void
    {
        $page = $this->get(route('admin.achats.nouveau'))->assertOk()->getContent();

        // Les deux boutons étaient en bas de la colonne, sous les totaux : on
        // découvrait après avoir tout saisi qu'on n'avait pas dit de quelle
        // pièce il s'agissait.
        $natureAvant = strpos($page, "Nature de l'achat");
        $typeApres = strpos($page, 'Type de document');

        $this->assertNotFalse($natureAvant);
        $this->assertNotFalse($typeApres);
        $this->assertLessThan($typeApres, $natureAvant);
    }

    public function test_la_mention_rne_du_bordereau_a_ete_retiree(): void
    {
        $page = $this->get(route('admin.achats.nouveau'))->assertOk()->getContent();

        // Un bordereau constate un achat auprès d'un producteur qui n'émet
        // rien : il n'existe aucun reçu normalisé auquel le rattacher.
        $this->assertStringNotContainsString('Mentions DGI (bordereau', $page);
        $this->assertStringNotContainsString('id="estRneCheckbox"', $page);
        $this->assertStringNotContainsString('id="numeroRneInput"', $page);
    }

    public function test_le_b2b_attend_qu_un_fournisseur_soit_choisi(): void
    {
        $page = $this->get(route('admin.achats.nouveau'))->assertOk()->getContent();

        $this->assertStringContainsString('id="blocB2b"', $page);
        // Masqué au départ : sans fournisseur, il n'y a personne à qui
        // adresser la demande.
        $this->assertMatchesRegularExpression('/id="blocB2b"[^>]*display:none/', $page);
        $this->assertStringContainsString('basculerB2b()', $page);
    }

    public function test_les_colonnes_est_rne_restent_en_base(): void
    {
        // L'écran cesse de les demander ; le payload FNE, lui, ne bouge pas.
        // La conformité est gelée : ce sont deux choses différentes.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('achats', 'est_rne'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('achats', 'numero_rne'));
    }
}
