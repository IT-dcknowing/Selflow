<?php

namespace Tests\Feature;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Quand la pièce part à la DGI.
 *
 * La normalisation partait systématiquement à l'enregistrement, sans que
 * personne ne puisse s'y opposer. C'est le bon comportement pour une caisse
 * qui tourne, mais pas pour une entreprise qui vérifie ses pièces avant de les
 * certifier — **et une pièce certifiée ne se reprend pas**.
 *
 * Le réglage se posait d'abord en deux cases, une par nature de pièce. **Le
 * 25/09/2026 elles ont été réunies en une seule** : le reçu emprunte la porte de
 * la facture — même envoi, même code QR, même sticker —, et deux réglages pour
 * une seule décision ne disaient pas lequel commandait la pièce qu'on avait
 * sous les yeux. Les deux colonnes restent en base et restent lues séparément :
 * une base peuplée avant cette date peut porter deux valeurs différentes.
 *
 * Le défaut reste l'automatique, pour ne rien changer aux entreprises déjà en
 * service sans qu'elles l'aient demandé.
 */
class NormalisationAutomatiqueTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Produit $article;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Plateau',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00321',
            'ncc'               => '2603210A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Bamba', 'prenom' => 'Salif', 'email' => 'salif-norm@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-001',
            'nom' => 'Ciment CPJ 45', 'type' => 'service', 'unite' => 'sac',
            'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);

        $this->client = Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Entreprise Konan BTP',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function emettre(string $typePiece = Vente::TYPE_FACTURE): void
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape'         => 'Facture',
            'type_piece'    => $typePiece,
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Espèces',
            'articles'      => [[
                'produit_id' => $this->article->id,
                'quantite'   => 2,
                'unite'      => 'sac',
            ]],
        ])->assertSessionDoesntHaveErrors();
    }

    // ══════════════ Le défaut : automatique ══════════════

    public function test_par_defaut_la_facture_part_des_son_emission(): void
    {
        $this->emettre();

        Queue::assertPushed(NormaliserFactureFne::class);
    }

    // ══════════════ Le réglage manuel ══════════════

    public function test_en_manuel_la_facture_ne_part_pas_toute_seule(): void
    {
        $this->entreprise->update(['normalisation_auto_factures' => false]);

        $this->emettre();

        Queue::assertNotPushed(NormaliserFactureFne::class);

        // Elle reste enregistrée, et normalisable à la main.
        $this->assertSame(1, Vente::count());
        $this->assertFalse((bool) Vente::first()->normalise);
    }

    public function test_en_manuel_le_recu_ne_part_pas_tout_seul(): void
    {
        $this->entreprise->update(['normalisation_auto_recus' => false]);

        $this->emettre(Vente::TYPE_RECU);

        Queue::assertNotPushed(NormaliserFactureFne::class);
    }

    // ══════════════ Les deux colonnes restent lues séparément ══════════════
    //
    // L'écran ne propose plus qu'une case, qui écrit la même valeur dans les
    // deux colonnes — voir plus bas. Le modèle, lui, continue de lire celle qui
    // correspond à la pièce : une base peuplée avant le 25/09/2026 peut porter
    // deux valeurs différentes, et elles doivent continuer d'être respectées.

    public function test_le_reglage_des_recus_ne_retient_pas_les_factures(): void
    {
        $this->entreprise->update([
            'normalisation_auto_factures' => true,
            'normalisation_auto_recus'    => false,
        ]);

        $this->emettre(Vente::TYPE_FACTURE);

        Queue::assertPushed(NormaliserFactureFne::class);
    }

    public function test_le_reglage_des_factures_ne_retient_pas_les_recus(): void
    {
        $this->entreprise->update([
            'normalisation_auto_factures' => false,
            'normalisation_auto_recus'    => true,
        ]);

        $this->emettre(Vente::TYPE_RECU);

        Queue::assertPushed(NormaliserFactureFne::class);
    }

    // ══════════════ Le réglage se pose depuis l'écran ══════════════

    /**
     * Les paramètres de l'entreprise, avec le minimum exigé par l'écran.
     */
    private function enregistrerParametres(array $ajouts = []): void
    {
        $this->put(route('admin.entreprise.parametres.enregistrer'), array_merge([
            'nom'               => $this->entreprise->nom,
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00321',
            'ncc'               => '2603210A',
            'gerant_fonction'   => 'Gérant',
            'secteurs_activite' => ['Commerce'],
        ], $ajouts));
    }

    public function test_une_seule_case_commande_les_deux_colonnes(): void
    {
        // Réuni le 25/09/2026 : deux réglages pour une seule décision ne
        // disaient pas lequel commandait la pièce qu'on avait sous les yeux.
        // Le reçu emprunte la porte de la facture ; la case écrit donc la même
        // valeur dans les deux colonnes.
        $this->entreprise->update([
            'normalisation_auto_factures' => false,
            'normalisation_auto_recus'    => false,
        ]);

        $this->enregistrerParametres(['normalisation_auto' => '1']);

        $entreprise = $this->entreprise->fresh();

        $this->assertTrue((bool) $entreprise->normalisation_auto_factures);
        $this->assertTrue((bool) $entreprise->normalisation_auto_recus);
    }

    public function test_le_reglage_se_decoche_depuis_les_parametres(): void
    {
        // Une case non cochée n'est pas transmise : sans lecture explicite,
        // décocher n'aurait aucun effet.
        $this->entreprise->update([
            'normalisation_auto_factures' => true,
            'normalisation_auto_recus'    => true,
        ]);

        // `normalisation_auto` volontairement absent : décoché.
        $this->enregistrerParametres();

        $entreprise = $this->entreprise->fresh();

        $this->assertFalse((bool) $entreprise->normalisation_auto_factures);
        $this->assertFalse((bool) $entreprise->normalisation_auto_recus);
    }

    /**
     * L'ancienne case du bordereau ne commandait rien.
     *
     * `entreprises.bapa` n'était lue nulle part ailleurs que dans le résumé de
     * la page des paramètres : le bordereau se choisit à la saisie de l'achat,
     * et c'est l'espace FNE de l'entreprise qui l'autorise. La case est devenue
     * une information, et l'enregistrement ne doit plus y toucher.
     */
    public function test_l_enregistrement_ne_touche_plus_au_bapa(): void
    {
        $this->entreprise->update(['bapa' => true]);

        $this->enregistrerParametres(['normalisation_auto' => '1']);

        $this->assertTrue((bool) $this->entreprise->fresh()->bapa);
    }

    // ══════════════ Ce que l'écran en dit ══════════════

    public function test_l_ecran_de_vente_n_annonce_plus_une_certification_suspendue(): void
    {
        // L'encadré annonçait une certification « suspendue tant que la FNE
        // n'a pas fourni les champs de mappage du reçu normalisé
        // électronique ». C'était vrai avant la refonte du reçu : depuis, il
        // emprunte la porte de la facture, et rien ne le retient. Le texte
        // disait à l'utilisateur que ses reçus n'étaient pas certifiés alors
        // qu'ils l'étaient.
        $corps = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Normalisation RNE en attente', $corps);
        $this->assertStringNotContainsString('champs de mappage du reçu', $corps);
        $this->assertStringContainsString('Une seule pièce, deux documents', $corps);
    }

    /**
     * Le choix « Reçu » a disparu de la saisie.
     *
     * Une caisse qui le choisissait se retrouvait sans facture, alors que le
     * reçu n'est que la facture mise en page pour le ticket. Un seul bouton
     * établit les deux, et c'est `facture` qui part à la plateforme — la
     * valeur qu'elle reçoit déjà.
     */
    public function test_la_saisie_ne_propose_plus_le_recu_comme_piece_a_part(): void
    {
        $corps = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('Facture + Reçu', $corps);
        $this->assertStringNotContainsString('data-etape-vente="Reçu"', $corps);
        $this->assertStringContainsString('id="typePieceInput" value="facture"', $corps);
    }

    public function test_l_ecran_dit_que_la_piece_part_des_son_emission(): void
    {
        $this->entreprise->update(['normalisation_auto_factures' => true]);

        $corps = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('part à la certification dès son émission', $corps);
    }

    public function test_l_ecran_dit_que_la_piece_attend_quand_le_reglage_est_decoche(): void
    {
        $this->entreprise->update(['normalisation_auto_factures' => false]);

        $corps = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('est décochée dans vos', $corps);
        $this->assertStringNotContainsString('part à la certification dès son émission', $corps);
    }
}
