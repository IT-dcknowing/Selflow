<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'ordre de production fige sa recette, et garde son coût (recette du
 * 08/10/2026).
 *
 * **Les lignes de l'ordre.** L'ordre relisait la fiche technique au moment de
 * sa validation : modifier la recette entre la création et la validation
 * changeait ce qu'un ordre déjà lancé allait consommer, sans que rien ne le
 * dise. Chaque ordre recopie désormais, à sa création, ce que la recette
 * demandait — la quantité et l'unité de la recette, et le besoin total ramené
 * à l'unité de stock de l'ingrédient.
 *
 * **Le coût.** Ce que la fabrication a coûté n'était écrit nulle part ailleurs
 * que dans les mouvements de stock : ni la liste ni le bon de fabrication ne
 * pouvaient le montrer.
 *
 * Purement additive : les ordres déjà établis n'ont pas de lignes, et se
 * valident sur la recette en place, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ordre_production_lignes')) {
            Schema::create('ordre_production_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ordre_production_id')->constrained('ordres_production')->cascadeOnDelete();
                $table->foreignId('ingredient_id')->constrained('produits')->cascadeOnDelete();
                // Ce que la recette demandait pour une unité de produit fini.
                $table->decimal('quantite_recette', 15, 4);
                $table->string('unite_recette', 50);
                // Le besoin total de l'ordre, dans l'unité de stock de
                // l'ingrédient, à la précision du stock.
                $table->decimal('quantite_requise', 15, 3);
                $table->timestamps();

                $table->unique(['ordre_production_id', 'ingredient_id'], 'unique_ordre_ingredient');
            });
        }

        Schema::table('ordres_production', function (Blueprint $table) {
            if (!Schema::hasColumn('ordres_production', 'cout_total')) {
                $table->decimal('cout_total', 15, 2)->nullable()->after('date_production');
                $table->decimal('cout_unitaire', 15, 4)->nullable()->after('cout_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordres_production', function (Blueprint $table) {
            if (Schema::hasColumn('ordres_production', 'cout_total')) {
                $table->dropColumn(['cout_total', 'cout_unitaire']);
            }
        });

        Schema::dropIfExists('ordre_production_lignes');
    }
};
