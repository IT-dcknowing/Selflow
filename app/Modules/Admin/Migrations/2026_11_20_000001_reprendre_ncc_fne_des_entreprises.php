<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fne_credentials')
            || !Schema::hasColumn('entreprises', 'possede_compte_fne')
            || !Schema::hasColumn('entreprises', 'ncc')) {
            return;
        }

        // Les anciennes fiches avaient parfois le NCC fiscal dans entreprises.ncc
        // sans le recopier dans fne_credentials.ncc_associe. On ne reprend cette
        // valeur que si l'entreprise a explicitement déclaré posséder un compte FNE.
        DB::table('entreprises')
            ->where('possede_compte_fne', true)
            ->whereNotNull('ncc')
            ->where('ncc', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($entreprises): void {
                foreach ($entreprises as $entreprise) {
                    DB::table('fne_credentials')
                        ->where('entreprise_id', $entreprise->id)
                        ->where(function ($query): void {
                            $query->whereNull('ncc_associe')->orWhere('ncc_associe', '');
                        })
                        ->update(['ncc_associe' => strtoupper(preg_replace('/\s+/', '', $entreprise->ncc))]);
                }
            });
    }

    public function down(): void
    {
        // Migration de reprise de données : ne pas effacer les NCC réconciliés,
        // qui peuvent avoir été confirmés ou corrigés depuis le déploiement.
    }
};
