<?php

namespace Tests\Feature;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\DeversementHistoriqueService;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * La comptabilité des exercices antérieurs (propriétaire, 08/10/2026) :
 * l'entreprise demande, le superadministrateur accorde un ou plusieurs
 * exercices, et seuls ceux-là partent chez Comptaflow.
 */
class ExercicesAnterieursTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Utilisateur $superadmin;
    private int $annee;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        $this->annee = (int) now()->year;
        config(['selflow.comptaflow_api_secret' => 'secret-de-test']);

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie ancienne', 'regime_imposition' => 'RNI', 'adresse' => 'Treichville',
            'rccm' => 'CI-ABJ-2019-B-00042', 'ncc' => '1900042A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'comptabilite', 'points_de_vente'],
            'comptabilite_activee' => true,
        ]);
        $this->entreprise->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle-de-liaison'])->save();
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Magasin', 'ville' => 'Abidjan', 'commune' => 'Treichville']);
        $this->admin = Utilisateur::create([
            'nom' => 'Ancien', 'prenom' => 'Ali', 'email' => 'ali-exercices@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->withSession(['point_de_vente_actif_id' => $this->site->id]);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Plateforme', 'prenom' => 'Sup', 'email' => 'super-exercices@exemple.ci',
            'password' => bcrypt('x'), 'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    private function operation(string $date): Operation
    {
        $operation = Operation::creer($this->entreprise->id, $this->site->id, $date, 'test', 'VTE', 'FAC-' . $date, 'Vente');
        foreach ([['compte_debit' => '411000', 'debit' => 1000, 'credit' => 0], ['compte_credit' => '701000', 'debit' => 0, 'credit' => 1000]] as $ligne) {
            EcritureComptable::create($ligne + [
                'operation_id' => $operation->id, 'entreprise_id' => $this->entreprise->id,
                'point_de_vente_id' => $this->site->id, 'date_ecriture' => $date,
                'libelle' => 'Vente', 'reference_document' => 'FAC-' . $date, 'code_journal' => 'VTE',
            ]);
        }
        $operation->cloturerEquilibre();

        return $operation;
    }

    private function envoyees(): array
    {
        $ids = [];
        Queue::assertPushed(DeverserOperationComptaflow::class, function ($job) use (&$ids) {
            $ids[] = (new \ReflectionProperty($job, 'operationId'))->getValue($job);
            return true;
        });

        return $ids;
    }

    public function test_sans_accord_seul_l_exercice_en_cours_part(): void
    {
        $courante = $this->operation($this->annee . '-03-10');
        $this->operation(($this->annee - 2) . '-05-01');
        $this->operation(($this->annee - 1) . '-06-01');
        Queue::fake(); // chaque opération part d'elle-même à sa création : on ne regarde que le déversement

        $resultat = DeversementHistoriqueService::lancer($this->entreprise->fresh());
        $this->assertTrue($resultat['success'], $resultat['message']);

        $this->assertSame([$courante->id], $this->envoyees());
        $this->assertStringContainsString(($this->annee - 2) . ', ' . ($this->annee - 1), $resultat['message']);
        $this->assertSame([$this->annee - 2, $this->annee - 1], DeversementHistoriqueService::anneesAnterieuresNonDeversees($this->entreprise));
    }

    public function test_l_entreprise_demande_et_le_superadmin_accorde_une_partie(): void
    {
        $courante = $this->operation($this->annee . '-03-10');
        $this->operation(($this->annee - 2) . '-05-01');
        $anneeAccordee = $this->operation(($this->annee - 1) . '-06-01');

        // Un exercice sans rien à déverser ne se demande pas.
        $this->actingAs($this->admin)->postJson(route('admin.entreprise.comptaflow.exercices_anterieurs'), ['annees' => [$this->annee - 5]])
            ->assertUnprocessable();

        $this->actingAs($this->admin)->postJson(route('admin.entreprise.comptaflow.exercices_anterieurs'), [
            'annees' => [$this->annee - 2, $this->annee - 1],
        ])->assertOk()->assertJson(['success' => true]);

        $demande = DemandeExercicesAnterieurs::sole();
        $this->assertSame('en_attente', $demande->statut);

        // Pas deux fois la même année en attente.
        $this->actingAs($this->admin)->postJson(route('admin.entreprise.comptaflow.exercices_anterieurs'), ['annees' => [$this->annee - 1]])
            ->assertUnprocessable();

        // L'entreprise ne s'accorde rien elle-même.
        $this->actingAs($this->admin)->post(route('superadmin.exercices_anterieurs.valider', $demande), ['annees' => [$this->annee - 1]]);
        $this->assertSame('en_attente', $demande->fresh()->statut);

        // Le superadministrateur ne peut accorder qu'une année demandée…
        $this->actingAs($this->superadmin)->post(route('superadmin.exercices_anterieurs.valider', $demande), ['annees' => [$this->annee - 7]])
            ->assertSessionHasErrors('annees.0');

        // … et choisit d'en accorder une seule.
        $this->actingAs($this->superadmin)->post(route('superadmin.exercices_anterieurs.valider', $demande), ['annees' => [$this->annee - 1]])
            ->assertSessionHasNoErrors();
        $this->assertSame('validee', $demande->fresh()->statut);
        $this->assertSame([$this->annee - 1], DemandeExercicesAnterieurs::anneesAccordees($this->entreprise->id));

        Queue::fake();
        DeversementHistoriqueService::lancer($this->entreprise->fresh());

        $envoyees = $this->envoyees();
        sort($envoyees);
        $this->assertSame([$courante->id, $anneeAccordee->id], $envoyees, 'L\'exercice non accordé reste chez Selflow.');
    }

    public function test_tout_l_historique_ne_se_limite_pas_a_la_periode_affichee(): void
    {
        // Les écritures portent un filtre par période : à l'écran, en pleine
        // période 2026, « tout l'historique » ne voyait que 2026.
        $ancienne = $this->operation(($this->annee - 1) . '-06-01');
        DemandeExercicesAnterieurs::create([
            'entreprise_id' => $this->entreprise->id, 'annees_demandees' => [$this->annee - 1],
            'annees_accordees' => [$this->annee - 1], 'statut' => 'validee',
        ]);
        session(['active_periode_debut' => now()->startOfYear()->toDateString(), 'active_periode_fin' => now()->endOfYear()->toDateString()]);
        Queue::fake();

        $this->assertSame(1, DeversementHistoriqueService::reste($this->entreprise)['operations']);
        DeversementHistoriqueService::lancer($this->entreprise->fresh());

        $this->assertSame([$ancienne->id], $this->envoyees());
    }

    public function test_le_superadmin_refuse(): void
    {
        $this->operation(($this->annee - 1) . '-06-01');
        $demande = DemandeExercicesAnterieurs::create([
            'entreprise_id' => $this->entreprise->id, 'annees_demandees' => [$this->annee - 1], 'statut' => 'en_attente',
        ]);

        $this->actingAs($this->superadmin)->get(route('superadmin.exercices_anterieurs.index'))
            ->assertOk()->assertSee('Quincaillerie ancienne')->assertSee('Valider la sélection');

        $this->actingAs($this->superadmin)->post(route('superadmin.exercices_anterieurs.refuser', $demande), ['motif_refus' => 'Pièces manquantes'])
            ->assertSessionHasNoErrors();

        $this->assertSame('refusee', $demande->fresh()->statut);
        $this->assertSame([], DemandeExercicesAnterieurs::anneesAccordees($this->entreprise->id));
    }

    public function test_l_ecran_des_parametres_propose_la_demande(): void
    {
        $this->operation(($this->annee - 1) . '-06-01');

        $page = $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('admin.entreprise.parametres'));
        $page->assertOk()
            ->assertSee('Déverser tout l\'historique', false)
            ->assertSee('Comptabilité des exercices antérieurs')
            ->assertSee('value="' . ($this->annee - 1) . '" data-exercice-anterieur', false)
            ->assertSee('Demander ces exercices');
    }
}
