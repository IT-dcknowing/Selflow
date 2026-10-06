<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Produit;

/**
 * Sur quel compte s'impute un article.
 *
 * La question se posait à cinq endroits de `ComptabiliteService`, résolue
 * chaque fois par la même paire :
 *
 *     $detail->produit?->compte_vente ?? config('…vente_defaut')
 *
 * Deux niveaux là où le référentiel en prévoit trois, et le plus utile —
 * **le rayon** — sautait. Un article créé à la main après la souscription
 * n'héritait donc de rien et tombait sur le compte générique `701000` : la
 * balance d'un magasin qui a soigneusement réparti ses rayons se retrouvait
 * avec une seule ligne de ventes.
 *
 * La chaîne complète, du plus précis au plus général :
 *
 * | Rang | Source | Ce que cela veut dire |
 * |---|---|---|
 * | 1 | `produits.compte_*` | L'exception que l'utilisateur assume, article par article |
 * | 2 | `categories.compte_*` | Le rayon — la règle métier, celle du référentiel |
 * | 3 | `config('selflow.plan_comptable_defaut')` | Le filet, quand rien n'est renseigné |
 *
 * Le rang 1 l'emporte parce qu'il est explicite : un utilisateur qui a saisi un
 * compte sur une fiche l'a fait exprès. Le rang 3 n'est pas une imputation,
 * c'est un aveu d'ignorance — il vaut mieux qu'une écriture perdue, mais il se
 * signale : `manqueUnCompte()` permet aux écrans de le dire.
 */
class ImputationService
{
    /**
     * Le compte collectif d'un tiers — 411000 pour un client, 401000 pour un
     * fournisseur (chantier 5.2, précisé par le propriétaire le 05/10/2026).
     *
     * Comptabilité éteinte, l'écran ne propose plus de compte : le serveur
     * pose celui de la nature du tiers, et **ignore** ce qu'une requête
     * forgée enverrait — ce qui n'est plus demandé ne doit plus être accepté
     * (chantier 3.1). Comptabilité ouverte, le compte choisi l'emporte.
     *
     * @param  'client'|'fournisseur'  $nature
     */
    public static function compteDeTiers(?\App\Modules\Admin\Modeles\Entreprise $entreprise, string $nature, ?string $saisi = null): string
    {
        $defaut = config('selflow.plan_comptable_defaut.' . ($nature === 'client' ? 'client_collectif' : 'fournisseur_collectif'));
        $saisi = trim((string) $saisi);

        if ($saisi !== '' && $entreprise?->comptabiliteOuverte()) {
            return $saisi;
        }

        return $defaut;
    }

    /** Compte de produit — classe 7. */
    public static function compteVente(?Produit $produit, ?int $entrepriseId = null): string
    {
        return self::resoudreAvecConfiguration($produit, 'compte_vente', 'vente_defaut', $entrepriseId);
    }

    /** Compte de charge — classe 6. */
    public static function compteAchat(?Produit $produit, ?int $entrepriseId = null): string
    {
        return self::resoudreAvecConfiguration($produit, 'compte_achat', 'achat_defaut', $entrepriseId);
    }

    /**
     * L'ordre de priorité des comptes de vente et d'achat (chantier 5.2),
     * écrit une fois :
     *
     * | Rang | Source |
     * |---|---|
     * | 1 | **l'exception de l'article** — un compte propre qui diffère du défaut et de sa famille |
     * | 2 | **la configuration globale** — par type, puis par catégorie, puis générale (section 6) |
     * | 3 | le compte de l'article, sinon celui de sa famille |
     * | 4 | le défaut 701000 / 601000 |
     *
     * Le rang 1 se reconnaît à la valeur, et non à une case : un compte qui
     * vaut le défaut ou celui de la famille est un héritage, quelle que soit
     * la façon dont il est arrivé sur la fiche. C'est la règle de reprise de
     * l'existant (chantier 6.5) — ce qui diffère est réputé voulu — appliquée
     * à chaque lecture plutôt qu'une fois par une migration.
     *
     * Sans aucune configuration globale, le résultat est exactement celui
     * d'avant : les rangs 1, 3 et 4 sont l'ancienne chaîne.
     */
    private static function resoudreAvecConfiguration(?Produit $produit, string $champ, string $cleDefaut, ?int $entrepriseId): string
    {
        $defaut  = (string) config("selflow.plan_comptable_defaut.{$cleDefaut}");
        $propre  = trim((string) ($produit?->$champ ?? ''));
        $famille = trim((string) ($produit?->categorieRelation?->$champ ?? ''));

        if ($propre !== '' && $propre !== $defaut && $propre !== $famille) {
            return $propre;
        }

        $global = self::configurationGlobale($produit, $champ, $entrepriseId ?? $produit?->entreprise_id);

        if ($global !== null) {
            return $global;
        }

        return self::resoudre($produit, $champ, $cleDefaut);
    }

