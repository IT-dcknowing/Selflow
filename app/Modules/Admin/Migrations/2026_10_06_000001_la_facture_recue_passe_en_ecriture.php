<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La facture reçue de la DGI passe en écriture.
 *
 * Décision du propriétaire, le 06/10/2026, qui renverse la règle du 05/10 :
 * « une facture d'achat reçue de la DGI doit passer en écritures comptables,
 * avec les numéros de compte paramétrés — c'est la priorité, cela concerne
 * les factures fournisseur ». La plupart des factures fournisseur arrivent par
 * le portail ; les achats saisis à la main sont surtout les charges qu'on ne
 * normalise pas.
 *
 * `operation_id` désigne l'opération qui porte cette facture au journal des
 * achats. Elle dit qu'elle est passée — et c'est ce qui empêche de la passer
 * deux fois quand le relevé la redépose chaque heure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            if (!Schema::hasColumn('portail_fne_factures_recues', 'operation_id')) {
                $table->unsignedBigInteger('operation_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('portail_fne_factures_recues', function (Blueprint $table) {
            if (Schema::hasColumn('portail_fne_factures_recues', 'operation_id')) {
                $table->dropIndex(['operation_id']);
                $table->dropColumn('operation_id');
            }
        });
    }
};
