<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use Illuminate\Support\Facades\Log;

/**
 * L'entreprise à qui revient un relevé du portail FNE.
 *
 * Le NCC n'est pas unique : le 23/11/2026, deux entreprises « DC-KNOWING
 * CGA » (n° 10 et n° 29) portaient le NCC 1864699A. Les imports prenaient la
 * première venue, et les 29 factures relevées partaient chez l'autre — celle
 * où l'on travaillait ne voyait rien.
 *
 * Le relevé se fait avec les accès FNE qu'une entreprise a confiés et que le
 * superadministrateur a autorisés au téléchargement : c'est à elle qu'il
 * revient. Le NCC seul ne sert plus que de repli, quand aucun accès autorisé
 * ne désigne d'entreprise.
 */
class EntreprisePortailFne
{
    public static function comparable(?string $ncc): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $ncc)) ?? '';
    }

    /** L'entreprise dont les accès autorisés ont servi au relevé, s'il y en a une. */
    public static function parAccesFne(string $login): ?Entreprise
    {
        $recherche = self::comparable($login);

        if ($recherche === '') {
            return null;
        }

        $acces = FneCredential::query()
            ->with('entreprise')
            ->where('telechargement_actif', true)
            ->whereNotNull('ncc_associe')
            ->whereNotNull('acces_mot_de_passe')
            ->orderByDesc('updated_at')
            ->get()
            ->filter(fn (FneCredential $c) => $c->entreprise && self::comparable($c->ncc_associe) === $recherche)
            ->values();

        if ($acces->count() > 1) {
            self::tracer("plusieurs entreprises ont des accès FNE autorisés pour le NCC {$login} : "
                . $acces->map(fn ($c) => "n° {$c->entreprise_id}")->implode(', ')
                . ". Le relevé va à la plus récemment mise à jour (n° {$acces->first()->entreprise_id}).");
        }

        return $acces->first()?->entreprise;
    }

    /**
     * Le repli par NCC : la première entreprise qui le porte, comme avant,
     * mais l'ambiguïté est écrite au journal au lieu d'être tranchée en silence.
     */
    public static function parNcc(string $login): ?Entreprise
    {
        $recherche = self::comparable($login);

        if ($recherche === '') {
            return null;
        }

        $candidates = Entreprise::query()
            ->whereNotNull('ncc')
            ->orderBy('id')
            ->get()
            ->filter(fn (Entreprise $e) => self::comparable($e->ncc) === $recherche)
            ->values();

        if ($candidates->count() > 1) {
            self::tracer("le NCC {$login} est porté par plusieurs entreprises ("
                . $candidates->map(fn ($e) => "n° {$e->id} {$e->nom}")->implode(', ')
                . ") et aucune n'a d'accès FNE autorisé : le relevé va à la n° {$candidates->first()->id}.");
        }

        return $candidates->first();
    }

    public static function pour(string $login): ?Entreprise
    {
        return self::parAccesFne($login) ?? self::parNcc($login);
    }

    private static function tracer(string $message): void
    {
        try {
            Log::warning('[portail FNE] ' . $message);
        } catch (\Throwable) {
            // Une panne de journal ne doit pas arrêter l'import.
        }
    }
}
