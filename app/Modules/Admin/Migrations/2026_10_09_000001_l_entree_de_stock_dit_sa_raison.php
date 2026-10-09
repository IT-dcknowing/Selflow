<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'entrée de stock sans achat saisi : sa raison et son commentaire.
 *
 * Réapprovisionner sans facture d'achat n'avait que deux portes, et toutes deux
 * mentaient : l'inventaire physique (un « écart » qui n'en est pas un, entré au
 * coût moyen du moment et non au coût réel) ou un faux achat. L'entrée de stock
 * dit pourquoi la marchandise arrive — achat sans facture, retour, don — et à
 * quel coût. Nuls pour tous les autres mouvements, qui disent déjà leur raison
 * par leur pièce.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('mouvements_stock', 'raison_entree')) {
            Schema::table('mouvements_stock', function (Blueprint $table) {
                $table->string('raison_entree', 30)->nullable()->after('sous_type');
                $table->string('commentaire', 255)->nullable()->after('reference_document');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mouvements_stock', 'raison_entree')) {
            Schema::table('mouvements_stock', function (Blueprint $table) {
                $table->dropColumn(['raison_entree', 'commentaire']);
            });
        }
    }
};
