<?php

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le volet Stock ouvert aux entreprises qui tiennent déjà des articles
 * stockables sans avoir le module (20/10/2026) — « poissonnerie LA GRACE » :
 * des alertes de stock au tableau de bord, et aucun volet Stock au menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('produits')->whereIn('type', Produit::TYPES_STOCKABLES)->distinct()->pluck('entreprise_id');

        Entreprise::withoutGlobalScopes()->whereIn('id', $ids)->get()
            ->each(fn (Entreprise $e) => $e->ouvrirLeStockSiBesoin());
    }

    public function down(): void
    {
        // Rien à défaire : ouvrir un module ne retire rien.
    }
};
