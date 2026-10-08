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
 * Ne sont repris que les champs de `Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE`.
 * Le timbre, le BAPA et le seuil des stickers restent au superadministrateur :
 * ils changent le calcul d'une facture, et un fichier déposé ne décide pas de
 * cela à sa place.
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

        foreach (Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE as $champ) {
            $portail = $fiche->{$champ};

            if ($portail === null || trim((string) $portail) === '') {
                continue;
            }

            if ((string) $portail !== (string) $entreprise->{$champ}) {
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
