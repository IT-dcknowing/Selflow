<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Operation;

/**
 * L'écriture comptable qu'un mouvement de stock produit.
 *
 * **Aucun compte de classe 3 n'était mouvementé.** Ni 31 (marchandises), ni 32
 * (matières), ni 36 (produits finis), ni les comptes de variation 603 et 736.
 * Le stock existait donc en quantité, dans `stocks` et `mouvements_stock`, mais
 * pas en valeur : la balance n'en portait pas trace, et aucun bilan ne pouvait
 * être établi. C'est le manque que ce service comble.
 *
 * **L'inventaire permanent, c'est cela** : le stock se met à jour à chaque
 * mouvement, et non une fois l'an au comptage. L'écriture n'est donc pas une
 * conséquence du mouvement — elle en fait partie. C'est pourquoi elle se
 * déclenche depuis `StockService`, la porte unique, et non depuis les huit
 * endroits qui déplacent de la marchandise.
 *
 * Les deux sens, en SYSCOHADA révisé :
 *
 * | Mouvement | Débit | Crédit |
 * |---|---|---|
 * | **Entrée** — le stock grossit | Compte de stock (3x) | Compte de variation (603x, 736) |
 * | **Sortie** — le stock diminue | Compte de variation | Compte de stock |
 *
 * Le compte de variation est un compte de gestion : il porte la consommation de
 * la période. Sa contrepartie au bilan est le compte de stock. Aucune des deux
 * écritures ne touche au résultat directement — c'est le jeu achat/variation
 * qui donne la charge consommée, et vente/coût qui donne la marge.
 *
 * **Trois cas ne produisent rien**, et chacun pour une raison :
 *
 * - un article **non stockable** — une prestation n'a pas de valeur au bilan ;
 * - un article **sans compte de stock ou sans compte de variation** — écrire
 *   l'un sans l'autre déséquilibrerait la balance, et le déséquilibre
 *   n'apparaîtrait que des semaines plus tard. `ImputationService` refuse ;
 * - une **valeur nulle** — un mouvement valorisé à zéro n'a rien à dire au
 *   bilan, et une ligne à zéro encombre le grand livre sans rien y apporter.
 */
class InventairePermanentService
{
    /**
     * Écrire, s'il y a lieu, l'écriture de stock d'un mouvement.
     *
     * @return EcritureComptable|null l'écriture, ou `null` si rien à écrire
     */
    public static function comptabiliser(MouvementStock $mouvement): ?EcritureComptable
    {
        $produit = $mouvement->produit;

        if (!ImputationService::peutTenirLInventairePermanent($produit)) {
            return null;
        }

        $valeur = round((float) $mouvement->quantite * (float) ($mouvement->cout_unitaire ?? 0), 2);

        if ($valeur <= 0) {
            return null;
        }

        $compteStock     = ImputationService::compteStock($produit);
        $compteVariation = ImputationService::compteVariation($produit);
        $estEntree       = $mouvement->type_mouvement === MouvementStock::ENTREE;

        $entrepriseId = $produit->entreprise_id;
        $date = ($mouvement->created_at ?? now())->toDateString();

        // Les mouvements de stock relèvent des opérations diverses : ils ne
        // sont ni une vente, ni un achat, ni un encaissement. Le journal OD est
        // celui qui les accueille dans tous les plans que j'ai vus.
        $codeJournal = self::codeJournalOd($entrepriseId);

        $libelle = self::libelle($mouvement, $produit->nom);

        // Un ajustement d'inventaire n'a pas d'autre piece que lui-meme : il
        // porte alors sa propre reference. La colonne n'accepte pas le vide, et
        // une ecriture sans reference serait introuvable au grand livre.
        $reference = $mouvement->reference_document ?: 'MVT-' . $mouvement->id;

        $operation = Operation::creer(
            $entrepriseId,
            $mouvement->point_de_vente_id,
            $date,
            'mouvement_stock',
            $codeJournal,
            $reference,
            $libelle
        );

        // **Deux lignes, et non une seule portant les deux comptes.**
        //
        // C'est la convention de tout le reste du projet — `ComptabiliteService`
        // écrit ainsi depuis l'origine — et c'est aussi celle de Comptaflow, où
        // une écriture porte **un** compte. Une ligne portant les deux comptes
        // se déverserait de travers : le point d'entrée de Comptaflow retient
        // `compte_debit` s'il est présent et **ignore `compte_credit`**, si bien
        // que les deux montants s'imputeraient sur le seul compte de stock. Le
        // compte de variation resterait vide, et la balance de Comptaflow
        // divergerait de celle de Selflow sans que rien ne le signale.
        $commun = [
            'operation_id'       => $operation->id,
            'entreprise_id'      => $entrepriseId,
            'point_de_vente_id'  => $mouvement->point_de_vente_id,
            'date_ecriture'      => $date,
            'libelle'            => $libelle,
            'reference_document' => $reference,
            'code_journal'       => $codeJournal,
        ];

        $compteDebite  = $estEntree ? $compteStock : $compteVariation;
        $compteCredite = $estEntree ? $compteVariation : $compteStock;

        $ligneDebit = EcritureComptable::create($commun + [
            'compte_debit'  => $compteDebite,
            'compte_credit' => null,
            'debit'         => $valeur,
            'credit'        => 0,
        ]);

        EcritureComptable::create($commun + [
            'compte_debit'  => null,
            'compte_credit' => $compteCredite,
            'debit'         => 0,
            'credit'        => $valeur,
        ]);

        $operation->cloturerEquilibre();

        return $ligneDebit;
    }

    /**
     * Le libellé porte le motif : c'est ce qui permet de retrouver, au grand
     * livre, pourquoi le stock a bougé — sans revenir au journal de stock.
     */
    private static function libelle(MouvementStock $mouvement, string $nomProduit): string
    {
        $motifs = [
            MouvementStock::RECEPTION              => 'Entrée en stock',
            MouvementStock::RETOUR_CLIENT          => 'Retour client en stock',
            MouvementStock::RETOUR_FOURNISSEUR     => 'Retour fournisseur',
            MouvementStock::LIVRAISON              => 'Sortie de stock',
            MouvementStock::TRANSFERT              => 'Transfert de stock',
            MouvementStock::REBUT                  => 'Mise au rebut',
            MouvementStock::INVENTAIRE             => 'Écart d\'inventaire',
            MouvementStock::PRODUCTION_ENTREE      => 'Entrée de production',
            MouvementStock::PRODUCTION_CONSOMMATION => 'Consommation de production',
            MouvementStock::CONTREPASSATION        => 'Contre-passation',
            MouvementStock::STOCK_INITIAL          => 'Stock d\'ouverture',
            MouvementStock::ENTREE_DIVERSE         => 'Entrée de stock',
        ];

        $motif = $motifs[$mouvement->sous_type] ?? 'Mouvement de stock';

        // L'entrée diverse dit sa raison au grand livre : un don et un achat
        // sans facture n'appellent pas la même régularisation.
        if ($mouvement->raison_entree && isset(MouvementStock::RAISONS_ENTREE[$mouvement->raison_entree])) {
            $motif .= ' (' . mb_strtolower(MouvementStock::RAISONS_ENTREE[$mouvement->raison_entree]) . ')';
        }

        return mb_substr("{$motif} — {$nomProduit}", 0, 190);
    }

    /**
     * Le journal des opérations diverses de l'entreprise, `OD` à défaut.
     */
    private static function codeJournalOd(int $entrepriseId): string
    {
        return \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $entrepriseId)
            ->where('type', 'OD')
            ->value('code') ?? 'OD';
    }
}
