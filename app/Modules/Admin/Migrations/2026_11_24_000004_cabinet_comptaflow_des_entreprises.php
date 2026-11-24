<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le cabinet Comptaflow auquel le dossier d'une entreprise est affecté
 * (24/11/2026) : le cabinet seul, ou un membre précis du cabinet. Repris de la
 * réponse de Comptaflow, pour l'afficher ; Comptaflow reste la référence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (! Schema::hasColumn('entreprises', 'comptaflow_cabinet_id')) {
                $table->unsignedBigInteger('comptaflow_cabinet_id')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'comptaflow_cabinet_nom')) {
                $table->string('comptaflow_cabinet_nom', 191)->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'comptaflow_cabinet_membre')) {
                $table->string('comptaflow_cabinet_membre', 191)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['comptaflow_cabinet_id', 'comptaflow_cabinet_nom', 'comptaflow_cabinet_membre'] as $c) {
                if (Schema::hasColumn('entreprises', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
