<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le compte choisi pour une ligne d'achat hors catalogue, reprise du portail
 * FNE (23/11/2026) — le pendant de `vente_details.compte_vente`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('achat_details') && !Schema::hasColumn('achat_details', 'compte_achat')) {
            Schema::table('achat_details', function (Blueprint $table) {
                $table->string('compte_achat', 20)->nullable()->after('montant_ttc');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('achat_details', 'compte_achat')) {
            Schema::table('achat_details', fn (Blueprint $table) => $table->dropColumn('compte_achat'));
        }
    }
};
