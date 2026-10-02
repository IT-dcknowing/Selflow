<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'            => \App\Modules\Authentification\Middleware\VerifierRole::class,
            'apercu.readonly' => \App\Modules\Authentification\Middleware\ApercuLectureSeule::class,
            'caissier.acces'  => \App\Modules\Authentification\Middleware\VerifierAccesCaissier::class,
            'habilitation'    => \App\Modules\Authentification\Middleware\VerifierHabilitationRoute::class,
            'auth.api'        => \App\Modules\Authentification\Middleware\AuthentificationApiParJeton::class,
            'periode'         => \App\Modules\Authentification\Middleware\GestionPeriode::class,
            'modules'         => \App\Modules\Authentification\Middleware\VerifierModulesActifs::class,
            'forcer.mdp'      => \App\Modules\Authentification\Middleware\ForcerChangementMotDePasse::class,
            'hub.token'       => \App\Modules\Authentification\Middleware\VerifierJetonHub::class,
            'verify.hub.token'   => \App\Http\Middleware\VerifyHubToken::class,
            'inscription.complete' => \App\Modules\Authentification\Middleware\VerifierInscriptionComplete::class,
            // Les écrans comptables ne s'ouvrent qu'à qui a demandé la
            // comptabilité, ou à qui le superadministrateur l'a accordée.
            'comptabilite'    => \App\Modules\Authentification\Middleware\VerifierComptabiliteOuverte::class,
        ]);


        // **Le groupe `api` n'existait pas.** Les modules posent leurs routes
        // avec `Route::middleware('api')`, mais `withRouting()` n'ayant pas de
        // volet `api`, le groupe n'était jamais défini : les cinquante et une
        // routes d'API n'avaient donc **aucune limite de débit**, et
        // l'authentification par jeton pouvait être éprouvée sans compter.
        $middleware->group('api', [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Les écrans ordinaires : large, mais borné. Une boucle dans une page
        // ne doit pas pouvoir occuper le serveur à elle seule.
        $middleware->appendToGroup('web', 'throttle:web');

        // En-têtes de sécurité HTTP sur toutes les réponses web (Section 17.11)
        $middleware->appendToGroup('web', \App\Http\Middleware\AjouterEntetesSecurite::class);

        // Vérifier si l'entreprise rattachée est active ou bloquée
        $middleware->appendToGroup('web', \App\Http\Middleware\VerifierEntrepriseActive::class);

        // Forcer le changement de mot de passe à la 1ère connexion (Section 13)
        $middleware->appendToGroup('web', \App\Modules\Authentification\Middleware\ForcerChangementMotDePasse::class);

        $middleware->redirectTo(function (Request $request) {
            if ($request->is('api/*')) {
                abort(response()->json([
                    'statut'  => 'erreur',
                    'message' => 'Non authentifié.'
                ], 401));
            }
            return route('connexion');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\Throwable $e, Request $request) {
            // Uniquement pour les requêtes web non-API en production
            if (!config('app.debug') && !$request->is('api/*') && \App\Exceptions\Panne::estUne($e)) {
                // Tenter d'envoyer l'alerte email aux super-admins
                try {
                    $url = $request->fullUrl();
                    $msg  = "Alerte Production : Panne détectée sur Selflow\n\n";
                    // La même référence que celle affichée à l'utilisateur :
                    // c'est par elle que les deux bouts se rejoignent.
                    $msg .= "Référence : " . \App\Exceptions\Panne::reference($e) . "\n";
                    $msg .= "URL de la page : " . $url . "\n";
                    $msg .= "Erreur : " . $e->getMessage() . "\n";
                    $msg .= "Fichier : " . $e->getFile() . "\n";
                    $msg .= "Ligne : " . $e->getLine() . "\n\n";
                    $msg .= "Trace d'exécution :\n" . substr($e->getTraceAsString(), 0, 1500) . "\n";

                    \Illuminate\Support\Facades\Mail::raw($msg, function ($mail) {
                        $mail->to(['meledjeagnimel17@gmail.com', 'it.dcknowin@gmail.com'])
                             ->subject("[Selflow - Alerte Technique] Dysfonctionnement détecté en production");
                    });
                } catch (\Throwable $mailError) {
                    // Tolérance aux pannes : ignorer si l'envoi d'email échoue (ex: SMTP non configuré)
                }

                // **La page de panne remplace la trace de Laravel.** Sur un
                // écran de caisse, celle-ci montrait le nom des fichiers du
                // serveur, les versions des bibliothèques et le contenu des
                // variables : illisible pour l'utilisateur, et trop lisible
                // pour qui passait par là.
                //
                // La page porte le message d'attente, une référence à donner au
                // service informatique, et le détail replié.
                return response()->view('errors.500', [
                    'reference' => \App\Exceptions\Panne::reference($e),
                    'detail'    => [
                        'type'    => $e::class,
                        'message' => $e->getMessage(),
                        'fichier' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getFile()),
                        'ligne'   => $e->getLine(),
                        'url'     => $request->fullUrl(),
                        'moment'  => now()->format('d/m/Y à H:i:s'),
                        // Les chemins sont ramenés à la racine du projet. La
                        // suite des appels est désormais visible de tous : elle
                        // n'a pas à dire sous quel compte le serveur tourne ni
                        // où le projet est installé, seulement quel fichier a
                        // appelé quel autre.
                        'trace'   => substr(
                            str_replace(base_path() . DIRECTORY_SEPARATOR, '', $e->getTraceAsString()),
                            0,
                            4000
                        ),
                    ],
                ], 500);
            }
        });
    })->create();
