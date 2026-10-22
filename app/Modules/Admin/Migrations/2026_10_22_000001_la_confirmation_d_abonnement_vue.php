<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'abonnement en attente (22/10/2026).
 *
 * - `bloquant` : le paiement a été déclaré sans période payée en cours ; tant
 *   qu'il n'est pas confirmé, l'espace de l'entreprise reste bloqué.
 * - `confirmation_vue_le` : « Abonnement confirmé » s'annonce une fois.
 *
 * Reprise : une souscription non confirmée (reçue, en cours ou en échec)
 * d'une entreprise qui n'a jamais eu de paiement confirmé devient bloquante —
 * c'est le cas relevé, où l'entreprise travaillait malgré l'échec. Les
 * paiements déjà confirmés sont tenus pour annoncés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements_abonnements', function (Blueprint $table) {
            if (! Schema::hasColumn('paiements_abonnements', 'bloquant')) {
                $table->boolean('bloquant')->default(false)->after('statut');
            }
            if (! Schema::hasColumn('paiements_abonnements', 'confirmation_vue_le')) {
                $table->timestamp('confirmation_vue_le')->nullable()->after('echec_le');
            }
        });

        $dejaConfirmees = DB::table('paiements_abonnements')->where('statut', 'active')->pluck('entreprise_id')->unique()->all();

        DB::table('paiements_abonnements')
            ->where('nature', 'souscription')
            ->whereIn('statut', ['recu', 'en_cours', 'echec'])
            ->whereNotIn('entreprise_id', $dejaConfirmees ?: [0])
            ->update(['bloquant' => true]);

        DB::table('paiements_abonnements')->where('statut', 'active')->whereNull('confirmation_vue_le')
            ->update(['confirmation_vue_le' => DB::raw('COALESCE(active_le, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('paiements_abonnements', function (Blueprint $table) {
            foreach (['bloquant', 'confirmation_vue_le'] as $colonne) {
                if (Schema::hasColumn('paiements_abonnements', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
