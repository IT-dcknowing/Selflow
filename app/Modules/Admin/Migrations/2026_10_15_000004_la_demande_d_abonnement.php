<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La dernière demande d'offre d'une entreprise (renouvellement ou passage à
 * une offre supérieure) — 15/10/2026. Le paiement en ligne n'est pas encore
 * branché : la demande attend que DC-Knowing confirme le règlement et repousse
 * l'échéance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (! Schema::hasColumn('entreprises', 'abonnement_demande')) {
                $table->string('abonnement_demande', 30)->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'abonnement_demande_le')) {
                $table->timestamp('abonnement_demande_le')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['abonnement_demande', 'abonnement_demande_le'] as $colonne) {
                if (Schema::hasColumn('entreprises', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
