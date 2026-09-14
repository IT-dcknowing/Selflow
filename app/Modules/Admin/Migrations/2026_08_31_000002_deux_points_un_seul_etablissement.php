<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La date à laquelle le portail a créé ce point de facturation.
 *
 * ## Pourquoi une seconde colonne, le jour même
 *
 * La migration précédente (`2026_08_31_000001`) pose `etablissement_fne_id` en
 * le croyant propre à un point de facturation. Le relevé réel du 31/08/2026 a
 * montré le contraire : le portail donne **le même identifiant d'établissement
 * à tous les points d'un même établissement** — « FACTURATION SIEGE » et
 * « FACTURATION TEST 2 » portent l'un et l'autre `42200613-f402-40a8-bd4d-…`.
 *
 * Seule leur **date de création** les distingue : propre à chacun, et stable
 * quand l'intitulé change — un point renommé reste donc le même point. C'est
 * la paire qui identifie, et c'est elle qui apparie un point de vente de
 * Selflow au point de facturation dont il vient.
 *
 * La migration précédente n'est pas retouchée : elle est appliquée, et une
 * migration appliquée ne se rejoue pas. Ce qui manque se pose par une
 * migration nouvelle.
 *
 * Comme sa jumelle, cette colonne **ne part pas à la DGI** : `FneService` est
 * gelé et n'est pas touché.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('points_de_vente', 'point_fne_cree_a')) {
            return;
        }

        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->timestamp('point_fne_cree_a')->nullable()->after('etablissement_fne_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('points_de_vente', 'point_fne_cree_a')) {
            return;
        }

        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->dropColumn('point_fne_cree_a');
        });
    }
};
