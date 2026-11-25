<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnement (25/11/2026, propriétaire) :
 * - `abonnement_demande_mois` : le nombre de mois choisi à l'inscription ;
 *   l'échéance se calcule quand le superadministrateur valide ;
 * - `message_abonnement` : le message que le superadministrateur adresse à
 *   l'entreprise, en haut de chaque page et dans la fenêtre de blocage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (! Schema::hasColumn('entreprises', 'abonnement_demande_mois')) {
                $table->unsignedTinyInteger('abonnement_demande_mois')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'message_abonnement')) {
                $table->text('message_abonnement')->nullable();
            }
            if (! Schema::hasColumn('entreprises', 'message_abonnement_le')) {
                $table->timestamp('message_abonnement_le')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['abonnement_demande_mois', 'message_abonnement', 'message_abonnement_le'] as $c) {
                if (Schema::hasColumn('entreprises', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
