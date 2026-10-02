<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Referentiel\Compte;
use App\Modules\Admin\Services\TrousseauEntrepriseService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le plan comptable par défaut, complet, et posable à tout moment.
 *
 * Deux défauts se tenaient l'un l'autre.
 *
 * **Le plan livré n'était pas un plan.** L'entreprise recevait les 41 comptes
 * marqués « communs » — clients, fournisseurs, TVA, trésorerie, achats,
 * ventes, stocks. Les 1 256 comptes de l'acte uniforme restaient un
 * dictionnaire du référentiel, servant à nommer une subdivision sans jamais
 * entrer dans le plan de personne. Le compte manquait donc dès qu'on sortait
 * de l'ordinaire : une immobilisation, un emprunt, une charge de personnel, un
 * impôt autre que la TVA. Il fallait le créer à la main, en devinant son
 * numéro — et une imputation sur un compte inventé ne se rattrape pas, elle
 * traverse la balance, le grand livre et la liasse fiscale.
 *
 * **Et le trousseau ne se posait qu'une fois**, à la création de l'entreprise.
 * Une entreprise créée avant qu'un compte ou un journal entre au référentiel —
 * le mobile money, par exemple — ne l'obtenait plus par aucun chemin.
 */
class TrousseauALaDemandeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique du carrefour',
            'modules_actifs' => ['principal', 'comptabilite', 'tresorerie'],
            // Cette entreprise tient ses livres dans Selflow : depuis le
            // lot 39, cela se demande. Sans la case, les ecrans comptables
            // repondent 404 (Not Found -- introuvable).
            'comptabilite_activee' => true,
        ]);

        $site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-trousseau@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    // ── Le plan est complet ──────────────────────────────────────────

    public function test_l_entreprise_recoit_le_plan_de_l_acte_uniforme_en_entier(): void
    {
        TrousseauEntrepriseService::doter($this->entreprise);

        // Le référentiel, plus les comptes de trésorerie que les journaux
        // usuels réclament et que l'acte uniforme ne prévoit pas — le mobile
        // money, rangé en subdivision de `521`.
        $this->assertGreaterThanOrEqual(
            Compte::count(),
            PlanComptable::where('entreprise_id', $this->entreprise->id)->count()
        );
        $this->assertGreaterThan(1000, PlanComptable::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_les_comptes_hors_ordinaire_y_figurent(): void
    {
        TrousseauEntrepriseService::doter($this->entreprise);

        // Ceux qui manquaient : une immobilisation, un emprunt, une charge de
        // personnel. Chacun devait être créé à la main, son numéro deviné.
        foreach (['241000', '162000', '661000'] as $numero) {
            $this->assertDatabaseHas('plan_comptable', [
                'entreprise_id' => $this->entreprise->id,
                'numero'        => $numero,
            ]);
        }
    }

    public function test_l_intitule_contextuel_prime_sur_celui_de_l_acte_uniforme(): void
    {
        TrousseauEntrepriseService::doter($this->entreprise);

        // « État, TVA facturée (18 % — régime réel) » dit plus que « État, TVA
        // facturée » : le référentiel écrase l'intitulé générique à
        // l'ensemencement, et la dotation reprend ce qu'il y trouve.
        $libelle = PlanComptable::where('entreprise_id', $this->entreprise->id)
            ->where('numero', '443100')->value('libelle');

        $this->assertSame(Compte::where('numero', '443100')->value('intitule'), $libelle);
    }

    // ── Le bouton ────────────────────────────────────────────────────

    public function test_le_bouton_pose_ce_qui_manque_au_plan(): void
    {
        // L'entreprise n'a rien : c'est le cas de celles créées avant que le
        // trousseau existe.
        $this->assertSame(0, PlanComptable::where('entreprise_id', $this->entreprise->id)->count());

        $this->post(route('admin.comptabilite.poser_plan_defaut'))->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(
            Compte::count(),
            PlanComptable::where('entreprise_id', $this->entreprise->id)->count()
        );
    }

    public function test_le_bouton_ne_touche_pas_a_ce_que_l_entreprise_a_renomme(): void
    {
        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '411000', 'libelle' => 'Mes clients à moi',
        ]);

        $this->post(route('admin.comptabilite.poser_plan_defaut'));

        $this->assertSame('Mes clients à moi', PlanComptable::where('entreprise_id', $this->entreprise->id)
            ->where('numero', '411000')->value('libelle'));
    }

    public function test_le_bouton_ne_duplique_rien_quand_on_le_reclique(): void
    {
        $this->post(route('admin.comptabilite.poser_plan_defaut'));
        $apres = PlanComptable::where('entreprise_id', $this->entreprise->id)->count();

        $this->post(route('admin.comptabilite.poser_plan_defaut'));

        $this->assertSame($apres, PlanComptable::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_le_bouton_des_journaux_pose_les_journaux_usuels(): void
    {
        $this->post(route('admin.tresorerie.poser_journaux_defaut'))->assertSessionHasNoErrors();

        $this->assertSame(
            count(TrousseauEntrepriseService::journauxParDefaut()),
            CodeJournal::where('entreprise_id', $this->entreprise->id)->count()
        );
    }

    // ── Cloisonnement ────────────────────────────────────────────────

    public function test_le_bouton_ne_dote_que_sa_propre_entreprise(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);

        $this->post(route('admin.comptabilite.poser_plan_defaut'));

        $this->assertSame(0, PlanComptable::where('entreprise_id', $voisine->id)->count());
    }
}
