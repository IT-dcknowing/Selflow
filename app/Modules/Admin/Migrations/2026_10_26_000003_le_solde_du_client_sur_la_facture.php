<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le solde du client imprimé sur ses factures (26/10/2026) : une option de
 * l'entreprise, éteinte par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('entreprises', 'afficher_solde_client_facture')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->boolean('afficher_solde_client_facture')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('entreprises', 'afficher_solde_client_facture')) {
            Schema::table('entreprises', function (Blueprint $table) {
                $table->dropColumn('afficher_solde_client_facture');
            });
        }
    }
};
