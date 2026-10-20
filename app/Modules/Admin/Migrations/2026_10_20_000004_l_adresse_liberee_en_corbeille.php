<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'adresse libérée dès la mise en corbeille (20/10/2026).
 *
 * Une entreprise supprimée gardait ses comptes pendant ses 30 jours de
 * corbeille : la même adresse ne pouvait ni se réinscrire, ni passer par
 * Google, qui répondait « Votre compte est désactivé ». L'adresse est mise de
 * côté (`email_avant_corbeille`) et remplacée par un alias ; la restauration
 * la rend, si personne ne l'a reprise entre-temps.
 *
 * Reprise : les entreprises déjà en corbeille libèrent leurs adresses ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('utilisateurs', 'email_avant_corbeille')) {
            Schema::table('utilisateurs', function (Blueprint $table) {
                $table->string('email_avant_corbeille')->nullable()->after('email');
            });
        }

        $enCorbeille = DB::table('entreprises')->whereNotNull('corbeille_le')->pluck('id');
        DB::table('utilisateurs')->whereIn('entreprise_id', $enCorbeille)->whereNull('email_avant_corbeille')
            ->get(['id', 'email', 'entreprise_id'])
            ->each(fn ($u) => DB::table('utilisateurs')->where('id', $u->id)->update([
                'email_avant_corbeille' => $u->email,
                'email' => sprintf('corbeille.%d.%d@selflow.invalid', $u->entreprise_id, $u->id),
            ]));
    }

    public function down(): void
    {
        if (Schema::hasColumn('utilisateurs', 'email_avant_corbeille')) {
            Schema::table('utilisateurs', fn (Blueprint $table) => $table->dropColumn('email_avant_corbeille'));
        }
    }
};
