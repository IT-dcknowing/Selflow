<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (!Schema::hasColumn('entreprises', 'domaines_personnalises')) {
                $table->json('domaines_personnalises')->nullable();
            }
            if (!Schema::hasColumn('entreprises', 'secteurs_personnalises')) {
                $table->json('secteurs_personnalises')->nullable();
            }
            if (!Schema::hasColumn('entreprises', 'modules_personnalises')) {
                $table->json('modules_personnalises')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['domaines_personnalises', 'secteurs_personnalises', 'modules_personnalises'] as $column) {
                if (Schema::hasColumn('entreprises', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
