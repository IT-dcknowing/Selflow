<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\Storage;

/**
 * L'adresse d'un fichier déposé, que le lien de stockage soit posé ou non.
 *
 * ## Le défaut, et pourquoi il ne se voyait qu'en ligne
 *
 * Tout ce qui est déposé — logos, photos d'articles, avatars, images de la
 * vitrine — vit dans `storage/app/public`, et s'affiche par `public/storage`,
 * un lien symbolique que pose `php artisan storage:link`.
 *
 * Sans ce lien, l'adresse `/storage/…` répond 404 (Not Found — introuvable) et
 * **rien ne le dit** : l'image manque, la page se charge normalement. En local
 * le lien est posé depuis longtemps ; sur un hébergement mutualisé il ne l'est
 * pas toujours, et il ne survit pas à tous les déploiements.
 *
 * `Produit::photoReelle()` savait déjà se replier sur une route. Les logos, les
 * avatars et la vitrine, non : c'est le défaut constaté le 25/09/2026, où les
 * deux logos de l'entreprise s'affichaient cassés dans les paramètres.
 *
 * ## Ce que fait cette classe
 *
 * Elle rend l'adresse directe quand le lien est là — servie par le serveur web,
 * sans passer par PHP — et une adresse d'application quand il manque. La règle
 * vit ici, une fois, au lieu d'être répétée à chaque écran.
 *
 * ## Ce qui la rend sûre
 *
 * La route de repli ne prend **pas un chemin** mais un dossier et un nom de
 * fichier, tous deux bornés par l'expression régulière de la route. Un chemin
 * libre venu d'une colonne — écrite par un formulaire — laisserait remonter
 * l'arborescence.
 */
class FichierPublic
{
    /** Les dossiers que l'application accepte de servir. */
    public const DOSSIERS = ['logos', 'produits', 'avatars', 'vitrine'];

    /**
     * L'adresse d'affichage d'un fichier du disque public.
     *
     * Rend `null` quand il n'y a rien à montrer — l'appelant décide alors s'il
     * affiche un cadre vide ou rien du tout.
     *
     * `$repli` laisse l'appelant imposer sa propre porte quand le lien manque :
     * la photo d'un article passe par une route qui vérifie à quelle entreprise
     * il appartient, et cette garde-là vaut mieux que la porte générale.
     */
    public static function url(?string $chemin, ?string $repli = null): ?string
    {
        $chemin = trim((string) $chemin);

        if ($chemin === '') {
            return null;
        }

        // Une adresse déjà complète se rend telle quelle : certains logos ont
        // été saisis comme des liens.
        if (str_starts_with($chemin, 'http://') || str_starts_with($chemin, 'https://')) {
            return $chemin;
        }

        // Le lien posé : le serveur web sert le fichier, PHP n'est pas appelé.
        if (self::lienPose()) {
            return '/storage/' . ltrim($chemin, '/');
        }

        // L'appelant peut avoir sa propre porte, mieux gardee : la photo d'un
        // article passe par une route qui verifie a quelle entreprise il
        // appartient. On la prefere a la porte generale.
        if ($repli !== null) {
            return $repli;
        }

        [$dossier, $fichier] = self::decouper($chemin);

        // Hors des dossiers connus, ou avec un nom qui ne ressemble pas à un
        // nom de fichier, on préfère ne rien montrer à ouvrir une porte.
        if ($dossier === null) {
            return null;
        }

        return route('admin.media', ['dossier' => $dossier, 'fichier' => $fichier]);
    }

    /**
     * Le dossier et le nom, ou `[null, null]` si le chemin n'est pas servable.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function decouper(string $chemin): array
    {
        $chemin = ltrim($chemin, '/');

        if (str_contains($chemin, '..')) {
            return [null, null];
        }

        $morceaux = explode('/', $chemin, 2);

        if (count($morceaux) !== 2) {
            return [null, null];
        }

        [$dossier, $fichier] = $morceaux;

        if (!in_array($dossier, self::DOSSIERS, true)) {
            return [null, null];
        }

        // Ni `..`, ni caractères dangereux dans le nom de fichier ou sous-dossier.
        if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $fichier)) {
            return [null, null];
        }

        return [$dossier, $fichier];
    }

    /**
     * Le lien `public/storage` est-il en place et servable par le serveur web ?
     *
     * ## Le défaut corrigé (25/09/2026)
     *
     * `file_exists()` retourne `true` même pour un dossier physique ou une
     * Junction Windows — or sur certains hébergements mutualisés, Apache refuse
     * l'accès à ce dossier avec 403 (Forbidden). PHP pensait que le lien était
     * posé, construisait `/storage/produits/…`, et le serveur web renvoyait 403.
     *
     * La correction :
     * 1. `STORAGE_LINK_FORCED=false` dans le `.env` force la route PHP même si
     *    le dossier existe — utile sur les hébergements mutualisés.
     * 2. On distingue un vrai lien d'un dossier physique, ce qui évite de
     *    construire une adresse que le serveur web va rejeter.
     *
     * Retenu pour la durée de la requête : un écran pose la question une fois
     * par image, et un accès disque par vignette n'apprendrait rien de neuf.
     */
    private static ?bool $lien = null;

    public static function lienPose(): bool
    {
        if (self::$lien !== null) {
            return self::$lien;
        }

        // La variable d'environnement permet de forcer la route PHP sur les
        // hébergements où le serveur web refuse de servir `public/storage`
        // (par exemple : 403 sur un mutualisé OVH, Infomaniak…).
        $force = env('STORAGE_LINK_FORCED');
        if ($force !== null && $force !== '') {
            return self::$lien = filter_var($force, FILTER_VALIDATE_BOOLEAN);
        }

        $chemin = public_path('storage');

        if (!file_exists($chemin)) {
            return self::$lien = false;
        }

        // Ce qu'il faut distinguer : un **lien**, que le serveur web suit,
        // d'un **dossier physique**, qui peut exister sans que le serveur
        // accepte d'en servir le contenu — le cas du mutualisé qui répond
        // 403 (Forbidden — accès interdit).
        //
        // `is_link()` seul ne suffit pas, et le commentaire d'origine le
        // croyait : **il répond `false` pour une jonction Windows**, celle
        // que `php artisan storage:link` pose sur un poste Windows. Vérifié
        // le 02/10/2026 sur ce dépôt — `public/storage` y est une jonction,
        // `is_link()` rendait `false`, et **toutes les images de toutes les
        // pages passaient par PHP** au lieu d'être servies en fichier.
        // Trente cartes de caisse faisaient trente démarrages de Laravel.
        //
        // `readlink()`, lui, résout une jonction, et rend `false` sur un
        // dossier ordinaire : c'est exactement la distinction cherchée.
        if (is_link($chemin)) {
            return self::$lien = true;
        }

        return self::$lien = @readlink($chemin) !== false;
    }

    /** Oublier ce qu'on croit savoir — les épreuves posent et retirent le lien. */
    public static function oublierLeLien(): void
    {
        self::$lien = null;
    }

    /** Le fichier existe-t-il réellement sur le disque ? */
    public static function existe(?string $chemin): bool
    {
        $chemin = trim((string) $chemin);

        if ($chemin === '' || str_contains($chemin, '..')) {
            return false;
        }

        try {
            return Storage::disk('public')->exists($chemin);
        } catch (\Throwable) {
            return false;
        }
    }
}
