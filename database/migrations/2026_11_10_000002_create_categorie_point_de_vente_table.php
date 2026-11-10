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
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_point_de_vente');
    }
};
