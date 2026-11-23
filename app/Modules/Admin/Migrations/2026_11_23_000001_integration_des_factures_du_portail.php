<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures relevées sur le portail FNE deviennent des ventes (23/11/2026).
 *
 * - `propositions_stock` : les entrées et sorties que ces ventes appellent,
 *   soumises à la validation de l'utilisateur au lieu d'être passées d'office.
 * - `vente_details.compte_vente` : le compte choisi pour une ligne hors
 *   catalogue, qu'aucun article ne porte.
 *
 * Une migration décrit un état : chaque ajout vérifie d'abord qu'il manque.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('propositions_stock')) {
            Schema::create('propositions_stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->unsignedBigInteger('point_de_vente_id')->nullable()->index();
                $table->unsignedBigInteger('produit_id')->index();
                $table->unsignedBigInteger('vente_id')->nullable()->index();
                $table->unsignedBigInteger('vente_detail_id')->nullable();
                $table->string('sens', 10); // sortie | entree
                $table->decimal('quantite', 15, 3);
                $table->string('origine', 40)->default('portail_fne');
                $table->string('reference', 80)->nullable();
                $table->string('statut', 12)->default('proposee'); // proposee | validee | refusee
                $table->string('motif')->nullable();
                $table->unsignedBigInteger('traite_par')->nullable();
                $table->timestamp('traite_le')->nullable();
                $table->timestamps();
                $table->index(['entreprise_id', 'statut']);
            });
        }

        if (Schema::hasTable('vente_details') && !Schema::hasColumn('vente_details', 'compte_vente')) {
            Schema::table('vente_details', function (Blueprint $table) {
                $table->string('compte_vente', 20)->nullable()->after('montant_ttc');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vente_details', 'compte_vente')) {
            Schema::table('vente_details', fn (Blueprint $table) => $table->dropColumn('compte_vente'));
        }
        Schema::dropIfExists('propositions_stock');
    }
};
