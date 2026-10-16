<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'audit rattaché à l'entreprise (retour du 16/10/2026).
 *
 * `journal_audit` retenait déjà qui, quoi, quand et d'où. Pour la traçabilité
 * par entreprise, on ajoute `entreprise_id`, renseigné à l'écriture, et on
 * remplit le passé depuis l'utilisateur de chaque ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('journal_audit', 'entreprise_id')) {
            Schema::table('journal_audit', function (Blueprint $table) {
                $table->unsignedBigInteger('entreprise_id')->nullable()->index()->after('utilisateur_id');
            });

            // Rattacher l'historique : l'entreprise de l'utilisateur de la ligne.
            try {
                DB::statement('UPDATE journal_audit a JOIN utilisateurs u ON u.id = a.utilisateur_id SET a.entreprise_id = u.entreprise_id WHERE a.entreprise_id IS NULL');
            } catch (\Throwable $e) {
                // SQLite (tests) : la jointure d'update n'existe pas, et l'historique
                // y est vide de toute façon.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('journal_audit', 'entreprise_id')) {
            Schema::table('journal_audit', function (Blueprint $table) {
                $table->dropColumn('entreprise_id');
            });
        }
    }
};
