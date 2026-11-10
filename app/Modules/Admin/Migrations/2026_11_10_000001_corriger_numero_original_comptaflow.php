<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Assure la présence des numéros d'origine reçus de COMPTAFLOW.
 *
 * La migration initiale avait été déposée dans
 * app/Modules/Admin/Database/migrations, alors que les migrations du module
 * Admin sont chargées depuis app/Modules/Admin/Migrations. Sur les bases où
 * ce fichier n'a pas été exécuté, la réception du référentiel échoue dès le
 * premier compte avec « Unknown column numero_original ».
 *
 * Les colonnes restent nullable : les comptes et journaux locaux n'ont pas
 * nécessairement de numéro d'origine COMPTAFLOW.
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
