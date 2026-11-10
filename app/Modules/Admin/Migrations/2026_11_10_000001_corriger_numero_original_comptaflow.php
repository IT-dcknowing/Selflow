<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assure la présence des numéros d'origine reçus de COMPTAFLOW.
 *
 * La migration initiale était rangée dans un répertoire différent de celui
 * utilisé par les migrations du module Admin. Sur les bases où elle n'a pas
 * été exécutée, la réception échoue avec « Unknown column numero_original ».
 *
 * Les colonnes restent nullable : les lignes locales n'ont pas forcément
 * de numéro d'origine COMPTAFLOW.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_comptable') && !Schema::hasColumn('plan_comptable', 'numero_original')) {
            Schema::table('plan_comptable', function (Blueprint $table) {
                $table->string('numero_original', 100)->nullable()->after('numero')
                    ->comment('Numéro original dans COMPTAFLOW avant renumérotation');
            });
        }

        if (Schema::hasTable('codes_journaux') && !Schema::hasColumn('codes_journaux', 'numero_original')) {
            Schema::table('codes_journaux', function (Blueprint $table) {
                $table->string('numero_original', 100)->nullable()->after('code')
                    ->comment('Code original dans COMPTAFLOW avant renumérotation');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plan_comptable') && Schema::hasColumn('plan_comptable', 'numero_original')) {
            Schema::table('plan_comptable', function (Blueprint $table) {
                $table->dropColumn('numero_original');
            });
        }

        if (Schema::hasTable('codes_journaux') && Schema::hasColumn('codes_journaux', 'numero_original')) {
            Schema::table('codes_journaux', function (Blueprint $table) {
                $table->dropColumn('numero_original');
            });
        }
    }
};
