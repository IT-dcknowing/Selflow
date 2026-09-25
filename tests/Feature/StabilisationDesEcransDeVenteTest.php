<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 30 — ce que les écrans de vente disaient de faux.
 *
 * Six constats du 25/09/2026, tous vérifiés ici :
 *
 * 1. **Des adresses mortes.** `Vente`, `Achat` et `BonLivraison` portent
 *    `IdentifiantOpaque` : leurs adresses se lient par `uuid`. Treize appels
 *    les construisaient avec `->id`, le numéro de ligne. « Imprimer le reçu »
 *    et « Créer la facture » ouvraient une page « Not Found » (404 —
 *    introuvable), et le tableau de bord FNE distribuait les mêmes adresses
 *    mortes sans que personne l'ait encore signalé.
 *
 * 2. **Une roue qui tournait pour rien.** La colonne « Normalisée (DGI) »
 *    affichait « En cours » sur toute pièce non certifiée, y compris celles
 *    que personne n'avait envoyées et que personne n'enverrait.
 *
 * 3. **Une file que personne ne servait.** `QUEUE_CONNECTION=database` : la
 *    normalisation automatique déposait son travail et rendait la main. Sans
 *    `queue:work`, il y restait.
 *
 * 4. **Deux cases pour une seule décision**, et une troisième — le bordereau —
 *    qui ne commandait rien.
 *
 * 5. **« Modifier » sur une facture établie.** Le bouton s'affichait tant que
 *    la pièce n'était pas normalisée, c'est-à-dire après qu'elle avait pu être
 *    remise au client.
 *
 * 6. **« Imprimer » là où l'on demandait « Télécharger ».**
 */
class StabilisationDesEcransDeVenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'DC-KNOWING CGA',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Riviera II',
            'rccm'              => 'CI-ABJ-2018-B-31734',
            'ncc'               => '1864699A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-stab@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function facture(array $ajouts = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->magasin->id,
            'utilisateur_id'    => $this->admin->id,
            'numero_facture'    => 'VTE-240926-012',
            'date_vente'        => now()->toDateString(),
            'etape'             => 'Facture',
            'type_piece'        => Vente::TYPE_FACTURE,
            'montant_ht'        => 10000,
            'montant_tva'       => 1800,
            'montant_ttc'       => 11800,
            'mode_paiement'     => 'Caisse',
            'statut'            => 'Payé',
        ], $ajouts));
    }

    // ══════════════ 1. Les adresses ══════════════

    public function test_la_page_de_la_piece_ne_porte_plus_le_numero_de_ligne(): void
    {
        $vente = $this->facture();

        $page = $this->get(route('admin.ventes.imprimer', $vente))->assertOk()->getContent();

        // Le bouton du reçu porte l'identifiant public, et non le numéro.
        $this->assertStringContainsString(
            route('admin.ventes.ticket', $vente), $page
        );
        $this->assertStringNotContainsString(
            "/admin/ventes/facture/{$vente->id}/ticket", $page
        );
    }

    public function test_le_numero_de_ligne_ne_designe_aucune_piece(): void
    {
        $vente = $this->facture();

        // Ce que l'utilisateur voyait : une page introuvable.
        $this->get("/admin/ventes/facture/{$vente->id}/ticket")->assertNotFound();

        // Ce qu'il doit voir : son reçu.
        $this->get(route('admin.ventes.ticket', $vente))->assertOk();
    }

    public function test_le_tableau_de_bord_fne_ne_distribue_plus_d_adresses_mortes(): void
    {
        // Le registre FNE ne porte que ce que la DGI a certifié depuis le
        // 25/09/2026 : une pièce non normalisée n'y figure plus, et c'est sur
        // une pièce certifiée qu'il faut vérifier les adresses qu'il distribue.
        $vente = $this->facture([
            'normalise'  => true,
            'numero_fne' => '1864699A26000000123',
        ]);

        $lignes = $this->getJson(route('admin.fne.factures.donnees') . '?source=selflow')
            ->assertOk()
            ->json();

        $json = json_encode($lignes, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString($vente->uuid, $json);
        $this->assertStringNotContainsString("/admin/ventes/facture/{$vente->id}\"", $json);
    }

    // ══════════════ 2. Ce que la colonne DGI annonce ══════════════

    public function test_la_piece_attend_quand_la_normalisation_est_manuelle(): void
    {
        $this->entreprise->update([
            'normalisation_auto_factures' => false,
            'normalisation_auto_recus'    => false,
        ]);
        $this->facture();

        $page = $this->get(route('admin.ventes.factures'))->assertOk()->getContent();

        // Rien ne partira tant que personne n'aura cliqué : le dire.
        $this->assertStringContainsString('En attente', $page);
        $this->assertStringContainsString('La normalisation automatique est décochée', $page);
    }

    public function test_la_piece_est_en_cours_quand_la_normalisation_est_automatique(): void
    {
        $this->entreprise->update([
            'normalisation_auto_factures' => true,
            'normalisation_auto_recus'    => true,
        ]);
        $this->facture();

        $page = $this->get(route('admin.ventes.factures'))->assertOk()->getContent();

        $this->assertStringContainsString('Déposée pour certification', $page);
        $this->assertStringNotContainsString('La normalisation automatique est décochée', $page);
    }

    // ══════════════ 3. Quelqu'un sert la file ══════════════

    public function test_le_planificateur_vide_la_file_d_attente(): void
    {
        // Sans cette tâche, `NormaliserFactureFne::dispatch()` dépose un
        // travail que personne ne prend jamais : la case « normaliser
        // automatiquement » était cochée, et rien ne partait.
        $planificateur = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('queue:work', $planificateur);
        $this->assertStringContainsString('--stop-when-empty', $planificateur);
        $this->assertStringContainsString('everyMinute', $planificateur);
    }

    // ══════════════ 5. Les colonnes du tableau ══════════════

    public function test_le_tableau_des_factures_a_ses_nouvelles_colonnes(): void
    {
        $this->facture();

        $page = $this->get(route('admin.ventes.factures'))->assertOk()->getContent();

        $this->assertStringContainsString('>Originale<', $page);
        $this->assertStringContainsString('>Fichier DGI<', $page);
        // Le reçu n'est plus une seconde pièce : la colonne n'a plus d'objet.
        $this->assertStringNotContainsString('>Reçu lié<', $page);
        $this->assertStringNotContainsString('>Fichier reçu<', $page);
    }

    public function test_une_facture_etablie_ne_se_modifie_plus(): void
    {
        $vente = $this->facture();

        $page = $this->get(route('admin.ventes.factures'))->assertOk()->getContent();

        // Le bouton s'affichait tant que la pièce n'était pas normalisée.
        $this->assertStringNotContainsString(route('admin.ventes.modifier', $vente), $page);
        // La seule action qui reste.
        $this->assertStringContainsString(route('admin.ventes.normaliser', $vente), $page);
    }

    // ══════════════ 6. Télécharger, et non imprimer ══════════════

    public function test_la_piece_et_le_recu_portent_un_bouton_telecharger(): void
    {
        $vente = $this->facture();

        $piece = $this->get(route('admin.ventes.imprimer', $vente))->assertOk()->getContent();
        $recu  = $this->get(route('admin.ventes.ticket', $vente))->assertOk()->getContent();

        $this->assertStringContainsString('telechargerFichier', $piece);
        $this->assertStringContainsString('telechargerRecu', $recu);

        // L'impression par le navigateur reste : elle n'a pas été retirée.
        $this->assertStringContainsString('telechargerPdf', $piece);
    }

    // ══════════════ Les tableaux de bord ══════════════

    public function test_les_tableaux_de_bord_ne_portent_plus_d_emoji(): void
    {
        foreach ([
            'app/Modules/Admin/Vues/tableau_de_bord.blade.php',
            'app/Modules/Admin/Vues/tableau_de_bord_general.blade.php',
        ] as $vue) {
            $contenu = file_get_contents(base_path($vue));

            $this->assertStringNotContainsString('👋', $contenu, $vue);
            $this->assertStringNotContainsString('🏢', $contenu, $vue);
        }
    }
}
