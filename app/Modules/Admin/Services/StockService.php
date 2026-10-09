<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Lot;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * La seule porte par laquelle le stock bouge.
 *
 * Le couple « modifier la fiche, puis écrire le mouvement » était recopié dans
 * plus de douze endroits, sur sept contrôleurs et deux contrôleurs d'API. Trois
 * conséquences, toutes constatées dans le dépôt :
 *
 * - **Rien ne garantissait la paire.** Une exception entre les deux gestes
 *   laissait un stock modifié sans trace, ou une trace sans stock. Plusieurs de
 *   ces blocs n'étaient pas dans une transaction.
 * - **`stock_avant` était lu sans verrou.** Deux ventes simultanées sur le même
 *   article lisaient la même valeur, écrivaient chacune leur `stock_apres`, et
 *   le journal annonçait un stock que la fiche démentait.
 * - **Les libellés divergeaient.** `ProductionControleur` écrivait « Entree »
 *   sans accent là où l'écran compare « Entrée » : une entrée de production
 *   s'affichait en rouge avec un signe moins.
 *
 * Ici, les trois gestes — verrouiller, modifier, journaliser — sont
 * indissociables. Une seule méthode les fait, et les motifs sont des
 * constantes.
 *
 * **Le journal ne s'efface pas.** Corriger un mouvement, c'est en écrire un de
 * sens inverse : `contrePasser()`. La ligne fautive reste lisible, et un écart
 * d'inventaire garde son explication six mois plus tard.
 */
class StockService
{
    /**
     * Faire entrer de la marchandise.
     *
     * @param  string  $motif  une des constantes de `MouvementStock`
     * @param  array{piece?: Model, reference?: string, fournisseur_id?: int, client_id?: int, contrepartie_id?: int, utilisateur_id?: int, contrepasse_id?: int, cout_unitaire?: float, raison_entree?: string, commentaire?: string}  $contexte
     */
    public static function entree(
        Produit $produit,
        int $pointDeVenteId,
        float $quantite,
        string $motif,
        array $contexte = []
    ): ?MouvementStock {
        return self::mouvementer($produit, $pointDeVenteId, $quantite, MouvementStock::ENTREE, $motif, $contexte);
    }

    /**
     * Faire sortir de la marchandise.
     *
     * Le stock peut passer sous zéro : c'est une anomalie, pas une impossibilité
     * — une livraison saisie avant sa réception, un comptage en retard. La
     * refuser ici bloquerait l'utilisateur sans rien corriger. C'est à l'appelant
     * de vérifier la disponibilité s'il doit le faire, avec `disponible()`, et
     * sous le même verrou.
     *
     * @param  array{piece?: Model, reference?: string, fournisseur_id?: int, client_id?: int, contrepartie_id?: int, utilisateur_id?: int, contrepasse_id?: int}  $contexte
     */
    public static function sortie(
        Produit $produit,
        int $pointDeVenteId,
        float $quantite,
        string $motif,
        array $contexte = []
    ): ?MouvementStock {
        return self::mouvementer($produit, $pointDeVenteId, $quantite, MouvementStock::SORTIE, $motif, $contexte);
    }

    /**
     * Déplacer de la marchandise d'un site à l'autre.
     *
     * Deux écritures, une seule transaction : un transfert à moitié écrit
     * ferait apparaître ou disparaître de la marchandise.
     *
     * @return array{0: ?MouvementStock, 1: ?MouvementStock} la sortie et l'entrée
     */
    public static function transferer(
        Produit $produit,
        int $sourceId,
        int $destinationId,
        float $quantite,
        array $contexte = []
    ): array {
        if ($sourceId === $destinationId) {
            throw new \InvalidArgumentException(
                'Un transfert va d\'un site à un autre : la source et la destination sont identiques.'
            );
        }

        return DB::transaction(function () use ($produit, $sourceId, $destinationId, $quantite, $contexte) {
            // Les deux sites sont verrouillés dans un ordre stable — le plus
            // petit identifiant d'abord — pour que deux transferts croisés
            // entre les mêmes sites ne s'attendent pas mutuellement.
            [$premier, $second] = $sourceId < $destinationId
                ? [$sourceId, $destinationId]
                : [$destinationId, $sourceId];

            self::verrouiller($produit, $premier);
            self::verrouiller($produit, $second);

            $sortie = self::sortie($produit, $sourceId, $quantite, MouvementStock::TRANSFERT,
                $contexte + ['contrepartie_id' => $destinationId]);

            $entree = self::entree($produit, $destinationId, $quantite, MouvementStock::TRANSFERT,
                $contexte + ['contrepartie_id' => $sourceId]);

            return [$sortie, $entree];
        });
    }

