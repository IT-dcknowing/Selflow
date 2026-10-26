<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masquer à la vente les articles en rupture (26/10/2026) : un réglage par
 * entreprise, éteint par défaut. L'administrateur de l'entreprise voit tout.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('entreprises', 'masquer_ruptures_vente')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->boolean('masquer_ruptures_vente')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('entreprises', 'masquer_ruptures_vente')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->dropColumn('masquer_ruptures_vente');
            });
        }
    }
};
