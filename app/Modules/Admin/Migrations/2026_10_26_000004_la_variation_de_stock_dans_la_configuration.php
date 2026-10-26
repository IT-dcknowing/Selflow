<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La configuration des comptes en un tableau (26/10/2026) : le compte de
 * variation de stock se personnalise comme ceux de vente et d'achat, par
 * catégorie et par type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configurations_comptes') && ! Schema::hasColumn('configurations_comptes', 'compte_variation')) {
            Schema::table('configurations_comptes', function (Blueprint $table) {
                $table->string('compte_variation', 20)->nullable()->after('compte_achat');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('configurations_comptes', 'compte_variation')) {
            Schema::table('configurations_comptes', function (Blueprint $table) {
                $table->dropColumn('compte_variation');
            });
        }
    }
};