    /**
     * Aligner le stock sur ce qu'un comptage physique a trouvé.
     *
     * L'écart devient un mouvement, dans le sens qu'il faut. Un comptage
     * conforme n'écrit rien : un journal n'a pas à porter des lignes à zéro.
     */
    public static function inventorier(
        Produit $produit,
        int $pointDeVenteId,
        float $quantiteComptee,
        array $contexte = []
    ): ?MouvementStock {
        return DB::transaction(function () use ($produit, $pointDeVenteId, $quantiteComptee, $contexte) {
            $fiche = self::verrouiller($produit, $pointDeVenteId);
            $ecart = round($quantiteComptee - (float) $fiche->quantite_disponible, Stock::DECIMALES);

            if (abs($ecart) < 10 ** -Stock::DECIMALES) {
                return null;
            }

            return $ecart > 0
                ? self::entree($produit, $pointDeVenteId, $ecart, MouvementStock::INVENTAIRE, $contexte)
                : self::sortie($produit, $pointDeVenteId, abs($ecart), MouvementStock::INVENTAIRE, $contexte);
        });
    }

    /**
     * Annuler un mouvement par une écriture de sens inverse.
     *
     * C'est la réponse à « il faut défaire ce mouvement ». La ligne d'origine
     * reste, la nouvelle la désigne, et le stock revient à sa valeur.
     * Contre-passer deux fois le même mouvement est refusé : l'erreur
     * fabriquerait de la marchandise.
     */
    public static function contrePasser(MouvementStock $origine, array $contexte = []): MouvementStock
    {
        return DB::transaction(function () use ($origine, $contexte) {
            if ($origine->contrepassePar()->exists()) {
                throw new \LogicException(
                    "Le mouvement #{$origine->id} a déjà été contre-passé : le refaire "
                    . 'créerait de la marchandise qui n\'existe pas.'
                );
            }

            $produit = $origine->produit ?? Produit::findOrFail($origine->produit_id);

            $contexte += [
                'reference'      => $origine->reference_document,
                'piece'          => $origine->piece,
                'contrepasse_id' => $origine->id,
            ];

            $inverse = $origine->type_mouvement === MouvementStock::SORTIE
                ? self::entree($produit, $origine->point_de_vente_id, (float) $origine->quantite,
                    MouvementStock::CONTREPASSATION, $contexte)
                : self::sortie($produit, $origine->point_de_vente_id, (float) $origine->quantite,
                    MouvementStock::CONTREPASSATION, $contexte);

            // `entree`/`sortie` ne rendent null que pour un article non
            // stockable ; l'origine en est un, sinon elle n'existerait pas.
            return $inverse;
        });
    }

