<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('entreprises', 'secteurs_selectionnes_par_domaine')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->json('secteurs_selectionnes_par_domaine')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('entreprises', 'secteurs_selectionnes_par_domaine')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->dropColumn('secteurs_selectionnes_par_domaine');
            });
        }
    }
};
