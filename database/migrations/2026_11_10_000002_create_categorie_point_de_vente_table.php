<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('categorie_point_de_vente')) {
            Schema::create('categorie_point_de_vente', function (Blueprint $table) {
                $table->id();
                $table->foreignId('categorie_id')->constrained('categories')->cascadeOnDelete();
                $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['categorie_id', 'point_de_vente_id'], 'cat_pdv_unique');
            });
        }

        // Reprendre les affectations déjà appliquées aux fiches de stock :
        // la première ouverture de cette configuration ne doit pas décocher
        // les sites qui utilisaient déjà ces catégories.
        \Illuminate\Support\Facades\DB::table('categories')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($categorie) {
                $sites = \Illuminate\Support\Facades\DB::table('stocks')
                    ->join('produits', 'produits.id', '=', 'stocks.produit_id')
                    ->where('produits.categorie_id', $categorie->id)
                    ->where('stocks.reference', true)
                    ->distinct()
                    ->pluck('stocks.point_de_vente_id');

                foreach ($sites as $siteId) {
                    \Illuminate\Support\Facades\DB::table('categorie_point_de_vente')->insertOrIgnore([
                        'categorie_id' => $categorie->id,
                        'point_de_vente_id' => $siteId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_point_de_vente');
    }
};
