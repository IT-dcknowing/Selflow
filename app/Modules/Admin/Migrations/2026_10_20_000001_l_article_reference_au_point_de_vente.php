<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque point de vente voit ses articles (retour du 20/10/2026).
 *
 * Le catalogue est celui de l'entreprise, mais une fiche de stock s'ouvrait à
 * zéro sur **tous** les sites à la création d'un article : chaque magasin
 * voyait donc en caisse et en stock ce que les autres vendaient. La fiche
 * dit désormais si l'article est **référencé** sur ce site.
 *
 * Reprise de l'existant, sans rien cacher de ce qui a servi : une fiche reste
 * référencée si elle porte une quantité ou si un mouvement y est passé. Un
 * article qui n'a encore servi nulle part reste référencé partout — on ne sait
 * pas à quel site il était destiné.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stocks', 'reference')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->boolean('reference')->default(true)->after('stock_maximum');
                $table->index(['point_de_vente_id', 'reference']);
            });
        }

        DB::statement("
            UPDATE stocks SET reference = 0
            WHERE quantite_disponible = 0
              AND NOT EXISTS (
                  SELECT 1 FROM mouvements_stock m
                  WHERE m.produit_id = stocks.produit_id AND m.point_de_vente_id = stocks.point_de_vente_id
              )
              AND (
                  -- Tables dérivées : MySQL refuse de relire `stocks` dans la
                  -- même requête qui le met à jour (erreur 1093).
                  produit_id IN (SELECT t.produit_id FROM (SELECT DISTINCT produit_id FROM stocks WHERE quantite_disponible <> 0) AS t)
                  OR produit_id IN (SELECT DISTINCT produit_id FROM mouvements_stock)
              )
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('stocks', 'reference')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->dropIndex(['point_de_vente_id', 'reference']);
                $table->dropColumn('reference');
            });
        }
    }
};
