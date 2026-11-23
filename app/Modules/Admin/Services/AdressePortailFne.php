<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\FneCredential;

/**
 * L'adresse du portail FNE d'une entreprise : test ou production.
 *
 * Le 23/11/2026, le scraper et les liens « Consulter FNE » visaient tous
 * l'environnement de test (http://54.247.95.108) — une seule adresse,
 * `FNE_URL`, pour toutes les entreprises —, alors que DC-KNOWING CGA est
 * validée en production chez le superadministrateur.
 *
 * La règle est celle que `FneService` applique déjà à la certification, et
 * qu'on ne fait que relire ici sans y toucher : la production quand l'accès
 * est « validee » et que `FNE_API_URL_PRODUCTION` est renseignée, le test
 * sinon. Le portail est servi à la racine de l'hôte de l'API (`…/ws`).
 * `FNE_PORTAIL_URL_PRODUCTION` permet de désigner un hôte de portail
 * différent si la DGI en donne un.
 */
class AdressePortailFne
{
    public static function enProduction(?FneCredential $acces): bool
    {
        return $acces !== null
            && $acces->statut === 'validee'
            && trim((string) (config('selflow.portail_fne.url_production') ?: config('selflow.fne_api_url_production'))) !== '';
    }

    /** L'hôte du portail, sans barre finale : `http://54.247.95.108`. */
    public static function hote(?FneCredential $acces): string
    {
        if (self::enProduction($acces)) {
            $portail = trim((string) config('selflow.portail_fne.url_production'));

            return $portail !== ''
                ? self::racine($portail)
                : self::racine((string) config('selflow.fne_api_url_production'));
        }

        return self::racine((string) config('selflow.fne_api_url_sandbox', 'http://54.247.95.108/ws'));
    }

    /** La page de connexion que le scraper ouvre (la forme de `FNE_URL`). */
    public static function connexion(?FneCredential $acces): string
    {
        $hote = self::hote($acces);

        return $hote === '' ? '' : $hote . '/fr/login';
    }

    public static function pourEntreprise(?int $entrepriseId): ?FneCredential
    {
        return $entrepriseId ? FneCredential::where('entreprise_id', $entrepriseId)->first() : null;
    }

    public static function pourLogin(string $login): ?FneCredential
    {
        $recherche = EntreprisePortailFne::comparable($login);

        return FneCredential::query()
            ->where('telechargement_actif', true)
            ->whereNotNull('ncc_associe')
            ->orderByDesc('updated_at')
            ->get()
            ->first(fn (FneCredential $c) => EntreprisePortailFne::comparable($c->ncc_associe) === $recherche);
    }

    private static function racine(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#/ws/?$#', '', $url);
        $url = (string) preg_replace('#/fr/login/?$#', '', $url);

        return rtrim($url, '/');
    }
}
