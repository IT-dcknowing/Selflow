<?php

namespace Tests\Feature;

use App\Modules\Admin\Controleurs\EspaceSurMesureControleur;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\EspaceSurMesure;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Selflow sur mesure (23/11/2026) : l'adresse de l'entreprise montre sa page
 * d'accueil et sa page de connexion, sans redirection, et n'ouvre qu'à ses
 * propres utilisateurs.
 */
class EspaceSurMesureTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $sotra;
    private Entreprise $autre;
    private EspaceSurMesure $espace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['selflow.sur_mesure.domaine_racine' => 'selflow.ci']);

        $this->sotra = Entreprise::create(['nom' => 'SOTRA Distribution', 'modules_actifs' => ['principal', 'ventes']]);
        $this->autre = Entreprise::create(['nom' => 'Autre Entreprise', 'modules_actifs' => ['principal', 'ventes']]);

        Utilisateur::create(['nom' => 'Koffi', 'prenom' => 'Ama', 'email' => 'ama@sotra.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->sotra->id]);
        Utilisateur::create(['nom' => 'Yao', 'prenom' => 'Kouassi', 'email' => 'yao@autre.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->autre->id]);

        $this->espace = EspaceSurMesure::create([
            'entreprise_id' => $this->sotra->id, 'actif' => true,
            'sous_domaine' => 'sotra', 'domaine' => 'gestion.sotra.ci',
            'modules' => ['accueil', 'connexion', 'couleurs'],
            'couleur_principale' => '#c2410c', 'couleur_secondaire' => '#1c1917',
            'titre_accueil' => 'Bienvenue chez SOTRA', 'message_connexion' => 'Bonjour l\'équipe SOTRA',
        ]);
    }

    public function test_le_sous_domaine_et_le_domaine_propre_montrent_l_accueil_de_l_entreprise_sans_redirection(): void
    {
        foreach (['http://sotra.selflow.ci/', 'http://gestion.sotra.ci/', 'http://www.gestion.sotra.ci/'] as $adresse) {
            $this->get($adresse)->assertOk()
                ->assertSee('Bienvenue chez SOTRA')
                ->assertSee('#c2410c', false)
                ->assertSee('DC-KNOWING');
        }

        $this->get('http://sotra.selflow.ci/connexion')->assertOk()
            ->assertSee('Bonjour l\'équipe SOTRA')
            ->assertSee('Propulsé par')
            ->assertDontSee('Se connecter avec Google');
    }

    public function test_l_adresse_de_selflow_et_un_espace_inactif_restent_standard(): void
    {
        $this->get('http://localhost/connexion')->assertOk()->assertDontSee('Bonjour l\'équipe SOTRA');

        $this->espace->update(['actif' => false]);
        $this->get('http://sotra.selflow.ci/connexion')->assertOk()->assertDontSee('Bonjour l\'équipe SOTRA');
    }

    public function test_sans_le_module_accueil_l_adresse_ouvre_la_connexion(): void
    {
        $this->espace->update(['modules' => ['connexion']]);

        $this->get('http://sotra.selflow.ci/')->assertRedirect('http://sotra.selflow.ci/connexion');
    }

    public function test_seuls_les_utilisateurs_de_l_entreprise_entrent_par_son_adresse(): void
    {
        $this->post('http://sotra.selflow.ci/connexion', ['email' => 'yao@autre.ci', 'password' => 'secret-de-test'])
            ->assertSessionHasErrors('connexion_erreur');
        $this->assertGuest();

        $this->post('http://sotra.selflow.ci/connexion', ['email' => 'ama@sotra.ci', 'password' => 'secret-de-test']);
        $this->assertAuthenticated();
    }

    public function test_un_compte_d_une_autre_entreprise_deja_connecte_est_deconnecte(): void
    {
        $this->actingAs(Utilisateur::where('email', 'yao@autre.ci')->first())
            ->get('http://sotra.selflow.ci/')
            ->assertRedirect('http://sotra.selflow.ci/connexion');
        $this->assertGuest();
    }

    public function test_le_superadmin_enregistre_l_espace_et_refuse_une_adresse_de_selflow(): void
    {
        Auth::login(Utilisateur::where('email', 'ama@sotra.ci')->first());
        $controleur = app(EspaceSurMesureControleur::class);
        $base = ['couleur_principale' => '#0EA5E9', 'couleur_secondaire' => '#0f172a', 'modele_accueil' => 'immersion',
            'modele_connexion' => 'plein_ecran', 'actif' => '1', 'modules' => ['accueil']];

        $controleur->enregistrer(Request::create('/', 'POST', $base + ['sous_domaine' => 'Distrib', 'domaine' => 'https://www.distrib.ci/']), $this->autre);
        $espace = EspaceSurMesure::where('entreprise_id', $this->autre->id)->first();
        $this->assertSame('distrib', $espace->sous_domaine);
        $this->assertSame('distrib.ci', $espace->domaine);
        $this->assertSame('#0ea5e9', $espace->couleur_principale);
        $this->assertSame($this->autre->id, EspaceSurMesure::pourHote('distrib.selflow.ci')?->entreprise_id, 'Le cache des adresses est rafraîchi.');

        $this->expectException(ValidationException::class);
        $controleur->enregistrer(Request::create('/', 'POST', $base + ['domaine' => 'x.selflow.ci']), $this->autre);
    }

    public function test_les_ecrans_du_superadmin_et_les_apercus_s_affichent(): void
    {
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        Auth::login(Utilisateur::where('email', 'ama@sotra.ci')->first());
        $controleur = app(EspaceSurMesureControleur::class);

        $this->assertStringContainsString('SOTRA Distribution', $controleur->index()->render());
        $this->assertStringContainsString('sotra', $controleur->modifier($this->sotra)->render());
        $this->assertStringContainsString('Autre Entreprise', $controleur->modifier($this->autre)->render());
        foreach (['horizon', 'immersion', 'epure'] as $modele) {
            $this->espace->update(['modele_accueil' => $modele]);
            $this->assertStringContainsString('Bienvenue chez SOTRA', $controleur->apercu($this->sotra, 'accueil')->render());
        }
        foreach (['panneau', 'plein_ecran', 'carte'] as $modele) {
            $this->espace->update(['modele_connexion' => $modele]);
            $this->assertStringContainsString('Bonjour', $controleur->apercu($this->sotra, 'connexion')->render());
        }
    }
}
