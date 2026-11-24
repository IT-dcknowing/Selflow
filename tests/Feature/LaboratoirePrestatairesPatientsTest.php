<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VentePatient;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laboratoire d'analyses (24/11/2026) : le prestataire externe (hôpital) paie,
 * les patients sont rattachés ; sans prestataire, une facture par patient.
 */
class LaboratoirePrestatairesPatientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $labo;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Produit $nfs;
    private Produit $glycemie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->labo = Entreprise::create(['nom' => 'Labo Biologie', 'modules_actifs' => ['principal', 'ventes', 'tiers', 'produits']]);
        $this->labo->forceFill(['attributions' => ['apporteurs']])->save();
        $this->site = PointDeVente::create(['entreprise_id' => $this->labo->id, 'nom' => 'Labo Plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create(['nom' => 'B', 'prenom' => 'A', 'email' => 'labo@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif',
            'entreprise_id' => $this->labo->id, 'point_de_vente_id' => $this->site->id]);
        $this->nfs = Produit::create(['entreprise_id' => $this->labo->id, 'reference' => 'NFS', 'nom' => 'Numération formule sanguine', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 5000, 'taux_tva' => 0]);
        $this->glycemie = Produit::create(['entreprise_id' => $this->labo->id, 'reference' => 'GLY', 'nom' => 'Glycémie', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 2000, 'taux_tva' => 0]);
    }

    private function vendre(array $champs): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->post(route('admin.ventes.enregistrer'), array_merge([
                'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit', 'etape' => 'Facture',
            ], $champs));
    }

    public function test_le_prestataire_paie_et_les_patients_sont_rattaches_meme_nouveaux(): void
    {
        $aya = Client::create(['entreprise_id' => $this->labo->id, 'nom' => 'Kouassi Aya']);

        $this->vendre([
            'prestataire_nom' => 'CHU de Cocody',
            'patients' => ['id:' . $aya->id, 'nom:Koné Ali'],
            'articles' => [
                ['produit_id' => $this->nfs->id, 'quantite' => 1, 'unite' => 'u', 'patient' => 'id:' . $aya->id],
                ['produit_id' => $this->glycemie->id, 'quantite' => 1, 'unite' => 'u', 'patient' => 'nom:Koné Ali'],
            ],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $chu = Client::where('nom', 'CHU de Cocody')->sole();
        $this->assertTrue((bool) $chu->est_apporteur, 'Le prestataire tapé est créé comme prestataire externe.');
        $ali = Client::where('nom', 'Koné Ali')->sole();
        $this->assertFalse((bool) $ali->est_apporteur);

        $vente = Vente::sole();
        $this->assertSame($chu->id, (int) $vente->client_id, 'Le prestataire est le payeur (la facture et la FNE sont à son nom).');
        $this->assertSame($chu->id, (int) $vente->apporteur_id);
        $this->assertEqualsCanonicalizing([$aya->id, $ali->id], VentePatient::where('vente_id', $vente->id)->pluck('client_id')->all());

        $detail = collect(VentePatient::detailPour($vente->id))->keyBy('nom');
        $this->assertEqualsWithDelta(5000, $detail['Kouassi Aya']['total'], 0.01);
        $this->assertEqualsWithDelta(2000, $detail['Koné Ali']['total'], 0.01);

        $this->get(route('admin.prestataires_externes.index'))->assertOk()->assertSee('CHU de Cocody');
        $this->get(route('admin.clients.index', ['type_tiers' => 'prestataires']))->assertOk()->assertSee('CHU de Cocody')->assertDontSee('Koné Ali');
    }

    public function test_sans_prestataire_une_facture_par_patient(): void
    {
        $this->vendre([
            'patients' => ['nom:Kouassi Aya', 'nom:Koné Ali'],
            'articles' => [
                ['produit_id' => $this->nfs->id, 'quantite' => 1, 'unite' => 'u', 'patient' => 'nom:Kouassi Aya'],
                ['produit_id' => $this->glycemie->id, 'quantite' => 1, 'unite' => 'u', 'patient' => 'nom:Koné Ali'],
            ],
        ])->assertSessionHas('succes', fn ($m) => str_contains($m, '2 facture(s)'));

        $ventes = Vente::orderBy('id')->get();
        $this->assertCount(2, $ventes);
        $this->assertSame(['Kouassi Aya', 'Koné Ali'], $ventes->map(fn ($v) => Client::find($v->client_id)->nom)->all(), 'Chaque patient paie sa facture.');
        $this->assertNull($ventes[0]->apporteur_id);
        $this->assertEqualsWithDelta(5000, (float) $ventes[0]->montant_ttc, 0.01);
        $this->assertEqualsWithDelta(2000, (float) $ventes[1]->montant_ttc, 0.01);
    }

    public function test_sans_prestataire_chaque_analyse_doit_avoir_son_patient(): void
    {
        $this->vendre([
            'patients' => ['nom:Kouassi Aya', 'nom:Koné Ali'],
            'articles' => [['produit_id' => $this->nfs->id, 'quantite' => 1, 'unite' => 'u']],
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'affectez chaque analyse'));
        $this->assertSame(0, Vente::count());
    }

    public function test_il_faut_au_moins_un_prestataire_ou_un_patient(): void
    {
        $this->vendre(['patients' => [], 'articles' => [['produit_id' => $this->nfs->id, 'quantite' => 1, 'unite' => 'u']]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'au moins un patient'));
        $this->assertSame(0, Vente::count());
    }

    public function test_le_formulaire_propose_les_deux_champs(): void
    {
        $this->actingAs($this->admin)->get(route('admin.ventes.nouvelle'))->assertOk()
            ->assertSee('Prestataire externe (payeur)')->assertSee('Patient(s)');
    }
}
