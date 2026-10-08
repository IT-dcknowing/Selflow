<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les lignes du bon de livraison (recette du 08/10/2026).
 *
 * Un bon de commande se livre désormais en plusieurs bons, chacun plafonné au
 * reste à livrer. Deux choses manquaient pour le tenir :
 *
 * - **la ligne de commande d'où vient chaque ligne livrée.** Le bon ne gardait
 *   que l'article : une commande qui portait deux fois le même article (deux
 *   prix, deux remises) ne savait plus laquelle avait été livrée, et la
 *   facture du bon prenait la première ;
 * - **des quantités décimales.** Les lignes de vente se comptent en décimales
 *   depuis le 09/08/2026 (kilos, litres) ; le bon les gardait en entiers, et
 *   2,5 kg livrés s'inscrivaient 2 ou 3 selon la base.
 *
 * Additive : la colonne nouvelle est nullable — les bons déjà établis se
 * rapprochent de leur ligne par l'article, comme avant —, et l'élargissement
 * d'entier en décimal ne perd aucune valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bon_livraison_details')) {
            return;
        }

        Schema::table('bon_livraison_details', function (Blueprint $table) {
            if (!Schema::hasColumn('bon_livraison_details', 'vente_detail_id')) {
                $table->foreignId('vente_detail_id')->nullable()->after('bon_livraison_id')
                    ->constrained('vente_details')->nullOnDelete();
            }
        });

        Schema::table('bon_livraison_details', function (Blueprint $table) {
            $table->decimal('qte_commandee', 15, 3)->default(0)->change();
            $table->decimal('qte_livree', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bon_livraison_details')) {
            return;
        }

        Schema::table('bon_livraison_details', function (Blueprint $table) {
            if (Schema::hasColumn('bon_livraison_details', 'vente_detail_id')) {
                $table->dropConstrainedForeignId('vente_detail_id');
            }
        });

        Schema::table('bon_livraison_details', function (Blueprint $table) {
            $table->integer('qte_commandee')->change();
            $table->integer('qte_livree')->change();
        });
    }
};
