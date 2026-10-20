<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les tiers et les soldes de départ rattachés à un point de vente
 * (20/10/2026) : « on doit pouvoir dissocier les clients et fournisseurs de
 * chaque point de vente ». Vide pour l'existant : un tiers sans site se
 * reconnaît par ses pièces, ou reste commun s'il n'en a aucune.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'fournisseurs', 'soldes_initiaux'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'point_de_vente_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('point_de_vente_id')->nullable()->index();
                });
            }
        }

        // Un solde de départ par tiers **et par site**. Le nouvel index avant
        // la suppression de l'ancien : sur MySQL, la clé étrangère
        // `entreprise_id` refuse de rester sans index (erreur 1553).
        Schema::table('soldes_initiaux', function (Blueprint $t) {
            $t->unique(['entreprise_id', 'type', 'tiers_id', 'point_de_vente_id'], 'soldes_initiaux_tiers_site_unique');
        });
        Schema::table('soldes_initiaux', function (Blueprint $t) {
            $t->dropUnique(['entreprise_id', 'type', 'tiers_id']);
        });
    }

    public function down(): void
    {
        Schema::table('soldes_initiaux', function (Blueprint $t) {
            $t->unique(['entreprise_id', 'type', 'tiers_id']);
        });
        Schema::table('soldes_initiaux', function (Blueprint $t) {
            $t->dropUnique('soldes_initiaux_tiers_site_unique');
        });
        foreach (['clients', 'fournisseurs', 'soldes_initiaux'] as $table) {
            if (Schema::hasColumn($table, 'point_de_vente_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('point_de_vente_id'));
            }
        }
    }
};
