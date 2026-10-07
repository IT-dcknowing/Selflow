<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\ImputationGlobale;
use App\Modules\Admin\Modeles\Produit;

/**
 * Sur quel compte s'impute un article — et un tiers.
 *
 * La question se posait à cinq endroits de `ComptabiliteService`, résolue
 * chaque fois par la même paire :
 *
 *     $detail->produit?->compte_vente ?? config('…vente_defaut')
 *
 * Elle se pose désormais ici, et seulement ici (chantier 5.2 du plan). Quand
 * l'utilisateur ne voit aucun champ de compte — comptabilité fermée, ou
 * configuration globale renseignée — c'est cette classe qui choisit.
 *
 * ## Vente et achat, du plus précis au plus général
 *
 * | Rang | Source | Ce que cela veut dire |
 * |---|---|---|
 * | 1 | `produits.compte_*`, **case cochée** | L'exception que l'utilisateur assume, article par article |
 * | 2 | `imputations_globales`, `type:<type>` | La configuration globale par type d'article |
 * | 3 | `categories.compte_*` | La configuration par catégorie — où le préparamétrage du métier pose les comptes de la famille |
 * | 4 | `imputations_globales`, `general` | La configuration générale : « tous les produits » |
 * | 5 | `config('selflow.plan_comptable_defaut')` | 701000 / 601000, le filet |
 *
 * Le rang 1 l'emporte parce qu'il est explicite. **La case compte, pas la
 * colonne** : une colonne remplie par un import ou par l'ancien formulaire —
 * qui l'exigeait — n'est pas un choix, et la lire comme tel figeait l'article
 * hors de toute configuration. Le rang 5 n'est pas une imputation, c'est un
 * aveu d'ignorance — il vaut mieux qu'une écriture perdue, mais il se
 * signale : `manqueUnCompte()` permet aux écrans de le dire.
 *
 * ## Les tiers
 *
 * Le client porte son compte collectif, sinon 411000 ; le fournisseur le
 * sien, sinon 401000. Les oublier laissait une écriture de vente ou d'achat
 * sans contrepartie de tiers — et c'est précisément ce compte que le numéro
 * de tiers subdivise chez Comptaflow.
 */
class ImputationService
{
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

    /** Compte collectif d'un client — 411000 s'il n'en porte pas. */
    public static function compteClient(?Client $client): string
    {
        $compte = trim((string) ($client?->compte_comptable ?? ''));

        return $compte !== '' ? $compte : (string) config('selflow.plan_comptable_defaut.client_collectif');
    }

    /** Compte collectif d'un fournisseur — 401000 s'il n'en porte pas. */
    public static function compteFournisseur(?Fournisseur $fournisseur): string
    {
        $compte = trim((string) ($fournisseur?->compte_comptable ?? ''));

        return $compte !== '' ? $compte : (string) config('selflow.plan_comptable_defaut.fournisseur_collectif');
    }

    /**
     * Ce dont l'article hérite s'il n'est pas une exception.
     *
     * Pour la fiche produit : « hérite de la configuration globale », avec
     * les comptes qui s'appliquent — case cochée ou non.
     *
     * @return array{compte_vente: string, compte_achat: string}
     */
    public static function heritage(Produit $produit): array
    {
        return [
            'compte_vente' => self::herite($produit, 'compte_vente') ?? (string) config('selflow.plan_comptable_defaut.vente_defaut'),
            'compte_achat' => self::herite($produit, 'compte_achat') ?? (string) config('selflow.plan_comptable_defaut.achat_defaut'),
        ];
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
     * Le compte de l'article selon la chaîne, sans le filet de configuration.
     *
     * Une chaîne vide vaut absence : une colonne remplie d'espaces par un
     * import maladroit ne doit pas passer pour une imputation.
     */
    private static function chercher(?Produit $produit, string $champ): ?string
    {
        if (!$produit) {
            return null;
        }

        $configurable = in_array($champ, ['compte_vente', 'compte_achat'], true);

        // Le stock et la variation ne passent pas par la configuration
        // globale : leur compte suit la nature de l'article (31, 32, 36…), et
        // un « compte de stock de tous les produits » rendrait le bilan faux.
        if (!$configurable || $produit->comptes_personnalises) {
            $surLArticle = self::valeur($produit->$champ ?? null);

            if ($surLArticle !== null) {
                return $surLArticle;
            }
        }

        if (!$configurable) {
            return self::valeur($produit->categorieRelation?->$champ ?? null);
        }

        return self::herite($produit, $champ);
    }

    /** Les rangs 2 à 4 de la chaîne. */
    private static function herite(Produit $produit, string $champ): ?string
    {
        $globale = $produit->entreprise_id ? ImputationGlobale::pour((int) $produit->entreprise_id) : [];

        return self::valeur($globale[ImputationGlobale::clePourType((string) $produit->type)][$champ] ?? null)
            ?? self::valeur($produit->categorieRelation?->$champ ?? null)
            ?? self::valeur($globale[ImputationGlobale::GENERALE][$champ] ?? null);
    }

    private static function valeur(mixed $compte): ?string
    {
        $compte = trim((string) ($compte ?? ''));

        return $compte !== '' ? $compte : null;
    }
}
