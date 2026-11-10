<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le numéro d'origine COMPTAFLOW aux tiers synchronisés.
 *
 * La réception des fournisseurs et des clients renseigne numero_original.
 * Les colonnes sont nullable pour préserver les tiers créés localement.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fournisseurs', 'clients'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'numero_original')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('numero_original', 100)->nullable()
                        ->comment('Numéro original du tiers dans COMPTAFLOW');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['fournisseurs', 'clients'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'numero_original')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('numero_original');
                });
            }
        }
    }
};
