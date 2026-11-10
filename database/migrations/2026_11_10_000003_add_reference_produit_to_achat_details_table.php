<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('achat_details', 'reference_produit')) {
            Schema::table('achat_details', function (Blueprint $table) {
                $table->string('reference_produit', 100)->nullable()->after('produit_id');
            });
        }

        \Illuminate\Support\Facades\DB::table('achat_details')
            ->whereNotNull('produit_id')
            ->whereNull('reference_produit')
            ->orderBy('id')
            ->chunkById(500, function ($lignes) {
                $references = \Illuminate\Support\Facades\DB::table('produits')
                    ->whereIn('id', $lignes->pluck('produit_id')->unique())
                    ->pluck('reference', 'id');

                foreach ($lignes as $ligne) {
                    if (isset($references[$ligne->produit_id])) {
                        \Illuminate\Support\Facades\DB::table('achat_details')
                            ->where('id', $ligne->id)
                            ->update(['reference_produit' => $references[$ligne->produit_id]]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('achat_details', 'reference_produit')) {
            Schema::table('achat_details', function (Blueprint $table) {
                $table->dropColumn('reference_produit');
            });
        }
    }
};
