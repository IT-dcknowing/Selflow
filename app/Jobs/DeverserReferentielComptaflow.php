<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\DeversementReferentielService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envoyer le référentiel d'une entreprise à Comptaflow, hors de la requête.
 *
 * Sert à la liaison lancée **depuis Comptaflow** : Comptaflow appelle
 * `link-company` et attend la réponse. Déverser le référentiel dans cette même
 * requête ferait rappeler Comptaflow pendant qu'il attend encore — un serveur
 * à un seul processus, comme celui de développement, s'y bloque jusqu'à
 * expiration, et la liaison échoue alors qu'elle avait réussi.
 */
class DeverserReferentielComptaflow implements ShouldQueue
{
    use Queueable;

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

        $resultat = DeversementReferentielService::deverser($entreprise);

        if (!$resultat['success']) {
            // Sans journal, un refus ici se taisait comme celui des écritures
            // l'avait fait : la liaison s'affichait active et rien n'arrivait.
            Log::warning('Référentiel non déversé après une liaison ouverte par Comptaflow', [
                'entreprise_id' => $entreprise->id,
                'motif'         => $resultat['message'],
            ]);
        }
    }
}
