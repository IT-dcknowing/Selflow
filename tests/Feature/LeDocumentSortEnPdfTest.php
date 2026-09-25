<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\DocumentPdfService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le document se télécharge en PDF véritable.
 *
 * Les écrans dessinent la pièce en JavaScript, en quatre modèles : le document
 * n'existe que dans le navigateur. « Imprimer / PDF » passait donc par la boîte
 * d'impression, et le « Télécharger » du lot 30 rendait une page HTML autonome
 * — un fichier, mais pas un PDF.
 *
 * dompdf a été installé le 25/09/2026, et la pièce est désormais établie côté
 * serveur. Ce qui est vérifié ici :
 *
 *  - l'adresse rend un vrai PDF, en pièce jointe, sous le nom de la pièce ;
 *  - **la certification n'apparaît que si elle a eu lieu.** Un document non
 *    normalisé qui porterait le visuel FNE et un numéro usurperait des mentions
 *    officielles ;
 *  - la pièce d'autrui reste introuvable (404) ;
 *  - les montants du PDF sont ceux qui ont été enregistrés, et non une seconde
 *    addition qui pourrait diverger.
 */
class LeDocumentSortEnPdfTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Produit $article;

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
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-pdf@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-001',
            'nom' => 'Ciment CPJ 45', 'type' => 'service', 'unite' => 'sac',
            'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function facture(array $ajouts = []): Vente
    {
        $vente = Vente::create(array_merge([
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

        VenteDetail::create([
            'vente_id' => $vente->id, 'produit_id' => $this->article->id,
            'quantite' => 2, 'unite' => 'sac', 'prix_unitaire' => 5000,
            'montant_tva' => 1800, 'montant_ttc' => 11800,
        ]);

        return $vente->fresh();
    }

    // ══════════════ Ce que l'adresse rend ══════════════

    public function test_la_facture_se_telecharge_en_pdf(): void
    {
        $vente = $this->facture();

        $reponse = $this->get(route('admin.ventes.pdf', $vente))->assertOk();

        $reponse->assertHeader('Content-Type', 'application/pdf');
        // Une pièce jointe, et non une page ouverte : le bouton dit
        // « Télécharger ».
        $this->assertStringContainsString('attachment', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('VTE-240926-012.pdf', $reponse->headers->get('Content-Disposition'));

        // Un PDF commence par `%PDF`. Sans cette vérification, une page
        // d'erreur servie avec le bon en-tête passerait pour un document.
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_le_recu_se_telecharge_en_pdf(): void
    {
        $vente = $this->facture();

        $reponse = $this->get(route('admin.ventes.ticket.pdf', $vente))->assertOk();

        $this->assertStringContainsString('-recu.pdf', $reponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_le_bordereau_d_achat_se_telecharge_en_pdf(): void
    {
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'KONAN Kofi',
        ]);

        $achat = Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'utilisateur_id'    => $this->admin->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'BA-240926-003',
            'date_achat'        => now()->toDateString(),
            'etape'             => 'Facture',
            'type_facture'      => 'bapa',
            'montant_ht'        => 13200,
            'montant_tva'       => 0,
            'montant_ttc'       => 13200,
            'mode_paiement'     => 'Caisse',
            'statut'            => 'Payé',
        ]);

        AchatDetail::create([
            'achat_id' => $achat->id, 'produit_id' => $this->article->id,
            'quantite' => 3, 'unite' => 'sac', 'prix_unitaire' => 4400,
            'montant_tva' => 0, 'montant_ttc' => 13200,
        ]);

        $reponse = $this->get(route('admin.achats.pdf', $achat))->assertOk();

        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        $this->assertStringContainsString('BA-240926-003.pdf', $reponse->headers->get('Content-Disposition'));
    }

    // ══════════════ Ce que le PDF porte, et ne porte pas ══════════════

    public function test_la_piece_non_certifiee_ne_porte_aucune_mention_de_la_dgi(): void
    {
        // Le document de test est lu tel que le gabarit le rend : c'est là que
        // se décide ce qui figure sur le papier.
        $vente = $this->facture();

        $html = $this->rendreLeGabarit($vente);

        $this->assertStringNotContainsString('Facture normalisée électronique', $html);
        $this->assertStringNotContainsString('N° FNE', $html);
    }

    public function test_la_piece_certifiee_porte_le_bloc_de_la_dgi(): void
    {
        $vente = $this->facture([
            'normalise'     => true,
            'numero_fne'    => '1864699A26000000123',
            'qr_code_data'  => 'JETON-DE-VERIFICATION',
            'signature_dgi' => 'SIGNATURE-DGI',
        ]);

        $html = $this->rendreLeGabarit($vente);

        $this->assertStringContainsString('Facture normalisée électronique', $html);
        $this->assertStringContainsString('1864699A26000000123', $html);
        $this->assertStringContainsString('JETON-DE-VERIFICATION', $html);
        // Le code QR est dessiné depuis la matrice du service gelé, en PNG :
        // le SVG de l'écran ne traverse pas proprement un PDF.
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_les_montants_du_pdf_sont_ceux_qui_ont_ete_enregistres(): void
    {
        $vente = $this->facture();

        $html = $this->rendreLeGabarit($vente);

        // Les montants sont lus tels qu'enregistrés, jamais rejoués : un
        // second calcul finirait par diverger du premier.
        $this->assertStringContainsString('11 800 F', $html);
        $this->assertStringContainsString('10 000 F', $html);
    }

    public function test_le_pdf_reprend_le_modele_choisi_a_l_ecran(): void
    {
        /*
         * L'écran laisse choisir entre quatre modèles — Classique, Élégant,
         * Moderne, Standard — et le PDF rendait **toujours le premier** : on
         * téléchargeait un document qui n'était pas celui qu'on regardait.
         */
        $vente = $this->facture();

        $classique = $this->rendreLeGabarit($vente, 1);
        $moderne   = $this->rendreLeGabarit($vente, 3);

        // La teinte du modèle 1, et celle du modèle 3.
        $this->assertStringContainsString('#0F6E56', $classique);
        $this->assertStringNotContainsString('#4F46E5', $classique);

        $this->assertStringContainsString('#4F46E5', $moderne);
        $this->assertStringNotContainsString('#0F6E56', $moderne);
    }

    public function test_un_modele_inconnu_retombe_sur_le_premier(): void
    {
        // Le numéro vient de l'adresse : il peut être inventé, ou absent.
        $vente = $this->facture();

        $this->assertSame(
            \App\Modules\Admin\Services\DocumentPdfService::modele(1),
            \App\Modules\Admin\Services\DocumentPdfService::modele(99)
        );

        $this->get(route('admin.ventes.pdf', $vente) . '?modele=99')->assertOk();
    }

    public function test_les_couleurs_du_pdf_sont_celles_de_l_ecran(): void
    {
        // Deux listes de couleurs pour un même choix finiraient par diverger,
        // et le PDF cesserait de ressembler à l'écran sans que rien ne le dise.
        $ecran = file_get_contents(base_path('app/Modules/Admin/Vues/factures/vente.blade.php'));

        foreach (\App\Modules\Admin\Services\DocumentPdfService::MODELES as $numero => $modele) {
            $this->assertStringContainsString(
                $modele['couleur'],
                $ecran,
                "La teinte du modèle {$numero} ne figure plus dans `getThemeColors()`."
            );
        }
    }

    // ══════════════ La pièce d'autrui ══════════════

    public function test_la_piece_d_une_autre_entreprise_reste_introuvable(): void
    {
        $autre = Entreprise::create([
            'nom' => 'Autre SARL', 'regime_imposition' => 'RSI', 'adresse' => 'Yopougon',
            'rccm' => 'CI-ABJ-2020-B-1', 'ncc' => '9999999X', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
        ]);
        $sonSite = PointDeVente::create([
            'entreprise_id' => $autre->id, 'nom' => 'Ailleurs', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);
        $saFacture = Vente::create([
            'point_de_vente_id' => $sonSite->id, 'numero_facture' => 'VTE-AUTRE-001',
            'date_vente' => now()->toDateString(), 'etape' => 'Facture',
            'montant_ht' => 100, 'montant_tva' => 18, 'montant_ttc' => 118,
            'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $this->get(route('admin.ventes.pdf', $saFacture))->assertNotFound();
        $this->get(route('admin.ventes.ticket.pdf', $saFacture))->assertNotFound();
    }

    // ══════════════ Le nom du fichier ══════════════

    public function test_le_nom_du_fichier_prefere_le_numero_de_la_dgi(): void
    {
        $vente = $this->facture(['numero_fne' => '1864699A26000000123', 'normalise' => true]);

        // C'est sous ce numéro que l'administration connaît la pièce.
        $this->assertSame('1864699A26000000123.pdf', DocumentPdfService::nomDuFichier($vente));
        $this->assertSame('1864699A26000000123-recu.pdf', DocumentPdfService::nomDuFichier($vente, '-recu'));
    }

    public function test_un_numero_de_piece_ne_peut_pas_choisir_le_nom_du_fichier(): void
    {
        // Simulation d'attaque : un numéro de pièce est saisi. S'il traversait
        // tel quel l'en-tête `Content-Disposition`, un `"` refermerait le nom et
        // un saut de ligne ouvrirait un second en-tête.
        $vente = $this->facture(['numero_facture' => "VTE\"-001\r\nX-Injecte: oui"]);

        $nom = DocumentPdfService::nomDuFichier($vente);

        $this->assertStringNotContainsString('"', $nom);
        $this->assertStringNotContainsString("\r", $nom);
        $this->assertStringNotContainsString("\n", $nom);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.pdf$/', $nom);
    }

    /**
     * Le gabarit du PDF, rendu en HTML.
     *
     * Ouvrir le PDF produit pour y chercher un mot demanderait un lecteur de
     * PDF ; le gabarit, lui, dit exactement ce que dompdf va dessiner, et c'est
     * là que se décide ce qui figure sur le papier.
     */
    private function rendreLeGabarit(Vente $vente, int $modele = 1): string
    {
        $service = app(DocumentPdfService::class);
        $methode = new \ReflectionMethod($service, 'donneesVente');
        $methode->setAccessible(true);

        $donnees = $methode->invoke($service, $vente->fresh(['details.produit', 'client', 'pointDeVente.entreprise']), 0.0);
        $donnees['modele'] = DocumentPdfService::modele($modele);

        return view('admin::factures.pdf.document', $donnees)->render();
    }
}
