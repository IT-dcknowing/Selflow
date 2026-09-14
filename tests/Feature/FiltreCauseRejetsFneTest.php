<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le tri des rejets par cause, à l'écran.
 *
 * ## Pourquoi
 *
 * Une coupure réseau et un refus de la DGI se ressemblaient à l'écran : même
 * étiquette « À traiter », même bouton « Rapprocher ». Or l'un se répare en
 * renvoyant la pièce, l'autre en corrigeant une donnée déclarée au portail.
 * Les confondre, c'est chercher un écart de paramétrage là où il n'y a eu
 * qu'une connexion perdue.
 *
 * Ce qui se vérifie ici :
 *
 * 1. **Le filtre trie**, et la cause est visible sans filtrer.
 * 2. **Un rejet réseau n'ouvre aucune demande de relevé.** C'est le défaut
 *    d'origine : le scraper partait sur le portail parce qu'un réseau avait
 *    bougé, et le rapprochement comparait ce qu'aucune DGI n'avait mis en cause.
 * 3. **Un rejet réseau ne se rapproche pas.** Ni par le bouton, ni par la route.
 * 4. **Un filtre vide ne se lit pas comme « aucun rejet ».** Un écran vide est
 *    une bonne nouvelle ; il ne doit pas l'être par accident.
 * 5. **Une cause inventée dans l'URL ne filtre rien**, plutôt que de rendre une
 *    liste vide.
 */
class FiltreCauseRejetsFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'comptabilite', 'points_de_vente'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'FACTURATION SIEGE', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $utilisateur = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    public function test_la_cause_est_consignee_et_visible_sans_filtrer(): void
    {
        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());
        FneRejet::consigner($this->uneVente('FA-0002'), $this->coupureReseau());
        FneRejet::consigner($this->uneVente('FA-0003'), $this->refusLocal());

        $reponse = $this->get(route('admin.fne.rejets'));

        $reponse->assertOk();
        $reponse->assertSee('FA-0001');
        $reponse->assertSee('FA-0002');
        $reponse->assertSee('FA-0003');
        $reponse->assertSee('Refus DGI');
        $reponse->assertSee('Réseau');
        $reponse->assertSee('Bloqué ici');
    }

    public function test_le_filtre_ne_montre_que_la_cause_demandee(): void
    {
        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());
        FneRejet::consigner($this->uneVente('FA-0002'), $this->coupureReseau());

        $reseau = $this->get(route('admin.fne.rejets', ['cause' => FneRejet::CAUSE_RESEAU]));
        $reseau->assertOk();
        $reseau->assertSee('FA-0002');
        $reseau->assertDontSee('FA-0001');

        $dgi = $this->get(route('admin.fne.rejets', ['cause' => FneRejet::CAUSE_DGI]));
        $dgi->assertOk();
        $dgi->assertSee('FA-0001');
        $dgi->assertDontSee('FA-0002');
    }

    public function test_une_coupure_reseau_nouvre_aucune_demande_de_releve(): void
    {
        // Le défaut d'origine : n'importe quel `success: false` ouvrait un
        // relevé du portail, et le scraper partait travailler pour rien.
        FneRejet::consigner($this->uneVente('FA-0002'), $this->coupureReseau());

        $this->assertSame(0, PortailFneDemande::count());
    }

    public function test_un_refus_local_nouvre_aucune_demande_de_releve(): void
    {
        // Une clé API absente ou un taux hors barème se corrigent ici : le
        // portail n'a rien à en dire, la pièce n'est jamais partie.
        FneRejet::consigner($this->uneVente('FA-0003'), $this->refusLocal());

        $this->assertSame(0, PortailFneDemande::count());
    }

    public function test_un_refus_de_la_dgi_ouvre_bien_la_demande(): void
    {
        // Le seul cas qui la justifie — et il ne doit pas avoir été perdu au
        // passage.
        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());

        $this->assertSame(1, PortailFneDemande::where('login', '1864699A')->count());
    }

    public function test_un_rejet_reseau_ne_se_rapproche_pas(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0002'), $this->coupureReseau());

        // Le bouton est retiré de l'écran…
        $this->get(route('admin.fne.rejets', ['cause' => FneRejet::CAUSE_RESEAU]))
            ->assertDontSee(route('admin.fne.rejets.diagnostiquer', $rejet));

        // …et la route refuse, parce qu'un navigateur poste ce qu'il veut.
        $this->post(route('admin.fne.rejets.diagnostiquer', $rejet))
            ->assertRedirect()
            ->assertSessionHas('erreur');

        $this->assertNull($rejet->fresh()->diagnostic);
        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->fresh()->statut);
    }

    public function test_un_filtre_vide_ne_se_lit_pas_comme_aucun_rejet(): void
    {
        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());

        $reponse = $this->get(route('admin.fne.rejets', ['cause' => FneRejet::CAUSE_RESEAU]));

        $reponse->assertOk();
        $reponse->assertSee('Aucun rejet pour cette cause');
        $reponse->assertDontSee('Aucune pièce refusée par la plateforme');
    }

    public function test_une_cause_inventee_dans_lurl_ne_filtre_rien(): void
    {
        FneRejet::consigner($this->uneVente('FA-0001'), $this->refusDgi());
        FneRejet::consigner($this->uneVente('FA-0002'), $this->coupureReseau());

        $reponse = $this->get(route('admin.fne.rejets', ['cause' => 'martienne']));

        $reponse->assertOk();
        $reponse->assertSee('FA-0001');
        $reponse->assertSee('FA-0002');
    }

    public function test_les_rejets_sans_cause_restent_atteignables(): void
    {
        // Les lignes consignées avant que la colonne existe. Sans une entrée
        // qui les désigne, elles disparaissent de l'écran dès qu'on filtre.
        $rejet = FneRejet::consigner($this->uneVente('FA-0000'), $this->refusDgi());
        $rejet->update(['cause' => null]);

        $reponse = $this->get(route('admin.fne.rejets', ['cause' => 'non-classes']));

        $reponse->assertOk();
        $reponse->assertSee('FA-0000');
        $reponse->assertSee('Cause inconnue');
    }

    /* ------------------------------- Fixtures ------------------------------ */

    private function uneVente(string $numero): Vente
    {
        return Vente::create([
            'point_de_vente_id' => $this->magasin->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht' => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape' => 'Facture',
        ]);
    }

    /** Ce que rend FneService quand la DGI examine et refuse. */
    private function refusDgi(): array
    {
        return [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : Invalid point of sale.',
            'errors'  => ['api_error' => json_encode([
                'errors' => ['pointOfSale' => ['invalid' => 'Point of sale is invalid']],
            ])],
        ];
    }

    /** Ce que rend FneService quand l'appel n'aboutit pas (FneService.php:276). */
    private function coupureReseau(): array
    {
        return [
            'success' => false,
            'message' => "Exception lors de l'appel API FNE : cURL error 28: Operation timed out",
        ];
    }

    /** Ce que rend FneService quand il refuse d'envoyer (FneService.php:630). */
    private function refusLocal(): array
    {
        return [
            'success' => false,
            'message' => "Normalisation refusée : la DGI n'accepte que les taux de TVA 18 %, 9 % et 0 %.",
            'errors'  => ['taux_tva' => ['Ligne 2 (5 %)']],
        ];
    }
}
