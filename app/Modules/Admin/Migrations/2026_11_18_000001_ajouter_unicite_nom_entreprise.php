<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            $table->string('nom_normalise', 191)->nullable();
        });

        // Les éventuels doublons historiques ne doivent pas faire échouer
        // la migration : le premier dossier conserve la valeur canonique,
        // les autres restent inchangés mais ne pourront pas en créer un nouveau.
        $vus = [];
        DB::table('entreprises')->select('id', 'nom')->orderBy('id')->get()
            ->each(function ($entreprise) use (&$vus) {
                $nom = preg_replace('/\s+/u', ' ', trim((string) $entreprise->nom));
                $normalise = mb_strtolower($nom, 'UTF-8');

                if ($normalise === '' || isset($vus[$normalise])) {
                    return;
                }

                $vus[$normalise] = true;
                DB::table('entreprises')->where('id', $entreprise->id)
                    ->update(['nom_normalise' => $normalise]);
            });

        Schema::table('entreprises', function (Blueprint $table) {
            $table->unique('nom_normalise', 'entreprises_nom_normalise_unique');
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            $table->dropUnique('entreprises_nom_normalise_unique');
            $table->dropColumn('nom_normalise');
        });
    }
};
