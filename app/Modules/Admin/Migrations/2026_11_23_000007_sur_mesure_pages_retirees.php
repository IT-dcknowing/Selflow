<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Selflow sur mesure (23/11/2026) : les pages retirées pour l'entreprise. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('espaces_sur_mesure') && ! Schema::hasColumn('espaces_sur_mesure', 'pages_retirees')) {
            Schema::table('espaces_sur_mesure', function (Blueprint $table) {
                $table->json('pages_retirees')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('espaces_sur_mesure') && Schema::hasColumn('espaces_sur_mesure', 'pages_retirees')) {
            Schema::table('espaces_sur_mesure', fn (Blueprint $table) => $table->dropColumn('pages_retirees'));
        }
    }
};
