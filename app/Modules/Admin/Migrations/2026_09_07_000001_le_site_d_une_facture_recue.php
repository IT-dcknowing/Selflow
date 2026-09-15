<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le point de vente auquel une facture reçue du portail se rattache.
 *
 * ## Pourquoi une colonne, et non le relevé
 *
 * Demandé par le propriétaire du projet le 07/09/2026 : une facture d'achat
 * doit être associée à un site, comme toute autre pièce de Selflow. Elle
 * s'affichait « non affecté », et le filtre par site l'écartait des totaux.
 *
 * **Le portail ne donne pas cette information.** Le relevé porte bien un
 * `clientPointOfSale`, mais il décrit l'émetteur : le champ voisin,
 * `clientEstablishment`, valait « CIAN SIEGE » sur le relevé du 07/09 — le nom
 * du fournisseur, jamais le nôtre. `FneService` l'envoie symétriquement quand
 * c'est nous qui émettons (`pointOfSale` = notre point de vente,
 * `establishment` = notre raison sociale) : le portail restitue simplement, à
 * celui qui reçoit, ce que celui qui a émis avait déclaré.
 *
 * Rapprocher ce libellé de nos propres points marcherait par coïncidence — le
 * relevé du 07/09 portait « FACTURATION SIEGE » quand nous avons « FACTURATION
 * SIEGES » — et rangerait la facture du fournisseur suivant sous n'importe quel
 * site. Une pièce fiscale affectée au mauvais établissement ne se répare pas.
 *
 * D'où une colonne qui porte **notre** décision, et trois façons de la remplir,
 * toutes sûres :
 *
 * 1. l'entreprise n'a qu'un seul point de vente — il n'y a rien à trancher ;
 * 2. la facture est rattachée à un achat — le site vient de l'achat ;
 * 3. un utilisateur la désigne à l'écran.
 *
 * Nulle est une réponse : elle veut dire « personne n'a encore décidé », et
 * l'écran le dit ainsi plutôt que d'inventer un site.
 *
 * ## Ce qu'elle ne touche pas
 *
 * Rien ne part à la DGI. `FneService` est gelé et ne lit pas cette table : une
 * facture reçue n'est pas une pièce que Selflow émet. La colonne ne sert qu'à
 * ranger, côté Selflow, une charge sous le site qui l'a supportée.
 */
return new class extends Migration {
    public function up(): void
    {
        // Ce qu'elle pose, elle vérifie d'abord que ce ne l'est pas : une
        // migration décrit un état, pas un geste.
        if (Schema::hasColumn('portail_fne_factures_recues', 'point_de_vente_id')) {
            return;
        }

        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            // `nullOnDelete` et non `cascade` : supprimer un point de vente ne
            // doit pas emporter le constat d'une facture que la DGI détient
            // toujours. Elle retourne « à affecter », ce qui est vrai.
            $table->foreignId('point_de_vente_id')->nullable()->after('entreprise_id')
                ->constrained('points_de_vente')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('portail_fne_factures_recues', 'point_de_vente_id')) {
            return;
        }

        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            $table->dropForeign(['point_de_vente_id']);
            $table->dropColumn('point_de_vente_id');
        });
    }
};
