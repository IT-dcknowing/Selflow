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
            'abonnement.actif' => \App\Modules\Authentification\Middleware\VerifierAbonnementActif::class,
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
            if ($request->is('api/*') || ! \App\Exceptions\Panne::estUne($e)) {
                return null;
            }

            // **L'utilisateur ne voit plus l'erreur** (décision du 15/10/2026) :
            // une page « en maintenance », la référence et les contacts de
            // l'assistance. Le détail va au superadministrateur, dans le
            // registre des pannes. La trace de Laravel ne reste qu'au poste
            // du développeur, ou au superadministrateur quand le débogage est
            // allumé.
            $panne = \App\Modules\Admin\Modeles\PanneConsignee::consigner($e, $request);

            $traceAutorisee = config('app.debug') && (
                app()->environment('local', 'testing')
                || (bool) $request->user()?->estSuperAdmin()
            );
            if ($traceAutorisee) {
                return null;
            }

            // Une alerte par courriel, comme avant — sans jamais bloquer la page.
            try {
                $msg  = "Alerte Production : Panne détectée sur Selflow\n\n";
                $msg .= "Référence : " . \App\Exceptions\Panne::reference($e) . "\n";
                $msg .= "URL de la page : " . $request->fullUrl() . "\n";
                $msg .= "Erreur : " . $e->getMessage() . "\n";
                $msg .= "Fichier : " . $e->getFile() . "\n";
                $msg .= "Ligne : " . $e->getLine() . "\n\n";
                $msg .= "Le détail est au registre des pannes du superadministrateur.\n";

                \Illuminate\Support\Facades\Mail::raw($msg, function ($mail) {
                    $mail->to(['meledjeagnimel17@gmail.com', 'it.dcknowin@gmail.com'])
                         ->subject("[Selflow - Alerte Technique] Dysfonctionnement détecté en production");
                });
            } catch (\Throwable $mailError) {
                // L'envoi peut échouer (SMTP non configuré) : la page s'affiche quand même.
            }

            return response()->view('errors.500', [
                'reference' => \App\Exceptions\Panne::reference($e),
                'assistance' => [
                    'email'     => \App\Modules\Admin\Modeles\ParametrePlateforme::lire(\App\Modules\Admin\Modeles\ParametrePlateforme::ASSISTANCE_EMAIL),
                    'telephone' => \App\Modules\Admin\Modeles\ParametrePlateforme::lire(\App\Modules\Admin\Modeles\ParametrePlateforme::ASSISTANCE_TELEPHONE),
                ],
            ], 500);
        });
    })->create();
