<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Le superadmin et le compte d'une entreprise passent du poste local au
 * serveur avec le même mot de passe, et sans rien de ce qui ne doit pas
 * voyager : ni clé FNE, ni liaison Comptaflow, ni données.
 */
class TransfertDesComptesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_SUPER = 'Essai-Du-Superadmin-2026';
    private const SECRET_ADMIN = 'Essai-Du-Gerant-2026';

    private string $fichier;
    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fichier = storage_path('framework/testing/transfert-' . uniqid() . '.json');

        Utilisateur::create([
            'nom' => 'ADMIN', 'prenom' => 'Super', 'email' => 'superadmin@exemple.test',
            'password' => self::SECRET_SUPER, 'role' => 'superadmin', 'statut' => 'actif',
        ]);

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'ncc' => '1864699A', 'regime_imposition' => 'RNI',
            'adresse' => 'Abidjan', 'rccm' => 'CI-ABJ-2026-B-1', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes'],
            'timbre_quittance' => true,
        ]);
        $this->entreprise->comptaflow_sync_key = 'cle-de-liaison-du-poste-local-a-ne-pas-emporter';
        $this->entreprise->save();

        Utilisateur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'KONE', 'prenom' => 'Awa',
            'email' => 'gerant@exemple.test', 'password' => self::SECRET_ADMIN,
            'role' => 'admin', 'statut' => 'actif',
        ]);
        Utilisateur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'TRAORE', 'prenom' => 'Ali',
            'email' => 'caisse@exemple.test', 'password' => 'Essai-Caisse-2026',
            'role' => 'caissier', 'statut' => 'actif',
        ]);

        PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'FACTURATION SIEGE',
            'ville' => 'Abidjan', 'commune' => 'Cocody', 'statut' => 'Ouvert',
        ]);

        FneCredential::create(['entreprise_id' => $this->entreprise->id, 'cle_test' => 'fne_cle_de_test_locale', 'statut' => 'test']);
    }

    protected function tearDown(): void
    {
        File::delete($this->fichier);
        parent::tearDown();
    }

    private function exporter(): void
    {
        $this->artisan('selflow:exporter-comptes', ['--entreprise' => ['1864699A'], '--fichier' => $this->fichier])
            ->assertExitCode(0);
    }

    /** Le serveur en ligne : aucune des lignes du poste local. */
    private function viderCommeUnServeurNeuf(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['fne_credentials', 'points_de_vente', 'utilisateurs', 'plan_comptable', 'codes_journaux', 'tax_configurations', 'entreprises'] as $table) {
            DB::table($table)->delete();
        }
        Schema::enableForeignKeyConstraints();
    }

    public function test_le_meme_mot_de_passe_ouvre_les_comptes_en_ligne(): void
    {
        $this->exporter();
        $this->viderCommeUnServeurNeuf();

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(0);

        $super = Utilisateur::where('email', 'superadmin@exemple.test')->firstOrFail();
        $this->assertSame('superadmin', $super->role);
        $this->assertTrue(Hash::check(self::SECRET_SUPER, $super->password));
        $this->assertNotEmpty($super->uuid);

        $entreprise = Entreprise::where('ncc', '1864699A')->firstOrFail();
        $gerant = Utilisateur::where('email', 'gerant@exemple.test')->firstOrFail();
        $this->assertSame($entreprise->id, $gerant->entreprise_id);
        $this->assertTrue(Hash::check(self::SECRET_ADMIN, $gerant->password));

        $this->assertSame(['principal', 'ventes'], $entreprise->modules_actifs);
        $this->assertTrue($entreprise->timbre_quittance);
        $this->assertSame(1, PointDeVente::where('entreprise_id', $entreprise->id)->where('nom', 'FACTURATION SIEGE')->count());
        $this->assertGreaterThan(0, DB::table('plan_comptable')->where('entreprise_id', $entreprise->id)->count());
    }

    public function test_ni_cle_fne_ni_liaison_ni_equipe_ne_voyagent(): void
    {
        $this->exporter();
        $brut = File::get($this->fichier);

        $this->assertStringNotContainsString('fne_cle_de_test_locale', $brut);
        $this->assertStringNotContainsString('comptaflow', $brut);
        $this->assertStringNotContainsString('caisse@exemple.test', $brut);
        $this->assertStringNotContainsString(self::SECRET_SUPER, $brut);

        $this->viderCommeUnServeurNeuf();
        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(0);

        $this->assertSame(0, FneCredential::count());
        $this->assertNull(Entreprise::where('ncc', '1864699A')->value('comptaflow_sync_key'));
    }

    public function test_relancer_l_import_ne_duplique_rien(): void
    {
        $this->exporter();
        $this->viderCommeUnServeurNeuf();

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(0);
        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(0);

        $this->assertSame(1, Entreprise::where('ncc', '1864699A')->count());
        $this->assertSame(2, Utilisateur::count());
        $this->assertSame(1, PointDeVente::count());
    }

    public function test_un_mot_de_passe_change_en_ligne_est_realigne_sur_le_poste_local(): void
    {
        $this->exporter();
        Utilisateur::where('email', 'superadmin@exemple.test')->firstOrFail()
            ->update(['password' => 'Un-Autre-Mot-De-Passe']);

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(0);

        $this->assertTrue(Hash::check(self::SECRET_SUPER, Utilisateur::where('email', 'superadmin@exemple.test')->value('password')));
    }

    public function test_simuler_n_ecrit_rien(): void
    {
        $this->exporter();
        $this->viderCommeUnServeurNeuf();

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier, '--simuler' => true])->assertExitCode(0);

        $this->assertSame(0, Utilisateur::count());
        $this->assertSame(0, Entreprise::count());
    }

    /**
     * Simulation d'attaque : le fichier est intercepté, et le rôle d'un compte
     * est changé en superadmin. L'empreinte ne correspond plus : rien n'entre.
     */
    public function test_un_fichier_retouche_est_refuse(): void
    {
        $this->exporter();
        $this->viderCommeUnServeurNeuf();

        $paquet = json_decode(File::get($this->fichier), true);
        $paquet['contenu']['entreprises'][0]['utilisateurs'][0]['role'] = 'superadmin';
        File::put($this->fichier, json_encode($paquet));

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier])->assertExitCode(1);

        $this->assertSame(0, Utilisateur::count());
    }

    public function test_sans_entreprise_designee_l_export_refuse_et_liste(): void
    {
        $this->artisan('selflow:exporter-comptes', ['--fichier' => $this->fichier])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->fichier);
    }

    public function test_effacer_supprime_le_fichier_apres_l_import(): void
    {
        $this->exporter();
        $this->viderCommeUnServeurNeuf();

        $this->artisan('selflow:importer-comptes', ['fichier' => $this->fichier, '--effacer' => true])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->fichier);
    }
}
