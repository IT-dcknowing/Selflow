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
    public static function compteVente(?Produit $produit): string
    {
        return self::resoudre($produit, 'compte_vente', 'vente_defaut');
    }

    /** Compte de charge — classe 6. */
    public static function compteAchat(?Produit $produit): string
    {
        return self::resoudre($produit, 'compte_achat', 'achat_defaut');
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
