<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les fiches de Selflow et de Comptaflow, sur la page d'accueil, mènent à
 * leur application (retest d'octobre 2026 : « les liens vers les
 * différentes applications »).
 *
 * Seules ces deux adresses sont connues ; les autres applications attendent
 * la leur, qui se pose depuis l'écran superadmin de la vitrine. Un lien déjà
 * saisi n'est jamais écrasé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vitrine_cartes') || !Schema::hasTable('vitrine_sections')) {
            return;
        }

        $section = DB::table('vitrine_sections')->where('cle', 'produits')->value('id');
        if (!$section) {
            return;
        }

        $liens = [
            'Selflow'    => ['https://selflow.dc-knowing.com/', 'Ouvrir Selflow'],
            'Comptaflow' => [(string) config('selflow.comptaflow_app_url', 'https://comptaflow.dc-knowing.com/'), 'Ouvrir Comptaflow'],
        ];

        foreach ($liens as $titre => [$url, $libelle]) {
            DB::table('vitrine_cartes')
                ->where('section_id', $section)
                ->where('titre', $titre)
                ->where(fn ($q) => $q->whereNull('lien_url')->orWhere('lien_url', ''))
                ->update(['lien_url' => $url, 'lien_libelle' => $libelle, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Rien à défaire : un lien posé ne nuit pas, et il a pu être retouché depuis.
    }
};
