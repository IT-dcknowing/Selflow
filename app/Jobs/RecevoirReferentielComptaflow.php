<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\RecevoirReferentielComptaflowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recevoir le référentiel Comptaflow après une liaison ouverte depuis ce côté.
 *
 * Le job attend que l'appel entrant de Comptaflow soit terminé avant de le
 * rappeler : sur un serveur de développement à un seul processus, un rappel
 * synchrone pendant la requête entrante pourrait bloquer jusqu'au timeout.
 */
class RecevoirReferentielComptaflow implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(public readonly int $entrepriseId)
    {
    }

    public function handle(): void
    {
        $entreprise = Entreprise::find($this->entrepriseId);

        if (!$entreprise) {
            return;
        }

        $resultat = RecevoirReferentielComptaflowService::synchroniser($entreprise);

        if (!$resultat['success']) {
            Log::warning('Référentiel Comptaflow non reçu après ouverture de liaison', [
                'entreprise_id' => $entreprise->id,
                'motif' => $resultat['message'],
            ]);
        }
    }
}
