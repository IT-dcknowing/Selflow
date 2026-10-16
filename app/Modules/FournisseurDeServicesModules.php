<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class FournisseurDeServicesModules extends ServiceProvider
{
    /**
     * Enregistrer les services du module.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrapper les services du module.
     */
    public function boot(): void
    {
        $cheminModules = app_path('Modules');

        if (!is_dir($cheminModules)) {
            return;
        }

        $dossiersModules = array_filter(glob($cheminModules . '/*'), 'is_dir');

        // Routes en cache (`php artisan optimize`) : les recharger à chaque
        // requête annulait le cache — 396 routes relues pour rien (audit de
        // performance du 16/10/2026).
        $routesEnCache = $this->app->routesAreCached();

        foreach ($dossiersModules as $cheminModule) {
            $nomModule = basename($cheminModule);

            // Enregistrer les routes
            $cheminRoute = $cheminModule . '/Routes/web.php';
            if (!$routesEnCache && file_exists($cheminRoute)) {
                Route::middleware('web')
                    ->group($cheminRoute);
            }

            // Enregistrer les routes API
            $cheminRouteApi = $cheminModule . '/Routes/api.php';
            if (!$routesEnCache && file_exists($cheminRouteApi)) {
                Route::middleware('api')
                    ->prefix('api')
                    ->group($cheminRouteApi);
            }

            // Enregistrer les vues
            $cheminVues = $cheminModule . '/Vues';
            if (is_dir($cheminVues)) {
                $this->loadViewsFrom($cheminVues, strtolower($nomModule));
            }

            // Enregistrer les migrations
            $cheminMigrations = $cheminModule . '/Migrations';
            if (is_dir($cheminMigrations)) {
                $this->loadMigrationsFrom($cheminMigrations);
            }
        }
    }
}
