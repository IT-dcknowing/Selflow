<?php

use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\PortailFneAchatsApiController;
use App\Http\Controllers\Api\PortailFneAvoirsApiController;
use Illuminate\Support\Facades\Route;

// On protège ces routes avec le token du Hub
Route::middleware(['hub.token'])->group(function () {
    Route::get('/companies', [DashboardApiController::class, 'getCompanies']);
    Route::get('/entreprises', [DashboardApiController::class, 'getCompanies']);
    Route::get('/dashboard/kpis', [DashboardApiController::class, 'getKpis']);
    Route::get('/dashboard/exercices', [DashboardApiController::class, 'getExercices']);

    /*
     * Le relevé des factures reçues du portail FNE.
     */
    Route::post('/portail-fne/achats/relever', [PortailFneAchatsApiController::class, 'relever']);
    Route::get('/portail-fne/achats', [PortailFneAchatsApiController::class, 'statut']);

    /*
     * Le relevé des factures d'avoir du portail FNE.
     */
    Route::post('/portail-fne/avoirs/relever', [PortailFneAvoirsApiController::class, 'relever']);
    Route::get('/portail-fne/avoirs', [PortailFneAvoirsApiController::class, 'statut']);
});
