<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Combien de fois par minute chaque porte peut être poussée.
 *
 * La connexion comptait ses échecs — cinq essais par couple adresse/adresse IP,
 * et c'était juste. **Tout le reste était libre**, et cinq portes le méritaient
 * particulièrement :
 *
 * - **les cinquante et une routes d'API.** Le groupe `api` n'existait pas :
 *   `withRouting()` n'ayant pas de volet `api`, `Route::middleware('api')` ne
 *   posait aucune limite, et l'authentification par jeton — une chaîne dans une
 *   colonne — pouvait être éprouvée sans compter ;
 * - **les routes de synchronisation externe**, qui n'ont aucune
 *   authentification : un secret partagé les protège, et `list-companies` rend
 *   **toutes les entreprises de la plateforme avec leur administrateur** ;
 * - **la réinitialisation de mot de passe**, qui envoie un courriel ;
 * - **l'import**, un fichier de cinq mégaoctets lu ligne à ligne ;
 * - **la normalisation par lot**, qui appelle la plateforme de la DGI : la
 *   marteler expose l'entreprise à voir **sa propre clé** ralentie ou coupée.
 */
class LimitesDeDebitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('');
    }

    /**
     * Les noms de limiteurs qu'une route peut réclamer.
     *
     * @return array<int, array<int, string>>
     */
    public static function lesLimiteurs(): array
    {
        return [['api'], ['mot-de-passe'], ['import'], ['plateforme'], ['externe'], ['web']];
    }

    // ══════════════ Les limiteurs existent ══════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('lesLimiteurs')]
    public function test_chaque_limiteur_reclame_par_une_route_est_declare(string $nom): void
    {
        // **`throttle:api` sur une route dont le limiteur n'est pas déclaré
        // lève une erreur au premier appel, non au démarrage** : le défaut ne
        // se verrait qu'en production, sur la première requête.
        $this->assertNotNull(
            app(\Illuminate\Cache\RateLimiter::class)->limiter($nom),
            "Le limiteur « {$nom} » est réclamé par une route mais n'est déclaré nulle part."
        );
    }

    public function test_les_routes_d_api_finissent_par_etre_bornees(): void
    {
        // **Le groupe `api` n'existait pas.** Les modules posent leurs routes
        // avec `Route::middleware('api')`, mais `withRouting()` n'ayant pas de
        // volet `api`, le groupe n'était jamais défini : les cinquante et une
        // routes d'API n'avaient donc aucune limite, et l'authentification par
        // jeton — une chaîne dans une colonne — pouvait être éprouvée sans
        // compter.
        //
        // L'épreuve est ici de comportement, non de configuration : c'est la
        // réponse du serveur qui fait foi, non la façon dont le groupe est
        // enregistré.
        $bornes = 0;

        for ($appel = 0; $appel < 70; $appel++) {
            if ($this->getJson('/api/admin/ventes/factures')->status() === 429) {
                $bornes++;
            }
        }

        $this->assertGreaterThan(0, $bornes,
            'Soixante-dix appels d\'affilée à une route d\'API doivent finir par être bornés.');
    }

    public function test_toute_route_d_api_porte_le_groupe_api(): void
    {
        $sansLimite = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/') && !in_array('api', $route->middleware(), true)) {
                $sansLimite[] = $route->uri();
            }
        }

        $this->assertSame([], $sansLimite,
            'Ces routes d\'API échappent au groupe qui porte la limite : ' . implode(', ', $sansLimite));
    }

    // ══════════════ Les portes sans authentification ══════════════

    public function test_la_synchronisation_externe_est_bornee(): void
    {
        // **La porte la plus précieuse de l'application** : aucune
        // authentification, un secret partagé, et une réponse qui rend toutes
        // les entreprises de la plateforme avec leur administrateur.
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'api.external.list-companies');

        $this->assertNotNull($route);
        $this->assertContains('throttle:externe', $route->middleware());
    }

    public function test_le_secret_externe_finit_par_etre_refuse_a_force_d_essais(): void
    {
        // Sans borne, le secret s'éprouve à la vitesse du réseau.
        config(['selflow.comptaflow_api_secret' => 'le-vrai-secret']);

        $refuses = 0;
        $bornes  = 0;

        for ($essai = 0; $essai < 25; $essai++) {
            $reponse = $this->postJson(route('api.external.list-companies'), [
                'secret' => 'tentative-' . $essai,
            ]);

            if ($reponse->status() === 429) {
                $bornes++;
            } elseif ($reponse->status() === 401) {
                $refuses++;
            }
        }

        $this->assertGreaterThan(0, $bornes,
            'Vingt-cinq tentatives de secret doivent finir par être bornées.');
        $this->assertLessThanOrEqual(20, $refuses,
            'Pas plus de vingt tentatives ne doivent atteindre la vérification du secret.');
    }

    public function test_la_demande_de_reinitialisation_est_bornee(): void
    {
        // Demander un lien envoie un courriel : sans borne, on inonde la boîte
        // d'un utilisateur, on épuise le quota d'envoi, et l'on apprend au
        // passage quelles adresses existent.
        $bornes = 0;

        for ($essai = 0; $essai < 15; $essai++) {
            $reponse = $this->post(route('password.email'), ['email' => "essai{$essai}@exemple.ci"]);

            if ($reponse->status() === 429) {
                $bornes++;
            }
        }

        $this->assertGreaterThan(0, $bornes,
            'Quinze demandes d\'affilée doivent finir par être bornées.');
    }

    // ══════════════ Les portes coûteuses ══════════════

    public function test_l_import_est_borne(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'admin.import.importer');

        $this->assertNotNull($route);
        $this->assertContains('throttle:import', $route->middleware());
    }

    public function test_les_appels_a_la_plateforme_sont_bornes(): void
    {
        // La marteler expose l'entreprise à voir sa propre clé ralentie ou
        // coupée par la DGI : la conséquence est chez elle, pas chez nous.
        // `admin.fne.stickers.acheter` figurait ici. L'écran des stickers a été
        // retiré le 25/09/2026 : ce n'était pas Selflow qui vendait les
        // vignettes, et il n'en tenait qu'un journal parallèle.
        foreach (['admin.fne.batch_normaliser', 'admin.fne.schedule_batch',
                  'admin.entreprise.fne.tester_connexion',
                  'admin.entreprise.comptaflow.sync_real',
                  // Le déversement de l'historique appelle Comptaflow autant de
                  // fois qu'il y a d'opérations : le marteler est aussi coûteux.
                  'admin.entreprise.comptaflow.deverser'] as $nom) {
            $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === $nom);

            $this->assertNotNull($route, "Route introuvable : {$nom}");
            $this->assertContains('throttle:plateforme', $route->middleware(),
                "La route {$nom} appelle un service extérieur sans borne.");
        }
    }

    // ══════════════ Ce qui ne doit pas gêner ══════════════

    public function test_un_utilisateur_qui_travaille_vite_n_est_pas_arrete(): void
    {
        // Le verrouillage ne doit pas gagner le travail ordinaire : un caissier
        // qui enchaîne les ventes n'a pas à voir passer une page d'erreur.
        $entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Bandama',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Cocody, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00888',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'produits'],
        ]);

        $magasin = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $caissier = Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Amos', 'email' => 'amos@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $magasin->id,
            'habilitations' => ['nouvelle_vente'],
        ]);

        $this->actingAs($caissier);

        for ($appel = 0; $appel < 40; $appel++) {
            $this->get(route('caissier.ventes.nouvelle'))->assertOk();
        }
    }

    public function test_deux_acteurs_ne_partagent_pas_leur_compteur(): void
    {
        // Une limite se compte par acteur : celui qui travaille vite ne doit
        // pas fermer la porte à son collègue.
        config(['selflow.comptaflow_api_secret' => 'le-vrai-secret']);

        for ($essai = 0; $essai < 25; $essai++) {
            $this->postJson(route('api.external.list-companies'), ['secret' => 'faux'],
                ['REMOTE_ADDR' => '10.0.0.1']);
        }

        // Une autre adresse repart d'un compteur neuf : le refus qu'elle
        // reçoit est celui du secret, non celui de la limite.
        $autre = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson(route('api.external.list-companies'), ['secret' => 'faux']);

        $this->assertSame(401, $autre->status(),
            'Le compteur de la première adresse ne doit pas fermer la porte à la seconde.');
    }
}
