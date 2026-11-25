<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le NCC des accès FNE recopié sur la fiche des entreprises qui n'en ont pas
 * (25/11/2026) : saisi par le superadministrateur, il restait vide dans
 * Paramètres. Une fiche qui a déjà un NCC n'est pas touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fne_credentials') || ! Schema::hasColumn('entreprises', 'ncc')) {
            return;
        }

        DB::table('fne_credentials')->whereNotNull('ncc_associe')->where('ncc_associe', '<>', '')
            ->get(['entreprise_id', 'ncc_associe'])
            ->each(function ($acces) {
                DB::table('entreprises')->where('id', $acces->entreprise_id)
                    ->where(fn ($q) => $q->whereNull('ncc')->orWhere('ncc', ''))
                    ->update(['ncc' => strtoupper(preg_replace('/\s+/', '', $acces->ncc_associe))]);
            });
    }

    public function down(): void
    {
        // Rien : un NCC posé reste juste.
    }
};
