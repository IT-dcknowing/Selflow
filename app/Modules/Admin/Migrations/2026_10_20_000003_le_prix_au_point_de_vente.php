<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le prix de vente propre à un point de vente (20/10/2026).
 *
 * « Quand je modifie les prix à Bondoukou, ça modifie Bongouanou aussi. » Le
 * prix vivait sur l'article, commun à l'entreprise. La fiche du site peut
 * maintenant porter le sien ; vide, c'est le prix de l'article qui vaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stocks', 'prix_vente')) {
            Schema::table('stocks', function (Blueprint $table) {
                $table->decimal('prix_vente', 15, 2)->nullable()->after('reference');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stocks', 'prix_vente')) {
            Schema::table('stocks', fn (Blueprint $table) => $table->dropColumn('prix_vente'));
        }
    }
};
