<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le portail où le robot va chercher les factures (24/11/2026) : test ou
 * production, choisi par le superadmin. Une entreprise sans clé (ni de test
 * ni de production) peut ainsi être relevée sur le bon portail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fne_credentials') && ! Schema::hasColumn('fne_credentials', 'portail_releve')) {
            Schema::table('fne_credentials', function (Blueprint $table) {
                $table->string('portail_releve', 12)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fne_credentials') && Schema::hasColumn('fne_credentials', 'portail_releve')) {
            Schema::table('fne_credentials', fn (Blueprint $table) => $table->dropColumn('portail_releve'));
        }
    }
};