    /**
     * Le compte que la configuration globale donne à cet article — le plus
     * précis l'emporte : type, puis catégorie, puis général (chantier 6.2).
     */
    public static function configurationGlobale(?Produit $produit, string $champ, ?int $entrepriseId): ?string
    {
        if (!$entrepriseId) {
            return null;
        }

        $config = \App\Modules\Admin\Modeles\ConfigurationCompte::pour($entrepriseId);

        if ($config === []) {
            return null;
        }

        $cles = [];
        if ($produit?->type) {
            $cles[] = \App\Modules\Admin\Modeles\ConfigurationCompte::TYPE . ':' . $produit->type;
        }
        if ($produit?->categorie_id) {
            $cles[] = \App\Modules\Admin\Modeles\ConfigurationCompte::CATEGORIE . ':' . $produit->categorie_id;
        }
        $cles[] = \App\Modules\Admin\Modeles\ConfigurationCompte::GENERAL . ':';

        foreach ($cles as $cle) {
            $compte = trim((string) ($config[$cle][$champ] ?? ''));

            if ($compte !== '') {
                return $compte;
            }
        }

        return null;
    }

    /**
     * Ce que l'article hérite, hors exception — affiché sur sa fiche (6.4) et
     * reposé sur la fiche quand on décoche l'exception : décochée, elle ne
     * garde pas en silence l'ancienne valeur.
     */
    public static function compteHerite(Produit $produit, string $champ): string
    {
        $cleDefaut = $champ === 'compte_vente' ? 'vente_defaut' : 'achat_defaut';

        return self::configurationGlobale($produit, $champ, $produit->entreprise_id)
            ?? (trim((string) ($produit->categorieRelation?->$champ ?? '')) ?: (string) config("selflow.plan_comptable_defaut.{$cleDefaut}"));
    }

    /** L'article porte-t-il une exception à ce qui s'hérite ? */
    public static function estUneException(Produit $produit, string $champ): bool
    {
        $cleDefaut = $champ === 'compte_vente' ? 'vente_defaut' : 'achat_defaut';
        $propre  = trim((string) ($produit->$champ ?? ''));

        return $propre !== ''
            && $propre !== (string) config("selflow.plan_comptable_defaut.{$cleDefaut}")
            && $propre !== trim((string) ($produit->categorieRelation?->$champ ?? ''));
    }

    /**
     * Compte de stock — classe 3.
     *
     * Sans repli de configuration : il n'existe pas de « compte de stock
     * générique » qui voudrait dire quelque chose. Les marchandises vont en 31,
     * les matières en 32, les produits finis en 36 ; les confondre rendrait le
     * bilan faux plutôt qu'imprécis. Un article sans compte de stock ne produit
     * donc pas d'écriture d'inventaire permanent, et l'écran le signale.
     */
    public static function compteStock(?Produit $produit): ?string
    {
        return self::chercher($produit, 'compte_stock');
    }

    /**
     * Compte de variation de stock — 603 pour les achats, 736 pour la
     * production. Même raisonnement que `compteStock()` : pas de repli.
     */
    public static function compteVariation(?Produit $produit): ?string
    {
        return self::chercher($produit, 'compte_variation');
    }

    /**
     * L'article peut-il produire une écriture d'inventaire permanent ?
     *
     * Il lui faut les deux comptes : le stock sans la variation écrirait une
     * entrée de bilan sans contrepartie de gestion, et le déséquilibre
     * n'apparaîtrait qu'à la balance, des semaines plus tard.
     */
    public static function peutTenirLInventairePermanent(?Produit $produit): bool
    {
        return $produit
            && $produit->estStockable()
            && self::compteStock($produit)
            && self::compteVariation($produit);
    }

    /**
     * Les comptes qu'un article devrait porter et ne trouve nulle part.
     *
     * Destiné aux écrans : un article mal imputé ne se voit pas avant la
     * balance, et à ce moment-là le mois est passé.
     *
     * @return array<int, string>
     */
    public static function manqueUnCompte(?Produit $produit): array
    {
        if (!$produit) {
            return [];
        }

        $manquants = [];

        foreach (['compte_vente' => 'de vente', 'compte_achat' => 'd\'achat'] as $champ => $libelle) {
            if (!self::chercher($produit, $champ)) {
                $manquants[] = "compte {$libelle}";
            }
        }

        if ($produit->estStockable()) {
            foreach (['compte_stock' => 'de stock', 'compte_variation' => 'de variation de stock'] as $champ => $libelle) {
                if (!self::chercher($produit, $champ)) {
                    $manquants[] = "compte {$libelle}";
                }
            }
        }

        return $manquants;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Le compte, ou le repli de configuration si la chaîne ne donne rien.
     */
    private static function resoudre(?Produit $produit, string $champ, string $cleDefaut): string
    {
        return self::chercher($produit, $champ)
            ?? config("selflow.plan_comptable_defaut.{$cleDefaut}");
    }

    /**
     * Le compte porté par l'article, sinon par son rayon, sinon rien.
     *
     * Une chaîne vide vaut absence : une colonne remplie d'espaces par un
     * import maladroit ne doit pas passer pour une imputation.
     */
    private static function chercher(?Produit $produit, string $champ): ?string
    {
        if (!$produit) {
            return null;
        }

        $surLArticle = trim((string) ($produit->$champ ?? ''));

        if ($surLArticle !== '') {
            return $surLArticle;
        }

        $surLeRayon = trim((string) ($produit->categorieRelation?->$champ ?? ''));

        return $surLeRayon !== '' ? $surLeRayon : null;
    }
}
