<?php

use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clé d'identité du point de vente (20/10/2026) : l'empreinte de son nom
 * ramené à l'essentiel — sans espaces ni ponctuation, sans « PDV », accents
 * gardés (la DGI distingue SIEGE et SIÈGE). « Guédé  Aya-Jacqueline » et
 * « GUÉDÉ AYA JACQUELINE » ont la même clé, et l'entreprise ne peut pas en
 * avoir deux (index unique). Les noms seulement proches (« GEUDE AYA
 * JAQUELINE ») sont signalés à la saisie.
 *
 * Reprise : un doublon exact déjà en base garde sa clé suffixée de son
 * identifiant, pour ne rien bloquer ; la commande
 * `selflow:doublons-points-de-vente` les nomme.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('points_de_vente', 'cle_nom')) {
            Schema::table('points_de_vente', function (Blueprint $table) {
                $table->string('cle_nom', 120)->nullable()->after('code_piece');
            });
        }

        $vues = [];
        DB::table('points_de_vente')->orderBy('id')->get(['id', 'entreprise_id', 'nom'])->each(function ($site) use (&$vues) {
            $cle = PointDeVente::empreinteDuNom((string) $site->nom);
            $id = $site->entreprise_id . '|' . $cle;
            $cle = isset($vues[$id]) ? $cle . '#' . $site->id : $cle;
            $vues[$id] = true;
            DB::table('points_de_vente')->where('id', $site->id)->update(['cle_nom' => $cle]);
        });

        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->unique(['entreprise_id', 'cle_nom'], 'points_de_vente_cle_nom_unique');
        });
    }

    public function down(): void
    {
        Schema::table('points_de_vente', function (Blueprint $table) {
            $table->dropUnique('points_de_vente_cle_nom_unique');
            $table->dropColumn('cle_nom');
        });
    }
};
