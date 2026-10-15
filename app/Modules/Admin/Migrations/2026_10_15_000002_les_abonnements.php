<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les offres d'abonnement — décision du propriétaire du 15/10/2026.
 *
 * SOLO, BUSINESS, PREMIUM, SUR MESURE et le PASS CADEAU de sept jours. Chaque
 * offre porte ses contraintes (points de vente, utilisateurs), que le
 * superadministrateur peut relever sans changer d'offre.
 *
 * `quota_utilisateurs` vide : aucune limite — ce qui vaut pour les entreprises
 * créées avant les offres, qui gardent ce qu'elles ont.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (! Schema::hasColumn('entreprises', 'quota_utilisateurs')) {
                $table->unsignedInteger('quota_utilisateurs')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'abonnement_statut')) {
                $table->string('abonnement_statut', 30)->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'abonnement_choisi_le')) {
                $table->timestamp('abonnement_choisi_le')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'abonnement_expire_le')) {
                $table->timestamp('abonnement_expire_le')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'pass_cadeau_utilise_le')) {
                $table->timestamp('pass_cadeau_utilise_le')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['quota_utilisateurs', 'abonnement_statut', 'abonnement_choisi_le', 'abonnement_expire_le', 'pass_cadeau_utilise_le'] as $colonne) {
                if (Schema::hasColumn('entreprises', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
