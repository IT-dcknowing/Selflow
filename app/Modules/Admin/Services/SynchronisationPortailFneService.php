<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFiche;
use Illuminate\Support\Facades\Log;

/**
 * Reprendre dans la fiche de l'entreprise ce que son espace FNE déclare.
 *
 * Demandé par le propriétaire le 08/10/2026 : une entreprise qui a déjà un
 * compte FNE ne ressaisit pas ce que le portail détient — ces champs se
 * grisent à l'écran et se remplissent depuis le dernier relevé. « Tout doit
 * être synchronisé. »
 *
 * Sont repris les champs de `Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE` et les
 * options de `Entreprise::OPTIONS_REPRISES_DU_PORTAIL_FNE` (timbre de
 * quittance, BAPA) : propriétaire, 08/10/2026, c'est l'espace FNE qui fait
 * foi. Chaque changement est journalisé.
 *
 * Une valeur que le portail n'a pas rendue n'efface rien.
 */
class SynchronisationPortailFneService
{
    /**
     * @return array<string, array{avant: mixed, apres: mixed}> ce qui a changé
     */
    public static function reprendre(Entreprise $entreprise): array
    {
        if ($entreprise->possede_compte_fne !== true) {
            return [];
        }

        $fiche = PortailFneFiche::where('entreprise_id', $entreprise->id)
            ->orderByDesc('date_scraping')->orderByDesc('id')
            ->first();

        if (!$fiche) {
            return [];
        }

        $changements = [];

        foreach (array_merge(Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE, Entreprise::OPTIONS_REPRISES_DU_PORTAIL_FNE) as $champ) {
            $portail = $fiche->{$champ};

            if ($portail === null || trim((string) $portail) === '') {
                continue;
            }

            $different = is_bool($portail)
                ? $portail !== (bool) $entreprise->{$champ}
                : (string) $portail !== (string) $entreprise->{$champ};

            if ($different) {
                $changements[$champ] = ['avant' => $entreprise->{$champ}, 'apres' => $portail];
                $entreprise->{$champ} = $portail;
            }
        }

        if ($changements !== []) {
            $entreprise->save();

            Log::info('[FNE] Fiche entreprise reprise du portail', [
                'entreprise_id' => $entreprise->id,
                'releve_du'     => $fiche->date_scraping?->toDateString(),
                'champs'        => array_keys($changements),
            ]);
        }

        return $changements;
    }
}
