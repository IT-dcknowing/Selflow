<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Referentiel\Article;
use App\Modules\Admin\Modeles\Referentiel\Famille;
use Illuminate\Support\Str;

/**
 * Le domaine et le secteur déduits des articles (25/11/2026, propriétaire :
 * « en créant les produits, on doit aussi créer le domaine à travers le
 * produit, en ajoutant le secteur »).
 *
 * Un article créé depuis le portail FNE n'a ni catégorie ni secteur. Son nom
 * est comparé aux articles types et aux familles du référentiel : les mots
 * en commun désignent la famille, donc son secteur (profil) et son domaine.
 * L'article prend la catégorie de cette famille chez l'entreprise (créée au
 * besoin, rattachée à son domaine et à son secteur), et l'entreprise reçoit
 * le secteur et le domaine, sans recopier tout le catalogue du secteur.
 *
 * Rien n'est deviné en dessous d'un seuil : un article non reconnu reste sans
 * catégorie, plutôt que rangé au mauvais rayon.
 */
class ClassementParReferentielService
{
    /** Mots qui ne disent rien du métier. */
    private const MOTS_VIDES = ['les', 'des', 'une', 'pour', 'avec', 'sans', 'sur', 'par', 'aux', 'du', 'de', 'la', 'le',
        'et', 'en', 'au', 'kg', 'g', 'l', 'ml', 'cl', 'pcs', 'piece', 'pieces', 'unite', 'lot', 'pack', 'carton', 'sac', 'boite'];

    /** Part minimale des mots du produit retrouvés dans l'entrée du référentiel. */
    private const SEUIL = 0.5;

    /** @var array<int, array{famille_id: int, mots: array<int, string>}>|null */
    private static ?array $index = null;

    /** La famille du référentiel qui correspond le mieux à ce nom, ou null. */
    public static function familleDe(string $nom): ?Famille
    {
        $mots = self::mots($nom);
        if ($mots === []) {
            return null;
        }

        $meilleure = null;
        $score = 0.0;
        foreach (self::index() as $entree) {
            $communs = count(array_intersect($mots, $entree['mots']));
            if ($communs === 0) {
                continue;
            }
            // Part des mots du produit retrouvés, départagée par la précision
            // de l'entrée (une entrée courte et entièrement retrouvée l'emporte).
            $s = $communs / count($mots) + 0.01 * ($communs / max(1, count($entree['mots'])));
            if ($s > $score) {
                $score = $s;
                $meilleure = $entree['famille_id'];
            }
        }

        return $meilleure !== null && $score >= self::SEUIL ? Famille::with('profil.categorie')->find($meilleure) : null;
    }

    /**
     * Range l'article dans la catégorie de sa famille et inscrit le secteur
     * et le domaine à l'entreprise. Rend la famille retenue, ou null.
     */
    public static function rattacher(Entreprise $entreprise, Produit $produit): ?Famille
    {
        if ($produit->categorie_id || !\App\Support\Structure::aTable('referentiel_familles')) {
            return null;
        }

        $famille = self::familleDe((string) $produit->nom);
        if (!$famille || !$famille->profil) {
            return null;
        }

        [$categorie] = SouscriptionProfilService::categoriePour($entreprise, $famille);
        $produit->forceFill(['categorie_id' => $categorie->id])->saveQuietly();

        $profil = $famille->profil;
        if (!$entreprise->profils()->where('profil_id', $profil->id)->exists()) {
            $entreprise->profils()->attach($profil->id, ['familles_creees' => 0, 'articles_crees' => 0, 'souscrit_le' => now()]);
        }

        $domaine = $profil->categorie?->nom;
        if ($domaine) {
            $entreprise->forceFill([
                'domaines_souscrits' => array_values(array_unique(array_merge((array) $entreprise->domaines_souscrits, [$domaine]))),
                'secteur_activite'   => array_values(array_unique(array_merge((array) $entreprise->secteur_activite, [$domaine]))),
            ])->save();
        }

        return $famille;
    }

    /** @return array<int, string> */
    private static function mots(string $texte): array
    {
        $texte = Str::lower(Str::ascii($texte));
        $mots = preg_split('/[^a-z0-9]+/', $texte) ?: [];
        $mots = array_filter($mots, fn ($m) => strlen($m) >= 3 && !ctype_digit($m) && !in_array($m, self::MOTS_VIDES, true));

        // « tomates » et « tomate » se valent.
        return array_values(array_unique(array_map(fn ($m) => strlen($m) > 4 ? rtrim($m, 's') : $m, $mots)));
    }

    /** @return array<int, array{famille_id: int, mots: array<int, string>}> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (Article::query()->whereNotNull('famille_id')->get(['famille_id', 'designation']) as $a) {
            $mots = self::mots((string) $a->designation);
            if ($mots !== []) {
                $index[] = ['famille_id' => (int) $a->famille_id, 'mots' => $mots];
            }
        }
        foreach (Famille::query()->get(['id', 'nom']) as $f) {
            $mots = self::mots((string) $f->nom);
            if ($mots !== []) {
                $index[] = ['famille_id' => (int) $f->id, 'mots' => $mots];
            }
        }

        return self::$index = $index;
    }

    /** Pour les tests : le référentiel a changé. */
    public static function oublier(): void
    {
        self::$index = null;
    }
}
