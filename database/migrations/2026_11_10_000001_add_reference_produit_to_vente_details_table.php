<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vente_details', 'reference_produit')) {
            Schema::table('vente_details', function (Blueprint $table) {
                $table->string('reference_produit', 100)->nullable()->after('produit_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vente_details', 'reference_produit')) {
            Schema::table('vente_details', function (Blueprint $table) {
                $table->dropColumn('reference_produit');
            });
        }
    }
};
