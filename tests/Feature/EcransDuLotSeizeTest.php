<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quatre écrans relus par le propriétaire.
 *
 * **Les adresses portaient le numéro de ligne.** Trois écrans construisaient
 * une adresse en collant `$modele->id` à un chemin — `/admin/produits/156/photo`,
 * `/admin/clients/52`, `/admin/fournisseurs/17`. Or les adresses de
 * l'application portent l'`uuid` depuis le lot 8.3, précisément pour ne pas
 * publier le nombre de pièces de la plateforme. Le lien de route ne résolvait
 * donc aucun modèle : **changer la photo d'un article, modifier un client,
 * modifier un fournisseur tombaient en 404 (Not Found — introuvable)**, sans
 * un mot à l'écran.
 *
 * **Le compte comptable était exigé sur les cinq types de journal.** Il n'a de
 * sens que sur un journal de trésorerie — le 521 de la banque, le 571 de la
 * caisse, celui que chaque écriture du journal mouvemente. Pour créer un
 * journal de ventes, il fallait inventer une valeur.
 *
 * **Les identifiants fiscaux étaient demandés à un particulier.** NCC, RCCM et
 * régime d'imposition étaient seulement grisés à 45 % d'opacité pour un
 * B2C : trois champs lisibles, cliquables, et vides à jamais — un particulier
 * n'en a aucun.
 *
 * **Le tableau des clés FNE était coupé à droite.** La carte portait
 * `overflow:hidden` : sur sept colonnes, la fin du tableau — les clés, et la
 * colonne qui permet de les poser — n'était atteignable par aucun moyen.
 */
class EcransDuLotSeizeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-Knowing CGA', 'regime_imposition' => 'RNI',
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers', 'comptabilite', 'tresorerie'],
            // Cette entreprise tient ses livres dans Selflow : depuis le
            // lot 39, cela se demande. Sans la case, les ecrans comptables
            // repondent 404 (Not Found -- introuvable).
            'comptabilite_activee' => true,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-ecrans@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '411000', 'libelle' => 'Clients',
        ]);
        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '401000', 'libelle' => 'Fournisseurs',
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    // ── Les adresses portent l'identifiant opaque ────────────────────

    public function test_l_adresse_de_la_photo_ne_porte_pas_le_numero_de_ligne(): void
    {
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'INFO-CLAV',
            'nom' => 'Clavier', 'type' => 'marchandise',
            'prix_achat' => 100, 'prix_vente' => 200, 'statut' => 'actif',
        ]);

        $corps = $this->get(route('admin.produits.index'))->assertOk()->getContent();

        $this->assertStringContainsString($produit->uuid, $corps);
        $this->assertStringNotContainsString('/admin/produits/' . $produit->id . '/photo', $corps);
    }

    public function test_l_adresse_de_la_fiche_client_ne_porte_pas_le_numero_de_ligne(): void
    {
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan Yao']);

        $corps = $this->get(route('admin.clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.clients.modifier', $client), $corps);
        $this->assertStringNotContainsString("'/admin/clients/' + data.id", $corps);
    }

    public function test_l_adresse_de_la_fiche_fournisseur_ne_porte_pas_le_numero_de_ligne(): void
    {
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Grossiste CI']);

        $corps = $this->get(route('admin.fournisseurs.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.fournisseurs.modifier', $fournisseur), $corps);
        $this->assertStringNotContainsString("'/admin/fournisseurs/' + data.id", $corps);
    }

    // ── Le compte du journal ─────────────────────────────────────────

    /** @return array<string, array{0: string, 1: bool}> */
    public static function lesTypesDeJournal(): array
    {
        return [
            'banque'   => ['Banque', true],
            'caisse'   => ['Caisse', true],
            'vente'    => ['Vente', false],
            'achat'    => ['Achat', false],
            'général'  => ['Général', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesTypesDeJournal')]
    public function test_seul_un_journal_de_tresorerie_porte_un_compte(string $type, bool $attendu): void
    {
        $this->assertSame($attendu, CodeJournal::porteUnCompteDeTresorerie($type));
    }

    public function test_un_journal_de_vente_se_cree_sans_compte(): void
    {
        // Il fallait inventer une valeur : le champ était exigé pour les cinq
        // types, alors que la contrepartie d'une vente est le tiers de la
        // pièce, et qu'elle change à chaque écriture.
        $this->post(route('admin.tresorerie.creer_code_journal'), [
            'type' => 'Vente', 'code' => 'VT2', 'intitule' => 'Ventes comptoir',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('codes_journaux', [
            'entreprise_id' => $this->entreprise->id,
            'code' => 'VT2', 'compte' => null,
        ]);
    }

    public function test_un_journal_de_banque_exige_son_compte(): void
    {
        $this->post(route('admin.tresorerie.creer_code_journal'), [
            'type' => 'Banque', 'code' => 'BQ2', 'intitule' => 'Banque Atlantique',
        ])->assertSessionHasErrors('compte');
    }

    public function test_un_compte_saisi_puis_le_type_change_ne_reste_pas_colle(): void
    {
        // Le champ se cache quand on repasse sur « Vente » ; sa valeur partait
        // pourtant avec le formulaire et restait sur le journal, invisible.
        $this->post(route('admin.tresorerie.creer_code_journal'), [
            'type' => 'Vente', 'code' => 'VT3', 'intitule' => 'Ventes export', 'compte' => '521000',
        ]);

        $this->assertDatabaseHas('codes_journaux', ['code' => 'VT3', 'compte' => null]);
    }

    public function test_un_type_de_journal_invente_est_refuse(): void
    {
        // La liste vivait en dur dans le `<select>` et n'était vérifiée nulle
        // part : n'importe quelle chaîne entrait en base.
        $this->post(route('admin.tresorerie.creer_code_journal'), [
            'type' => 'Trésorerie occulte', 'code' => 'XX', 'intitule' => 'Divers',
        ])->assertSessionHasErrors('type');
    }

    // ── Les identifiants fiscaux d'un particulier ────────────────────

    public function test_les_champs_fiscaux_se_retirent_pour_un_particulier(): void
    {
        $corps = $this->get(route('admin.clients.index'))->assertOk()->getContent();

        // Le bloc existe et sait se retirer : c'est ce qui remplace l'opacité
        // à 45 %, qui laissait trois champs saisissables et vides à jamais.
        $this->assertStringContainsString('id="new_bloc_fiscal"', $corps);
        $this->assertStringContainsString('id="edit_bloc_fiscal"', $corps);
        $this->assertStringContainsString("estB2C ? 'none' : 'contents'", $corps);
    }

    public function test_le_compte_comptable_reste_pour_tout_le_monde(): void
    {
        $corps = $this->get(route('admin.clients.index'))->assertOk()->getContent();

        // Il vaut pour un particulier comme pour une entreprise : le retirer du
        // bloc fiscal était le point délicat du correctif.
        $this->assertStringContainsString('name="compte_comptable"', $corps);
        $this->assertStringNotContainsString(
            'name="compte_comptable"',
            substr($corps, strpos($corps, 'id="new_bloc_fiscal"'), 1200)
        );
    }

    // ── Le tableau des clés FNE ──────────────────────────────────────

    public function test_le_tableau_des_cles_fne_se_laisse_atteindre(): void
    {
        $superadmin = Utilisateur::create([
            'nom' => 'Selflow', 'prenom' => 'Support', 'email' => 'support-ecrans@selflow.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);

        $corps = $this->actingAs($superadmin)
            ->get(route('superadmin.fne.index'))->assertOk()->getContent();

        // La carte portait `overflow:hidden` : les clés et la colonne qui
        // permet de les poser n'étaient atteignables par aucun moyen. Elle
        // laisse défiler, et la colonne Actions reste collée à droite.
        $this->assertStringContainsString('overflow-x:auto', $corps);
        $this->assertStringContainsString('position:sticky', $corps);
        $this->assertStringContainsString('min-width:1080px', $corps);
    }
}
