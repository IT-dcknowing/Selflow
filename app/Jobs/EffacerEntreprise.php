<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\SuppressionEntrepriseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * L'effacement réel d'une entreprise, en arrière-plan (retour du 16/10/2026).
 *
 * La mise en corbeille est instantanée ; l'effacement, lui, suit toutes les
 * dépendances du schéma et peut durer. Le confier à la file retire ce temps
 * de la requête — « Vider la corbeille » rend la main aussitôt — et un échec
 * se rejoue au lieu de laisser une entreprise à moitié effacée.
 */
class EffacerEntreprise implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 3;

    public function __construct(public int $entrepriseId)
    {
    }

    public function handle(): void
    {
        // On relit sans filtre : l'entreprise est en corbeille, et un éventuel
        // scope global la cacherait au job qui doit précisément l'effacer.
        $entreprise = Entreprise::withoutGlobalScopes()->find($this->entrepriseId);

        if (!$entreprise) {
            return;
        }

        if (!$entreprise->estEnCorbeille()) {
            Log::warning('EffacerEntreprise : entreprise hors corbeille, effacement abandonné', [
                'entreprise_id' => $this->entrepriseId,
            ]);

            return;
        }

        SuppressionEntrepriseService::supprimer($entreprise);
    }
}
