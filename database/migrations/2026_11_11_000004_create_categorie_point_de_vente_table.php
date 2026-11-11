<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les catégories peuvent être limitées à un ou plusieurs points de vente.
     * Cette table pivot est utilisée par Categorie::pointsDeVente() et par
     * Produit::scopeVisiblesAuSite(). Elle doit donc exister même sur les
     * bases installées avant l'activation de cette fonctionnalité.
     */
    public function up(): void
    {
        if (Schema::hasTable('categorie_point_de_vente')) {
            return;
        }

        if (!Schema::hasTable('categories') || !Schema::hasTable('points_de_vente')) {
            throw new RuntimeException(
                'Impossible de créer categorie_point_de_vente : les tables categories et points_de_vente doivent exister.'
            );
        }

        Schema::create('categorie_point_de_vente', function (Blueprint $table) {
            $table->foreignId('categorie_id')
                ->constrained('categories')
                ->cascadeOnDelete();

            $table->foreignId('point_de_vente_id')
                ->constrained('points_de_vente')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->primary(['categorie_id', 'point_de_vente_id'], 'cat_pdv_primary');
            $table->index('point_de_vente_id', 'cat_pdv_point_de_vente_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_point_de_vente');
    }
};
