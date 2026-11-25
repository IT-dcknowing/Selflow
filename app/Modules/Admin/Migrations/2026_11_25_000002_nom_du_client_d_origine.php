<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les clients de passage (B2C) des factures du portail FNE sont rangés sous
 * « Client divers » (25/11/2026, propriétaire) ; le nom porté par la facture
 * est gardé ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ventes') && ! Schema::hasColumn('ventes', 'nom_client_origine')) {
            Schema::table('ventes', function (Blueprint $table) {
                $table->string('nom_client_origine', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventes') && Schema::hasColumn('ventes', 'nom_client_origine')) {
            Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('nom_client_origine'));
        }
    }
};
