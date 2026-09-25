<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ce que le client a tendu, et ce qu'on lui a rendu.
 *
 * Demandé par le propriétaire le 25/09/2026 : un champ « monnaie rendue », non
 * saisissable, toujours calculé, et qui figure sur la facture comme sur le reçu.
 *
 * En l'écrivant, un défaut est apparu : **la somme tendue partait telle quelle
 * en trésorerie et en comptabilité.** Une caisse qui recevait 10 000 F pour une
 * pièce de 6 500 F enregistrait 10 000 F d'encaissement — la caisse était
 * gonflée de la monnaie qu'on venait de rendre, et rien nulle part ne disait
 * qu'on l'avait rendue.
 *
 * Deux montants distincts, donc : `montant_recu`, la somme tendue, qui ne sert
 * qu'à établir la monnaie et à l'imprimer ; et l'encaissement, borné au net à
 * payer. La monnaie rendue, elle, n'est **pas** une colonne : une valeur
 * calculée qu'on enregistre finit par contredire ses termes.
 */
class LaMonnaieRendueTest extends TestCase
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
            'nom' => 'Quincaillerie du Plateau', 'regime_imposition' => 'RNI',
            'adresse' => 'Plateau, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00321',
            'ncc' => '2603210A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Bamba', 'prenom' => 'Salif', 'email' => 'salif-monnaie@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-001',
            'nom' => 'Ciment CPJ 45', 'type' => 'service', 'unite' => 'sac',
            'prix_achat' => 5000, 'prix_vente' => 5000, 'taux_tva' => 0,
        ]);

        $this->client = Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Entreprise Konan BTP',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    /** Une vente au comptant de 10 000 F, réglée par `$tendu`. */
    private function encaisser(float $tendu): Vente
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape'         => 'Facture',
            'type_piece'    => 'facture',
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Caisse',
            'montant_paye'  => $tendu,
            'articles'      => [[
                'produit_id'     => $this->article->id,
                'quantite'       => 2,
                'prix_unitaire'  => 5000,
                'unite'          => 'sac',
            ]],
        ])->assertRedirect();

        return Vente::latest('id')->firstOrFail();
    }

    // ══════════════ Le calcul ══════════════

    public function test_la_monnaie_rendue_est_ce_qui_depasse_le_du(): void
    {
        $vente = $this->encaisser(15000);

        $this->assertSame(10000.0, $vente->netAPayer());
        $this->assertSame(15000.0, (float) $vente->montant_recu);
        $this->assertSame(5000.0, $vente->monnaieRendue());
    }

    public function test_un_reglement_a_l_appoint_ne_rend_rien(): void
    {
        $vente = $this->encaisser(10000);

        // Zéro, et non `null` : le client a bien tendu une somme, elle
        // couvrait exactement le dû.
        $this->assertSame(0.0, $vente->monnaieRendue());
    }

    public function test_une_somme_insuffisante_n_est_pas_une_monnaie_a_rendre(): void
    {
        // C'est une avance. L'annoncer en négatif ferait croire à une dette de
        // la caisse envers le client.
        $vente = $this->encaisser(6000);

        $this->assertSame(0.0, $vente->monnaieRendue());
        $this->assertSame('Avance', $vente->statut);
    }

    public function test_sans_somme_tendue_il_n_y_a_pas_de_ligne(): void
    {
        $vente = Vente::create([
            'point_de_vente_id' => $this->magasin->id,
            'numero_facture' => 'VTE-SANS-001', 'date_vente' => now()->toDateString(),
            'etape' => 'Facture', 'montant_ht' => 100, 'montant_tva' => 0,
            'montant_ttc' => 100, 'mode_paiement' => 'Crédit', 'statut' => 'Crédit',
        ]);

        // `null` et non zéro : on ne rend pas zéro, on ne rend rien — et le
        // document ne doit pas porter une ligne qui n'a pas eu lieu.
        $this->assertNull($vente->monnaieRendue());
    }

    // ══════════════ Ce qui entre réellement en caisse ══════════════

    public function test_la_caisse_n_encaisse_pas_la_monnaie_qu_elle_rend(): void
    {
        $this->encaisser(15000);

        $encaisse = TresorerieJournal::where('point_de_vente_id', $this->magasin->id)
            ->sum('montant_entree');

        // 10 000 et non 15 000 : les 5 000 sont ressortis du tiroir.
        $this->assertSame(10000.0, (float) $encaisse);
    }

    public function test_un_reglement_exact_entre_en_entier(): void
    {
        $this->encaisser(10000);

        $encaisse = TresorerieJournal::where('point_de_vente_id', $this->magasin->id)
            ->sum('montant_entree');

        $this->assertSame(10000.0, (float) $encaisse);
    }

    // ══════════════ Ce que l'écran et les documents en disent ══════════════

    public function test_l_ecran_de_vente_porte_un_champ_non_saisissable(): void
    {
        $page = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('id="monnaieRendueInput"', $page);
        $this->assertStringContainsString('calculerRenduMonnaie', $page);
        // Jamais saisie : la laisser saisissable, c'est permettre qu'elle
        // contredise les deux montants entre lesquels elle se tient.
        $this->assertMatchesRegularExpression(
            '/id="monnaieRendueInput"[^>]*readonly/',
            $page
        );
        // Et jamais transmise : le serveur la recalcule.
        $this->assertDoesNotMatchRegularExpression(
            '/id="monnaieRendueInput"[^>]*name=/',
            $page
        );
    }

    public function test_l_ecran_d_achat_porte_le_meme_champ(): void
    {
        $page = $this->get(route('admin.achats.nouveau'))->assertOk()->getContent();

        $this->assertStringContainsString('id="monnaieRendueInput"', $page);
        $this->assertStringContainsString('calculerRenduMonnaie', $page);
    }

    public function test_le_bareme_du_timbre_vient_du_service_et_non_d_une_copie(): void
    {
        // Les deux écrans doivent annoncer le net **avant** que la pièce parte
        // à la plateforme : le client tend son argent au comptoir, pas après la
        // réponse de la DGI. Il leur faut donc le barème.
        //
        // Il en existait une copie écrite à la main dans l'écran de vente, et
        // aucune dans celui des achats — d'où un net sous-estimé du timbre sur
        // un bordereau réglé en espèces. Les valeurs sont désormais tirées de
        // `TimbreQuittanceService`, périmètre gelé de la conformité FNE : le
        // service n'est pas modifié, il est lu. Le journal garde la trace de
        // ce que coûte une seconde copie — le timbre estimé à 1,5 %, taux qui
        // ne figure dans aucun texte.
        $attendu = json_encode(\App\Modules\Admin\Services\TimbreQuittanceService::BAREME);
        $tranche = \App\Modules\Admin\Services\TimbreQuittanceService::DROIT_TRANCHE_SUPERIEURE;

        foreach ([route('admin.ventes.nouvelle'), route('admin.achats.nouveau')] as $adresse) {
            $page = $this->get($adresse)->assertOk()->getContent();

            $this->assertStringContainsString('const BAREME_TIMBRE = ' . $attendu, $page, $adresse);
            $this->assertStringContainsString('const TIMBRE_TRANCHE_SUPERIEURE = ' . $tranche, $page, $adresse);
        }
    }

    public function test_l_ecran_d_achat_annonce_le_timbre(): void
    {
        // Le pavé des totaux n'en portait aucune ligne : il annonçait un net
        // inférieur à ce qu'on remet vraiment au vendeur.
        $page = $this->get(route('admin.achats.nouveau'))->assertOk()->getContent();

        $this->assertStringContainsString('id="totTimbreAchat"', $page);
        $this->assertStringContainsString('timbreDeQuittance(total, modePaiementAchat)', $page);
    }

    public function test_la_facture_et_le_recu_l_annoncent(): void
    {
        $vente = $this->encaisser(15000);
        $service = app(\App\Modules\Admin\Services\DocumentPdfService::class);
        $methode = new \ReflectionMethod($service, 'donneesVente');
        $methode->setAccessible(true);
        $donnees = $methode->invoke($service, $vente->fresh(['details.produit', 'client', 'pointDeVente.entreprise']), 0.0);

        foreach (['admin::factures.pdf.document', 'admin::factures.pdf.ticket'] as $gabarit) {
            $html = view($gabarit, $donnees)->render();

            $this->assertStringContainsStringIgnoringCase('monnaie rendue', $html, $gabarit);
            $this->assertStringContainsString('5 000 F', str_replace("\u{202F}", ' ', $html), $gabarit);
        }
    }

    public function test_les_documents_affiches_l_annoncent_aussi(): void
    {
        // Le PDF n'est pas le seul document : la pièce et le reçu s'affichent
        // aussi à l'écran, et c'est là que le caissier les lit au comptoir.
        $vente = $this->encaisser(15000);

        $piece = $this->get(route('admin.ventes.imprimer', $vente))->assertOk()->getContent();
        $recu  = $this->get(route('admin.ventes.ticket', $vente))->assertOk()->getContent();

        // La pièce est dessinée en JavaScript : ce qui compte est que la
        // valeur y soit portée, et calculée par le serveur.
        $this->assertStringContainsString('monnaie_rendue: 5000', $piece);
        $this->assertStringContainsString('blocMonnaieRendue', $piece);

        $this->assertStringContainsStringIgnoringCase('monnaie rendue', $recu);
        $this->assertStringContainsString('5 000', $recu);
    }

    public function test_un_reglement_a_l_appoint_n_imprime_pas_de_ligne(): void
    {
        $vente = $this->encaisser(10000);
        $service = app(\App\Modules\Admin\Services\DocumentPdfService::class);
        $methode = new \ReflectionMethod($service, 'donneesVente');
        $methode->setAccessible(true);
        $donnees = $methode->invoke($service, $vente->fresh(['details.produit', 'client', 'pointDeVente.entreprise']), 0.0);

        $html = view('admin::factures.pdf.ticket', $donnees)->render();

        $this->assertStringNotContainsStringIgnoringCase('monnaie rendue', $html);
    }
}
