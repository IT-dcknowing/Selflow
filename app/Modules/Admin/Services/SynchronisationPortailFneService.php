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
        // Le portail fait foi pour toute entreprise dont il a été relevé
        // (24/11/2026, propriétaire : « le système doit mettre à jour ») : la
        // fiche existe, donc l'espace FNE existe.

        $fiche = PortailFneFiche::where('entreprise_id', $entreprise->id)
            ->orderByDesc('date_scraping')->orderByDesc('id')
            ->first();

        if (!$fiche) {
            return [];
        }

        $changements = [];

        // Les coordonnées et le paramétrage se reprennent pour toute
        // entreprise. Les trois options fiscales (timbre de quittance,
        // bordereau d'achat, seuil des stickers) changent ce que la facture
        // contient : elles ne suivent le portail que pour une entreprise qui a
        // déclaré son compte FNE (règle de conformité, CycleFneTroisCasTest).
        $champs = $entreprise->possede_compte_fne === true
            ? array_merge(Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE, Entreprise::OPTIONS_REPRISES_DU_PORTAIL_FNE)
            : array_values(array_diff(Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE, ['sticker_solde_alerte']));

        foreach ($champs as $champ) {
            $portail = $fiche->{$champ};

            // Une valeur que le portail n'a pas rendue n'efface rien. Une case
            // DÉCOCHÉE, elle, est une valeur : `false` doit décocher aussi
            // (propriétaire, 08/10/2026 — « si c'est décoché dans la FNE, cela
            // doit être décoché »). `(string) false` vaut '' : sans ce test,
            // un décochage n'aurait jamais été repris.
            if ($portail === null || (!is_bool($portail) && trim((string) $portail) === '')) {
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
