<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La liste du portail d'où vient une pièce relevée : `received` (reçue d'un
 * fournisseur) ou `issued` (émise par l'entreprise).
 *
 * Le relevé des avoirs range aussi les avoirs que l'entreprise émet à ses
 * clients, dans la même table que les factures reçues de ses fournisseurs.
 * Sans cette colonne, un avoir émis passait pour un avoir fournisseur : il
 * partait au journal des achats, son client pris pour fournisseur. Nul pour
 * les relevés d'avant : ils ne venaient que de la liste des pièces reçues.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portail_fne_factures_recues') && !Schema::hasColumn('portail_fne_factures_recues', 'liste_portail')) {
            Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
                $table->string('liste_portail', 10)->nullable()->after('subtype');
                $table->index(['entreprise_id', 'liste_portail']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portail_fne_factures_recues', 'liste_portail')) {
            Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
                $table->dropIndex(['entreprise_id', 'liste_portail']);
                $table->dropColumn('liste_portail');
            });
        }
    }
};