    /**
     * Contre-passer tous les mouvements d'une pièce.
     *
     * C'est ce qu'il faut appeler quand on modifie ou annule une vente déjà
     * facturée. `VenteControleur` faisait un `MouvementStock::where(...)
     * ->delete()` : le stock revenait juste, mais l'histoire disparaissait.
     *
     * @return int le nombre de mouvements contre-passés
     */
    public static function contrePasserLaPiece(Model $piece, array $contexte = []): int
    {
        return DB::transaction(function () use ($piece, $contexte) {
            $mouvements = MouvementStock::with('produit')
                ->where('piece_type', $piece->getMorphClass())
                ->where('piece_id', $piece->getKey())
                ->whereDoesntHave('contrepassePar')
                ->where('sous_type', '!=', MouvementStock::CONTREPASSATION)
                ->get();

            foreach ($mouvements as $mouvement) {
                self::contrePasser($mouvement, $contexte);
            }

            return $mouvements->count();
        });
    }

    /**
     * Quantité disponible, lue sous verrou.
     *
     * À utiliser lorsqu'une décision dépend du stock — refuser une vente, par
     * exemple. Lue sans verrou, la valeur est déjà périmée au moment où l'on
     * s'en sert : deux ventes simultanées la liraient toutes deux suffisante.
     */
    public static function disponible(Produit $produit, int $pointDeVenteId): float
    {
        return (float) self::verrouiller($produit, $pointDeVenteId)->quantite_disponible;
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Le geste unique : verrouiller, modifier la fiche, journaliser.
     */
    private static function mouvementer(
        Produit $produit,
        int $pointDeVenteId,
        float $quantite,
        string $sens,
        string $motif,
        array $contexte
    ): ?MouvementStock {
        // Un service ne s'épuise pas : lui tenir une fiche de stock le ferait
        // figurer en permanence dans les alertes de rupture.
        if (!$produit->estStockable()) {
            return null;
        }

        if (!in_array($motif, MouvementStock::MOTIFS, true)) {
            throw new \InvalidArgumentException(
                "Motif de mouvement inconnu : « {$motif} ». "
                . 'Les motifs valides sont des constantes de MouvementStock.'
            );
        }

        $quantite = round($quantite, Stock::DECIMALES);

        if ($quantite <= 0) {
            throw new \InvalidArgumentException(
                "Une quantité de mouvement est strictement positive ({$quantite} reçue). "
                . 'Le sens est porté par le type du mouvement, jamais par le signe.'
            );
        }

        return DB::transaction(function () use ($produit, $pointDeVenteId, $quantite, $sens, $motif, $contexte) {
            $fiche = self::verrouiller($produit, $pointDeVenteId);

            $avant = (float) $fiche->quantite_disponible;
            $apres = round($sens === MouvementStock::ENTREE ? $avant + $quantite : $avant - $quantite,
                Stock::DECIMALES);

            // Le **CUMP** (Coût Unitaire Moyen Pondéré) se recalcule à chaque
            // entrée, et jamais à une sortie : une sortie consomme le coût
            // moyen, elle ne le déplace pas.
            [$cout, $cumpApres] = self::valoriser($fiche, $sens, $quantite, $avant, $apres, $contexte);

            $fiche->update(['quantite_disponible' => $apres, 'cump' => $cumpApres]);

            $piece = $contexte['piece'] ?? null;

            $mouvement = MouvementStock::create([
                'produit_id'                     => $produit->id,
                'point_de_vente_id'              => $pointDeVenteId,
                'type_mouvement'                 => $sens,
                'sous_type'                      => $motif,
                'quantite'                       => $quantite,
                'stock_avant'                    => $avant,
                'stock_apres'                    => $apres,
                'cout_unitaire'                  => $cout,
                'cump_apres'                     => $cumpApres,
                'point_de_vente_contrepartie_id' => $contexte['contrepartie_id'] ?? null,
                'utilisateur_id'                 => $contexte['utilisateur_id'] ?? Auth::id(),
                'fournisseur_id'                 => $contexte['fournisseur_id'] ?? null,
                'client_id'                      => $contexte['client_id'] ?? null,
                'reference_document'             => $contexte['reference'] ?? null,
                'piece_type'                     => $piece?->getMorphClass(),
                'piece_id'                       => $piece?->getKey(),
                'contrepasse_id'                 => $contexte['contrepasse_id'] ?? null,
                'raison_entree'                  => $contexte['raison_entree'] ?? null,
                'commentaire'                    => $contexte['commentaire'] ?? null,
            ]);

            // L'inventaire permanent, c'est cela : le stock se met a jour en
            // valeur a chaque mouvement, et non une fois l'an au comptage.
            // L'ecriture n'est donc pas une consequence du mouvement — elle en
            // fait partie, et se declenche depuis la porte unique plutot que
            // depuis les huit endroits qui deplacent de la marchandise.
            //
            // Aucun compte de classe 3 n'etait mouvemente jusqu'ici : le stock
            // existait en quantite, pas en valeur, et aucun bilan ne pouvait
            // etre etabli.
            // **Le suivi par lot ne remplace pas le stock, il le double.** Le
            // mouvement reste un seul mouvement — la comptabilité, le CUMP
            // (Coût Unitaire Moyen Pondéré) et le journal ne changent pas — et
            // porte à côté de lui le détail des arrivages qu'il a touchés.
            //
            // Faire des lots une seconde valorisation donnerait deux vérités
            // sur la valeur du stock, qui divergeraient au premier arrondi, et
            // la balance ne saurait plus laquelle croire.
            self::ventilerParLot($mouvement, $produit, $pointDeVenteId, $quantite, $sens, $motif, $contexte);

            $mouvement->setRelation('produit', $produit);
            InventairePermanentService::comptabiliser($mouvement);

            return $mouvement;
        });
    }

    /**
     * Rattacher le mouvement aux arrivages qu'il a touchés.
     *
     * Trois chemins, et chacun a sa raison :
     *
     * - **une contre-passation** rend au lot ce que le mouvement d'origine lui
     *   avait pris. Sans cela, une vente annulée ferait disparaître la
     *   traçabilité du lot vendu, et un rappel du fabricant ne retrouverait
     *   plus le client ;
     * - **une entrée** alimente le lot que l'appelant désigne. Sans numéro de
     *   lot, rien n'est écrit : un stock d'ouverture, un transfert, un écart
     *   d'inventaire n'ont pas d'arrivage à eux ;
     * - **une sortie** prélève en FEFO — *First Expired, First Out* —, du plus
     *   proche de sa date au plus lointain.
     */
    private static function ventilerParLot(
        MouvementStock $mouvement,
        Produit $produit,
        int $pointDeVenteId,
        float $quantite,
        string $sens,
        string $motif,
        array $contexte
    ): void {
        if (!$produit->suivi_par_lot) {
            return;
        }

        if ($motif === MouvementStock::CONTREPASSATION && !empty($contexte['contrepasse_id'])) {
            $origine = MouvementStock::find($contexte['contrepasse_id']);

            if ($origine) {
                // Le mouvement inverse porte la même ventilation que celui
                // qu'il défait : c'est ce qui rend la chaîne lisible depuis le
                // lot, dans les deux sens.
                LotService::attacher($mouvement, LotService::defaire($origine));
            }

            return;
        }

        if ($sens === MouvementStock::ENTREE) {
            $lot = LotService::entrer(
                $produit, $pointDeVenteId, $quantite,
                $contexte['lot'] ?? [],
                (float) ($mouvement->cout_unitaire ?? 0)
            );

            if ($lot) {
                LotService::attacher($mouvement, [['lot' => $lot, 'quantite' => $quantite]]);
            }

            return;
        }

        LotService::attacher(
            $mouvement,
            LotService::prelever($produit, $pointDeVenteId, $quantite, $motif)
        );
    }

    /**
     * Valoriser un mouvement, et en déduire le nouveau CUMP (Coût Unitaire
     * Moyen Pondéré).
     *
     * `produits.prix_achat` tenait lieu de coût : un prix de catalogue, figé,
     * saisi une fois. La marge affichée était donc fausse dès que le
     * fournisseur changeait ses prix — un sac acheté 12 000 puis 15 000 restait
     * valorisé au prix de la fiche — et le bilan ne pouvait pas être établi.
     *
     * **À l'entrée**, le coût moyen se recalcule en pondérant l'ancien stock
     * par son coût et l'entrée par le sien :
     *
     *     CUMP = (Q_ancienne × CUMP_ancien + Q_entrée × coût_entrée)
     *            ÷ (Q_ancienne + Q_entrée)
     *
     * **À la sortie**, rien ne bouge : une sortie consomme le coût moyen, elle
     * ne le déplace pas. C'est la définition même du procédé, et c'est ce qui
     * le rend indépendant de l'ordre des ventes.
     *
     * Trois situations demandent une décision, et chacune a sa raison :
     *
     * - **entrée sans coût connu** — un transfert, un retour client, un écart
     *   d'inventaire en plus. On reprend le CUMP en place : la marchandise
     *   n'a pas changé de valeur en changeant de main. Pour un premier
     *   mouvement sur une fiche vide, on retombe sur `prix_achat`, faute de
     *   mieux, plutôt que de valoriser à zéro ;
     * - **stock négatif ou nul après l'entrée** — la moyenne pondérée n'a plus
     *   de sens sur une quantité qui ne peut pas la porter. On garde le coût de
     *   l'entrée, qui est la seule information sûre ;
     * - **stock à zéro avant l'entrée** — pas de moyenne à faire : le coût de
     *   l'entrée devient le CUMP.
     *
     * @return array{0: float, 1: float} le coût du mouvement, et le CUMP après
     */
    private static function valoriser(
        Stock $fiche,
        string $sens,
        float $quantite,
        float $avant,
        float $apres,
        array $contexte
    ): array {
        $cumpAvant = (float) $fiche->cump;

        if ($sens === MouvementStock::SORTIE) {
            // La sortie est valorisée au coût moyen, qui ne bouge pas.
            return [$cumpAvant, $cumpAvant];
        }

        $cout = isset($contexte['cout_unitaire'])
            ? round((float) $contexte['cout_unitaire'], Stock::DECIMALES_COUT)
            : ($cumpAvant > 0 ? $cumpAvant : (float) ($fiche->produit?->prix_achat ?? 0));

        if ($avant <= 0 || $apres <= 0) {
            return [$cout, round($cout, Stock::DECIMALES_COUT)];
        }

        $cump = (($avant * $cumpAvant) + ($quantite * $cout)) / $apres;

        return [$cout, round($cump, Stock::DECIMALES_COUT)];
    }

    /**
     * Valeur du stock d'un article sur un site : quantité × CUMP (Coût
     * Unitaire Moyen Pondéré).
     *
     * C'est ce qu'un compte de classe 3 doit porter au bilan.
     */
    public static function valeurDuStock(Produit $produit, int $pointDeVenteId): float
    {
        $fiche = Stock::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $pointDeVenteId)
            ->first();

        if (!$fiche) {
            return 0.0;
        }

        return round((float) $fiche->quantite_disponible * (float) $fiche->cump, 2);
    }

    /**
     * La fiche de stock du couple article / site, verrouillée pour cette
     * transaction, créée si elle n'existe pas.
     *
     * `lockForUpdate` fait attendre toute autre transaction qui voudrait la
     * même ligne — c'est ce qui manquait : `stock_avant` était lu librement,
     * et deux ventes simultanées écrivaient chacune un `stock_apres` calculé
     * depuis la même valeur périmée.
     *
     * Le verrou ne s'obtient que dans une transaction ; les méthodes publiques
     * en ouvrent toutes une.
     */
    private static function verrouiller(Produit $produit, int $pointDeVenteId): Stock
    {
        Stock::firstOrCreate(
            ['produit_id' => $produit->id, 'point_de_vente_id' => $pointDeVenteId],
            ['quantite_disponible' => 0, 'stock_minimum' => 5, 'stock_maximum' => 100]
        );

        return Stock::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $pointDeVenteId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
