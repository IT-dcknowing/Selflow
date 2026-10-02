<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\ModeleLibelle;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\ComptabiliteService;
use App\Modules\Admin\Services\LibelleEcritureService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les libellés d'écriture cessent d'être l'intitulé du compte.
 *
 * Jusqu'ici, l'opération d'une facture de vente portait pour libellé général
 * « Vente de marchandises » — c'est-à-dire le nom SYSCOHADA du compte
 * mouvementé. Le compte dit déjà cela : le répéter dépense la seule colonne de
 * texte libre du journal, et un grand livre du 701 dont chaque ligne redit son
 * en-tête n'apprend rien.
 *
 * Deux exigences encadrent le changement :
 *
 *  1. **une entreprise qui ne paramètre rien ne voit aucune différence.** Les
 *     gabarits par défaut reproduisent l'ancien texte au caractère près ;
 *  2. **les écritures déjà passées ne sont pas réécrites.** Un journal se lit
 *     tel qu'il a été tenu.
 */
class LibellesEcritureTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Client $client;
    private Fournisseur $fournisseur;
    private Produit $ciment;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        LibelleEcritureService::oublier();

        // Cette entreprise tient ses livres dans Selflow : depuis le lot 39,
        // cela se demande. Sans la case, l'ecran des libelles repond
        // 404 (Not Found -- introuvable).
        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie du Plateau',
            'comptabilite_activee' => true,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Dépôt de Yopougon', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        foreach ([['VTE', 'Ventes', 'Vente'], ['ACH', 'Achats', 'Achat'], ['CAI', 'Caisse', 'Trésorerie']] as [$code, $intitule, $type]) {
            CodeJournal::create([
                'entreprise_id' => $this->entreprise->id,
                'code' => $code, 'intitule' => $intitule, 'type' => $type,
            ]);
        }

        $this->client = Client::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'SOCIÉTÉ IVOIRIENNE DE NÉGOCE',
            'compte_comptable' => '411000', 'numero_tiers' => '411001',
        ]);

        $this->fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Cimenterie de Côte d\'Ivoire',
            'compte_comptable' => '401000', 'numero_tiers' => '400012',
        ]);

        $rayon = Categorie::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Marchandises', 'prefixe' => 'MAR',
            'compte_vente' => '701000', 'compte_achat' => '601000',
        ]);

        $this->ciment = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-50',
            'nom' => 'Ciment 50 kg', 'type' => 'marchandise',
            'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
            'categorie_id' => $rayon->id,
        ]);

        $this->admin = Utilisateur::create([
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
            'nom' => 'Kouadio', 'prenom' => 'Lewis',
            'email' => 'lewis@quincaillerie.ci',
            'password' => Hash::make('motdepasse-de-test'),
            'role' => 'admin', 'statut' => 'actif',
        ]);
    }

    // ── Le défaut reproduit l'ancien comportement ───────────────────

    public function test_sans_gabarit_l_operation_de_vente_garde_l_ancien_libelle(): void
    {
        $vente = $this->vente();

        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame('Vente de marchandises', $this->operation('FactureVente')->libelle_general);
    }

    public function test_sans_gabarit_les_lignes_de_vente_gardent_l_ancien_libelle(): void
    {
        $vente = $this->vente();

        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame(
            $vente->numero_facture . ' / Facturation Vente',
            EcritureComptable::where('compte_debit', '411000')->value('libelle')
        );

        $this->assertSame(
            $vente->numero_facture . ' / TVA Collectée Vente',
            EcritureComptable::where('compte_credit', '443100')->value('libelle')
        );
    }

    public function test_sans_gabarit_l_achat_garde_l_ancien_libelle(): void
    {
        $achat = $this->achat();

        ComptabiliteService::genererEcrituresAchat($achat, 0, 'Crédit');

        $this->assertSame('Achat de marchandises', $this->operation('FactureAchat')->libelle_general);
        $this->assertSame(
            $achat->numero_facture . ' / Facturation Achat',
            EcritureComptable::where('compte_credit', '401000')->value('libelle')
        );
    }

    /**
     * Le règlement sans référence de paiement : l'ancien texte ne portait pas
     * de double barre. Le gabarit `Rglt/{piece}/{reference}/Vente {produits}`
     * en produirait une si le nettoyage ne la retirait pas.
     */
    public function test_un_reglement_sans_reference_ne_laisse_pas_de_double_barre(): void
    {
        $vente = $this->vente();

        ComptabiliteService::genererEcrituresVente($vente, 7670, 'Espèces');

        $libelle = EcritureComptable::where('compte_debit', '571000')->value('libelle');

        $this->assertSame('Rglt/' . $vente->numero_facture . '/Vente Ciment 50 kg', $libelle);
        $this->assertStringNotContainsString('//', (string) $libelle);
    }

    public function test_un_reglement_avec_reference_la_porte_au_libelle(): void
    {
        $vente = $this->vente(['reference_paiement' => 'CHQ-4471']);

        ComptabiliteService::genererEcrituresVente($vente, 7670, 'Chèque');

        $this->assertStringContainsString(
            'CHQ-4471',
            (string) EcritureComptable::whereNotNull('compte_debit')
                ->where('libelle', 'like', 'Rglt/%')->value('libelle')
        );
    }

    // ── Le gabarit de l'entreprise l'emporte ────────────────────────

    public function test_un_gabarit_pose_change_le_libelle_de_l_operation(): void
    {
        $this->gabarit('FactureVente', '{tiers} — {point_de_vente}', null);

        $vente = $this->vente();
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame(
            'SOCIÉTÉ IVOIRIENNE DE NÉGOCE — Dépôt de Yopougon',
            $this->operation('FactureVente')->libelle_general
        );
    }

    public function test_un_gabarit_pose_change_le_libelle_des_lignes(): void
    {
        $this->gabarit('FactureVente', null, '{piece} · {tiers} · {role}');

        $vente = $this->vente();
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame(
            $vente->numero_facture . ' · SOCIÉTÉ IVOIRIENNE DE NÉGOCE · Facturation Vente',
            EcritureComptable::where('compte_debit', '411000')->value('libelle')
        );
    }

    /**
     * Un gabarit posé sur la vente ne doit pas déteindre sur l'achat : les six
     * types sont indépendants.
     */
    public function test_un_gabarit_de_vente_ne_touche_pas_l_achat(): void
    {
        $this->gabarit('FactureVente', 'VENTE {tiers}', null);

        $achat = $this->achat();
        ComptabiliteService::genererEcrituresAchat($achat, 0, 'Crédit');

        $this->assertSame('Achat de marchandises', $this->operation('FactureAchat')->libelle_general);
    }

    /**
     * Un jeton sans valeur — une vente sans client — ne doit pas laisser le
     * séparateur qui l'annonçait.
     */
    public function test_un_jeton_vide_emporte_son_separateur(): void
    {
        $this->gabarit('FactureVente', '{piece} — {tiers}', null);

        $vente = $this->vente(['client_id' => null]);
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame($vente->numero_facture, $this->operation('FactureVente')->libelle_general);
    }

    /**
     * Un gabarit dont tous les jetons sont vides produirait une écriture sans
     * libellé, et le journal deviendrait illisible. Le défaut reprend la main.
     */
    public function test_un_gabarit_qui_ne_produit_rien_retombe_sur_le_defaut(): void
    {
        $this->gabarit('FactureVente', '{tiers}', null);

        $vente = $this->vente(['client_id' => null]);
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame('Vente de marchandises', $this->operation('FactureVente')->libelle_general);
    }

    /**
     * La colonne fait 255 caractères. Un gabarit bavard ne doit pas faire
     * échouer l'enregistrement d'une vente.
     */
    public function test_un_libelle_trop_long_est_tronque_et_n_empeche_pas_la_vente(): void
    {
        $this->gabarit('FactureVente', str_repeat('Libellé interminable ', 40), null);

        $vente = $this->vente();
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertLessThanOrEqual(255, mb_strlen($this->operation('FactureVente')->libelle_general));
    }

    // ── Le passé n'est pas réécrit ──────────────────────────────────

    public function test_les_ecritures_deja_passees_ne_sont_pas_reecrites(): void
    {
        $vente = $this->vente();
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $avant = EcritureComptable::where('compte_debit', '411000')->value('libelle');

        $this->gabarit('FactureVente', 'TOUT AUTRE CHOSE', 'TOUT AUTRE CHOSE');

        $this->assertSame(
            $avant,
            EcritureComptable::where('compte_debit', '411000')->value('libelle')
        );
    }

    // ── Le cloisonnement ────────────────────────────────────────────

    public function test_le_gabarit_d_une_entreprise_ne_vaut_pas_pour_une_autre(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);
        ModeleLibelle::create([
            'entreprise_id' => $voisine->id,
            'type_operation' => 'FactureVente',
            'gabarit_operation' => 'LE GABARIT DU VOISIN',
        ]);

        $vente = $this->vente();
        ComptabiliteService::genererEcrituresVente($vente, 0, 'Crédit');

        $this->assertSame('Vente de marchandises', $this->operation('FactureVente')->libelle_general);
    }

    // ── L'écran de paramétrage ──────────────────────────────────────

    public function test_l_ecran_s_affiche_avec_les_six_types(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.comptabilite.libelles'));

        $reponse->assertOk();
        foreach (ModeleLibelle::TYPES as $nom) {
            $reponse->assertSee($nom);
        }
    }

    public function test_l_ecran_enregistre_un_gabarit(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.comptabilite.libelles.enregistrer'), [
                'gabarits' => ['FactureVente' => ['operation' => '{tiers}', 'ligne' => '{piece} / {role}']],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('modeles_libelles', [
            'entreprise_id'     => $this->entreprise->id,
            'type_operation'    => 'FactureVente',
            'gabarit_operation' => '{tiers}',
        ]);
    }

    /**
     * Deux champs vides veulent dire « reviens au défaut ». Enregistrer deux
     * chaînes vides laisserait la ligne en base et empêcherait une évolution
     * ultérieure du défaut de rattraper cette entreprise.
     */
    public function test_vider_les_deux_champs_supprime_la_ligne(): void
    {
        $this->gabarit('FactureVente', '{tiers}', null);

        $this->actingAs($this->admin)
            ->put(route('admin.comptabilite.libelles.enregistrer'), [
                'gabarits' => ['FactureVente' => ['operation' => '', 'ligne' => '']],
            ]);

        $this->assertDatabaseMissing('modeles_libelles', [
            'entreprise_id'  => $this->entreprise->id,
            'type_operation' => 'FactureVente',
        ]);
    }

    public function test_un_gabarit_de_plus_de_255_caracteres_est_refuse(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.comptabilite.libelles.enregistrer'), [
                'gabarits' => ['FactureVente' => ['operation' => str_repeat('x', 256)]],
            ])
            ->assertSessionHasErrors('gabarits.FactureVente.operation');
    }

    /**
     * Simulation d'attaque : un type d'opération inventé, glissé dans le
     * formulaire. Il serait enregistré et jamais relu — une ligne morte dans la
     * table, et une fausse impression d'avoir paramétré quelque chose.
     */
    public function test_un_type_d_operation_invente_n_est_pas_enregistre(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.comptabilite.libelles.enregistrer'), [
                'gabarits' => ['ViderLaCaisse' => ['operation' => 'peu importe']],
            ]);

        $this->assertDatabaseMissing('modeles_libelles', ['type_operation' => 'ViderLaCaisse']);
    }

    /**
     * Simulation d'attaque : `entreprise_id` glissé dans la charge utile. Le
     * contrôleur ne lit que l'entreprise de la session ; le champ doit rester
     * sans effet.
     */
    public function test_un_entreprise_id_injecte_reste_sans_effet(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);

        $this->actingAs($this->admin)
            ->put(route('admin.comptabilite.libelles.enregistrer'), [
                'entreprise_id' => $voisine->id,
                'gabarits' => ['FactureVente' => ['operation' => '{tiers}']],
            ]);

        $this->assertDatabaseMissing('modeles_libelles', ['entreprise_id' => $voisine->id]);
        $this->assertDatabaseHas('modeles_libelles', ['entreprise_id' => $this->entreprise->id]);
    }

    public function test_l_apercu_rend_le_gabarit_sans_rien_enregistrer(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.comptabilite.libelles.apercu'), [
                'gabarit' => '{piece} — {tiers}',
                'type'    => 'FactureVente',
                'cible'   => 'operation',
            ]);

        $reponse->assertOk()
            ->assertJsonPath('apercu', 'FV-240826-014 — SOCIÉTÉ IVOIRIENNE DE NÉGOCE');

        $this->assertDatabaseCount('modeles_libelles', 0);
    }

    /**
     * `{role}` n'a pas de sens sur l'opération : seule une ligne en porte un.
     * Le rendre ferait promettre à l'aperçu un texte que l'écriture ne
     * produira jamais.
     */
    public function test_l_apercu_d_une_operation_ne_rend_pas_le_role(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.comptabilite.libelles.apercu'), [
                'gabarit' => '{piece} / {role}',
                'type'    => 'FactureVente',
                'cible'   => 'operation',
            ])
            ->assertJsonPath('apercu', 'FV-240826-014');
    }

    // ── Le service, isolément ───────────────────────────────────────

    public function test_le_nettoyage_conserve_le_premier_separateur_et_ses_espaces(): void
    {
        $this->assertSame(
            'A / B',
            LibelleEcritureService::appliquer('A / {tiers} / B', [])
        );

        $this->assertSame(
            'A/B',
            LibelleEcritureService::appliquer('A/{tiers}/B', [])
        );
    }

    // ─────────────────────────────────────────────────────────────────

    private function gabarit(string $type, ?string $operation, ?string $ligne): void
    {
        ModeleLibelle::updateOrCreate(
            ['entreprise_id' => $this->entreprise->id, 'type_operation' => $type],
            ['gabarit_operation' => $operation, 'gabarit_ligne' => $ligne],
        );

        LibelleEcritureService::oublier($this->entreprise->id);
    }

    private function vente(array $attributs = []): Vente
    {
        $vente = Vente::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => 'FV-' . uniqid(),
            'date_vente'        => now()->toDateString(),
            'montant_ht'        => 6500,
            'montant_tva'       => 1170,
            'montant_ttc'       => 7670,
            'etape'             => 'Facture',
            'statut'            => 'Payé',
            'mode_paiement'     => 'Espèces',
        ], $attributs));

        VenteDetail::create([
            'vente_id'      => $vente->id,
            'produit_id'    => $this->ciment->id,
            'quantite'      => 1,
            'prix_unitaire' => 6500,
            'montant_tva'   => 1170,
            'montant_ttc'   => 7670,
        ]);

        return $vente->fresh();
    }

    private function achat(array $attributs = []): Achat
    {
        $achat = Achat::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id'    => $this->fournisseur->id,
            'numero_facture'    => 'FA-' . uniqid(),
            'date_achat'        => now()->toDateString(),
            'montant_ht'        => 50000,
            'montant_tva'       => 9000,
            'montant_ttc'       => 59000,
            'statut'            => 'Payé',
            'mode_paiement'     => 'Espèces',
        ], $attributs));

        AchatDetail::create([
            'achat_id'      => $achat->id,
            'produit_id'    => $this->ciment->id,
            'quantite'      => 10,
            'prix_unitaire' => 5000,
            'montant_tva'   => 9000,
            'montant_ttc'   => 59000,
        ]);

        return $achat->fresh();
    }

    private function operation(string $type): Operation
    {
        return Operation::where('entreprise_id', $this->entreprise->id)
            ->where('type_operation', $type)
            ->firstOrFail();
    }
}
