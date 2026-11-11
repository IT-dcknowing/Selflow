<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('entreprises', 'domaines_souscrits')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->json('domaines_souscrits')->nullable();
            });
        }

        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'domaine_configuration')) {
                $table->string('domaine_configuration', 120)->nullable()->index();
            }
            if (!Schema::hasColumn('categories', 'metier_configuration')) {
                $table->string('metier_configuration', 150)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('entreprises', 'domaines_souscrits')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->dropColumn('domaines_souscrits');
            });
        }

        Schema::table('categories', function (Blueprint $table) {
            foreach (['domaine_configuration', 'metier_configuration'] as $column) {
                if (Schema::hasColumn('categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
