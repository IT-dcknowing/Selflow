<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La corbeille des entreprises (retour du 16/10/2026).
 *
 * Supprimer une entreprise effaçait tout d'un bloc, dans la requête : sur la
 * base de production, l'introspection du schéma et les suppressions en cascade
 * dépassaient cinq minutes et le navigateur rendait la main avant la fin.
 *
 * Désormais, « Supprimer » ne fait que dater la mise en corbeille : instantané.
 * L'entreprise disparaît des listes et ses utilisateurs n'entrent plus. Elle y
 * reste 30 jours, avec un bouton « Restaurer » ; passé ce délai — ou sur
 * « Vider la corbeille » — l'effacement réel part en arrière-plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (!Schema::hasColumn('entreprises', 'corbeille_le')) {
                $table->timestamp('corbeille_le')->nullable()->index()->after('modules_actifs');
            }
            if (!Schema::hasColumn('entreprises', 'corbeille_par')) {
                $table->unsignedBigInteger('corbeille_par')->nullable()->after('corbeille_le');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            foreach (['corbeille_le', 'corbeille_par'] as $colonne) {
                if (Schema::hasColumn('entreprises', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
