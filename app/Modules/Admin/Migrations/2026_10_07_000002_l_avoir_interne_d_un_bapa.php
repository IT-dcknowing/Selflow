<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'avoir interne d'un BAPA (chantier 8.4 du plan).
 *
 * Le propriétaire l'a confirmé le 07/10/2026 : la DGI ne normalise toujours
 * pas l'avoir d'un bordereau d'achat. L'avoir de BAPA est donc un document
 * **interne, non certifié**, qui ne vaut que pour la comptabilité et le stock.
 *
 * ## Pourquoi deux tables à part, et non une ligne `achats`
 *
 * Un avoir rangé dans `achats` sur un vendeur sans NCC serait reconnu comme
 * BAPA par `Achat::estBapa()` — et quatre chemins envoient les BAPA à la DGI :
 * la normalisation manuelle, le tableau FNE, la normalisation par lot, les
 * corrections. Les garder tous fermés supposerait de toucher au périmètre
 * gelé ou de semer quatre exceptions. Ici, rien de ce qui parle à la
 * plateforme ne lit ces tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs_bapa', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('achat_id')->constrained('achats')->restrictOnDelete();
            $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->restrictOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->string('numero', 40);
            $table->date('date_avoir');
            $table->string('motif', 255);
            $table->decimal('montant_ttc', 15, 2);
            $table->timestamps();

            $table->unique(['entreprise_id', 'numero']);
        });

        Schema::create('avoir_bapa_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avoir_bapa_id')->constrained('avoirs_bapa')->cascadeOnDelete();
            $table->foreignId('achat_detail_id')->constrained('achat_details')->restrictOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('libelle');
            $table->decimal('quantite', 15, 3);
            $table->decimal('prix_unitaire', 15, 2);
            $table->decimal('montant', 15, 2);
            $table->boolean('retour_stock')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoir_bapa_lignes');
        Schema::dropIfExists('avoirs_bapa');
    }
};
