<?php

use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\PortailFneAchatsApiController;
use Illuminate\Support\Facades\Route;

// On protège ces routes avec le token du Hub
Route::middleware(['hub.token'])->group(function () {
    Route::get('/companies', [DashboardApiController::class, 'getCompanies']);
    Route::get('/entreprises', [DashboardApiController::class, 'getCompanies']);
    Route::get('/dashboard/kpis', [DashboardApiController::class, 'getKpis']);
    Route::get('/dashboard/exercices', [DashboardApiController::class, 'getExercices']);

    /*
     * Le relevé des factures reçues du portail FNE.
     *
     * Le planificateur le lance déjà tout seul, toutes les cinq minutes. Ces
     * deux routes servent à ce qu'il ne couvre pas : déclencher un relevé sans
     * attendre le passage suivant, et savoir de l'extérieur si la chaîne tourne
     * encore — c'est `dernier_releve_le` qui le dit.
     *
     * `POST` pour le lancement, et ce n'est pas une convention gratuite : il
     * ouvre un navigateur sur le portail de la DGI avec le mot de passe d'un
     * client. Un `GET` serait rejoué par un cache, un préchargement de
     * navigateur ou un lien visité deux fois.
     */
    Route::post('/portail-fne/achats/relever', [PortailFneAchatsApiController::class, 'relever']);
    Route::get('/portail-fne/achats', [PortailFneAchatsApiController::class, 'statut']);
});
