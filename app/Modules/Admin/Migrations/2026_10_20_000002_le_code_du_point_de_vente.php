<?php

use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le code court du point de vente (20/10/2026), qui entre dans le numéro des
 * pièces quand l'entreprise a plusieurs sites : VTE-BDK-201026-001. Chaque
 * site numérote à partir de 1, sans jamais croiser le numéro d'un autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('points_de_vente', 'code_piece')) {
            Schema::table('points_de_vente', function (Blueprint $table) {
                $table->string('code_piece', 8)->nullable()->after('nom');
            });
        }

        DB::table('points_de_vente')->whereNull('code_piece')->orderBy('id')->get(['id', 'entreprise_id', 'nom'])
            ->each(function ($site) {
                DB::table('points_de_vente')->where('id', $site->id)->update([
                    'code_piece' => PointDeVente::codeLibre((int) $site->entreprise_id, (string) $site->nom, (int) $site->id),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('points_de_vente', 'code_piece')) {
            Schema::table('points_de_vente', fn (Blueprint $table) => $table->dropColumn('code_piece'));
        }
    }
};
