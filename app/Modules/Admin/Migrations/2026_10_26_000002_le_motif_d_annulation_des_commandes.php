<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'annulation des devis, bons de commande et commandes d'achat
 * (26/10/2026) garde son motif sur la pièce : aucun avoir ne le porte, rien
 * n'ayant été livré, reçu ni facturé.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ventes', 'achats'] as $table) {
            if (! Schema::hasColumn($table, 'motif_annulation')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('motif_annulation', 200)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['ventes', 'achats'] as $table) {
            if (Schema::hasColumn($table, 'motif_annulation')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('motif_annulation');
                });
            }
        }
    }
};
