<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('entreprises')) {
            return;
        }

        DB::table('entreprises')
            ->where('comptabilite_stock_active', false)
            ->where('comptabilite_stock_quotidienne', false)
            ->where('comptabilite_stock_periode_mois', 1)
            ->whereNull('comptabilite_stock_date_debut')
            ->update([
                'comptabilite_stock_active' => true,
                'comptabilite_stock_quotidienne' => false,
                'comptabilite_stock_periode_mois' => 3,
                'comptabilite_stock_date_debut' => now()->toDateString(),
            ]);
    }

    public function down(): void
    {
        // Migration de données : ne pas écraser les choix enregistrés ensuite.
    }
};
