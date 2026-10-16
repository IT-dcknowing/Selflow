<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La traçabilité du trafic et le blocage d'IP (retour du 16/10/2026).
 *
 * Deux tables :
 *  - `visites` : une ligne par requête (IP, page, statut, durée, qui), de quoi
 *    bâtir les KPI, le graphe en direct, l'activité par adresse IP et le temps
 *    passé par page. Purgée au-delà de 30 jours.
 *  - `ip_bloquees` : les adresses interdites d'accès — elles ne voient plus
 *    rien, pas même l'URL (403 immédiat).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('visites')) {
            Schema::create('visites', function (Blueprint $table) {
                $table->id();
                $table->string('ip', 45)->index();
                $table->string('methode', 10);
                $table->string('chemin', 500);
                $table->unsignedSmallInteger('statut')->nullable()->index();
                $table->unsignedInteger('duree_ms')->nullable();
                $table->unsignedBigInteger('utilisateur_id')->nullable()->index();
                $table->unsignedBigInteger('entreprise_id')->nullable()->index();
                $table->string('agent', 255)->nullable();
                $table->string('session_id', 64)->nullable()->index();
                $table->timestamp('cree_le')->index();
            });
        }

        if (!Schema::hasTable('ip_bloquees')) {
            Schema::create('ip_bloquees', function (Blueprint $table) {
                $table->id();
                $table->string('ip', 45)->unique();
                $table->string('motif', 255)->nullable();
                $table->unsignedBigInteger('bloquee_par')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visites');
        Schema::dropIfExists('ip_bloquees');
    }
};
