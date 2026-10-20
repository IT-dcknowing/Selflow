<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le code des points de vente en chiffres (20/10/2026) : 01, 02, 03…, dans
 * l'ordre de création, entreprise par entreprise. Les numéros déjà émis
 * (VTE-BON-…) ne changent pas ; les suivants portent le nouveau code et
 * repartent à 001 — leur préfixe diffère, ils ne se croisent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('points_de_vente')->orderBy('entreprise_id')->orderBy('id')->get(['id', 'entreprise_id'])
            ->groupBy('entreprise_id')
            ->each(function ($sites) {
                foreach ($sites->values() as $rang => $site) {
                    DB::table('points_de_vente')->where('id', $site->id)
                        ->update(['code_piece' => str_pad((string) ($rang + 1), 2, '0', STR_PAD_LEFT)]);
                }
            });
    }

    public function down(): void
    {
        // Les anciens codes en lettres ne se reconstituent pas : rien à défaire.
    }
};
