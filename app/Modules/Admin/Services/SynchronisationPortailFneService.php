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
    /** Le seuil d'alerte des stickers quand le portail dit 0. */
    public const SEUIL_STICKERS_PAR_DEFAUT = 5;

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

        // Tout ce que le portail déclare se reprend, pour toute entreprise,
        // options fiscales comprises (timbre de quittance, BAPA, seuil des
        // stickers) : demande explicite du propriétaire le 24/11/2026
        // (exception n° 3 de la règle FNE de CLAUDE.md). L'espace FNE fait foi.
        $champs = array_merge(Entreprise::CHAMPS_REPRIS_DU_PORTAIL_FNE, Entreprise::OPTIONS_REPRISES_DU_PORTAIL_FNE);

        foreach ($champs as $champ) {
            $portail = $fiche->{$champ};

            // Un seuil d'alerte des stickers à 0 au portail n'alerterait jamais :
            // Selflow écrit 5, sa valeur par défaut (24/11/2026, propriétaire).
            if ($champ === 'sticker_solde_alerte' && $portail !== null && trim((string) $portail) !== '' && (int) $portail <= 0) {
                $portail = self::SEUIL_STICKERS_PAR_DEFAUT;
            }

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
