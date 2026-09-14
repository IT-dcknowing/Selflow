<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran qui rend le rapprochement lisible.
 *
 * Le mécanisme écrivait déjà son constat chaque heure ; personne ne pouvait le
 * lire. Ce qui se vérifie ici :
 *
 * 1. **L'écran nomme les deux valeurs.** C'est tout l'intérêt : « vous avez
 *    envoyé X, le portail déclare Y » à la place d'un code.
 * 2. **La correction descriptive s'applique, et une seule fois.** Renommer un
 *    point de vente est le même geste que depuis l'écran des points de vente ;
 *    la valeur du portail est simplement affichée en face.
 * 3. **Les écarts fiscaux ne s'appliquent pas.** `timbre_quittance`, `bapa` et
 *    `sticker_solde_alerte` sont montrés, jamais recopiés — aucune route ne
 *    permet de le faire.
 * 4. **Un rejet se referme quand la pièce passe.** Sans cela l'écran afficherait
 *    pour toujours un refus corrigé depuis longtemps, et une file qui ne se vide
 *    jamais cesse d'être lue.
 * 5. **Les rejets d'autrui n'existent pas.** Un rejet porte le nom d'un point de
 *    vente et le message de la DGI : c'est de l'information commerciale.
 */
class EcranRejetsFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $mienne;
    private PointDeVente $monMagasin;
    private Utilisateur $moi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mienne = $this->uneEntreprise('DC-KNOWING CGA', 'CI-ABJ-2026-B-01111', '1864699A');

        $this->monMagasin = PointDeVente::create([
            'entreprise_id' => $this->mienne->id,
            'nom'           => 'FACTURATION SIEGE',
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
        ]);

        $this->moi = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->mienne->id,
            'point_de_vente_id' => $this->monMagasin->id,
        ]);

        $this->actingAs($this->moi)
            ->withSession(['point_de_vente_actif_id' => $this->monMagasin->id]);
    }

    public function test_l_ecran_nomme_la_valeur_envoyee_et_celle_du_portail(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());
        $this->unReleve();

        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet))->assertRedirect();

        $reponse = $this->get(route('admin.fne.rejets'));

        $reponse->assertOk();
        $reponse->assertSee('FA-0042');
        $reponse->assertSee('FACTURATION SIEGE');
        $reponse->assertSee('FACTURATION SIÈGE', false);
    }

    public function test_la_correction_rattache_la_piece_au_point_du_portail_et_rouvre_le_rejet(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());
        $this->unReleve();
        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet));

        $this->post(route('admin.fne.rejets.appliquer', $rejet))->assertRedirect();

        // Le point d'origine n'est jamais renommé (règle posée en 2e50585) : le
        // point déclaré au portail est créé, et la pièce lui est rattachée.
        $this->assertSame('FACTURATION SIEGE', $this->monMagasin->refresh()->nom);
        $this->assertSame('FACTURATION SIÈGE', $vente->refresh()->pointDeVente->nom);

        // Le diagnostic décrivait un écart qui n'existe plus : le rejet
        // retourne en attente d'un rapprochement plutôt que d'afficher un
        // constat périmé.
        $rejet->refresh();
        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->statut);
        $this->assertNull($rejet->diagnostic);
    }

    public function test_la_correction_ne_s_offre_pas_quand_le_portail_declare_plusieurs_points(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());
        $this->unReleve(['FACTURATION SIÈGE', 'FACTURATION ANNEXE']);
        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet));

        // La machine ne choisit pas le point de vente à la place de qui a
        // établi la pièce.
        $this->post(route('admin.fne.rejets.appliquer', $rejet))
            ->assertSessionHas('erreur');

        $this->assertSame('FACTURATION SIEGE', $this->monMagasin->refresh()->nom);
    }

    public function test_aucune_route_n_applique_un_ecart_fiscal(): void
    {
        $this->mienne->update(['timbre_quittance' => false, 'sticker_solde_alerte' => 5]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());
        $this->unReleve();
        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet));

        // L'écran les montre...
        $this->get(route('admin.fne.rejets'))
            ->assertSee('timbre_quittance')
            ->assertSee('sticker_solde_alerte');

        // ...et « appliquer », le seul geste offert, ne touche qu'au nom du
        // point de vente.
        $this->post(route('admin.fne.rejets.appliquer', $rejet));

        $this->mienne->refresh();
        $this->assertFalse((bool) $this->mienne->timbre_quittance);
        $this->assertSame(5, (int) $this->mienne->sticker_solde_alerte);
    }

    public function test_un_rejet_se_referme_quand_la_piece_passe(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());

        $this->assertSame(1, FneRejet::resoudre($vente));
        $this->assertSame(FneRejet::STATUT_RESOLU, $rejet->refresh()->statut);

        // Une pièce qui repasse ne referme rien de nouveau.
        $this->assertSame(0, FneRejet::resoudre($vente));
    }

    public function test_le_rejet_se_classe_a_la_main(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        $this->post(route('admin.fne.rejets.resoudre', $rejet))->assertRedirect();

        $this->assertSame(FneRejet::STATUT_RESOLU, $rejet->refresh()->statut);
    }

    public function test_le_rejet_d_une_autre_entreprise_n_existe_pas(): void
    {
        $sienne = $this->uneEntreprise('Quincaillerie rivale', 'CI-ABJ-2026-B-02222', '9999999Z');

        $sonMagasin = PointDeVente::create([
            'entreprise_id' => $sienne->id,
            'nom' => 'Magasin rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $saVente = Vente::create([
            'point_de_vente_id' => $sonMagasin->id,
            'numero_facture'    => 'FA-RIVALE-001',
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);

        $sonRejet = FneRejet::consigner($saVente, $this->refus());

        // 404 et non 403 : un 403 confirmerait que la pièce existe, et les
        // identifiants sont séquentiels.
        $this->post(route('admin.fne.rejets.diagnostiquer', $sonRejet))->assertNotFound();
        $this->post(route('admin.fne.rejets.appliquer', $sonRejet))->assertNotFound();
        $this->post(route('admin.fne.rejets.resoudre', $sonRejet))->assertNotFound();

        $this->get(route('admin.fne.rejets'))->assertDontSee('FA-RIVALE-001');
    }

    public function test_sans_releve_l_ecran_le_dit_au_lieu_de_se_taire(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet))
            ->assertSessionHas('erreur');

        $this->get(route('admin.fne.rejets'))
            ->assertOk()
            ->assertSee('Aucun relevé du portail', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function refus(): array
    {
        return [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : Invalid point of sale.',
            'errors'  => ['api_error' => json_encode([
                'errors' => ['pointOfSale' => ['invalid' => 'Point of sale is invalid']],
            ])],
        ];
    }

    private function uneEntreprise(string $nom, string $rccm, string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => $rccm, 'ncc' => $ncc, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'comptabilite', 'points_de_vente'],
        ]);
    }

    private function uneVente(string $numero): Vente
    {
        return Vente::create([
            'point_de_vente_id' => $this->monMagasin->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);
    }

    /**
     * @param  array<int, string>  $noms
     */
    private function unReleve(array $noms = ['FACTURATION SIÈGE']): PortailFneImport
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->mienne->id,
            'login'             => '1864699A',
            'date_scraping'     => '2026-08-21',
            'type'              => PortailFneImport::TYPE_FICHE,
            'fichier_nom'       => '1864699A_20260821.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        PortailFneFiche::create([
            'import_id'            => $import->id,
            'entreprise_id'        => $this->mienne->id,
            'login'                => '1864699A',
            'date_scraping'        => '2026-08-21',
            'timbre_quittance'     => true,
            'sticker_solde_alerte' => 5000,
        ]);

        foreach ($noms as $nom) {
            PortailFnePointFacturation::create([
                'import_id'        => $import->id,
                'entreprise_id'    => $this->mienne->id,
                'login'            => '1864699A',
                'date_scraping'    => '2026-08-21',
                'nom'              => $nom,
                'outil'            => 'Application FNE',
                'statut'           => '1',
                'etablissement_id' => '42200613-f402-40a8-bd4d-a778bb5b96f0',
            ]);
        }

        return $import;
    }
}
