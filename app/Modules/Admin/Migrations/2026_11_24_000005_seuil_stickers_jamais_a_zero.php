<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un seuil d'alerte des stickers à 0 (repris du portail FNE) n'alerte jamais :
 * il passe à 5, la valeur par défaut (24/11/2026, propriétaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('entreprises', 'sticker_solde_alerte')) {
            DB::table('entreprises')->where('sticker_solde_alerte', '<=', 0)->update(['sticker_solde_alerte' => 5]);
        }
    }

    public function down(): void
    {
        // Rien à défaire : 0 n'était pas un réglage voulu.
    }
};
