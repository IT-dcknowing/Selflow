<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les soldes de départ des tiers (retour client du 19/10/2026).
 *
 * Une entreprise qui arrive sur Selflow a déjà des clients qui lui doivent et
 * des fournisseurs à qui elle doit. Ces sommes n'ont pas de facture dans
 * l'application : elles se saisissent une fois, par tiers, et se soldent
 * ensuite par des règlements comme n'importe quelle pièce — la trésorerie les
 * rattache par la référence du solde.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('soldes_initiaux')) {
            return;
        }

        Schema::create('soldes_initiaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->string('type', 20);               // client | fournisseur
            $table->unsignedBigInteger('tiers_id');   // clients.id ou fournisseurs.id
            $table->decimal('montant', 15, 2);
            $table->date('date_solde');
            $table->string('reference', 40);          // SD-C-000012 / SD-F-000007
            $table->string('libelle')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'type', 'tiers_id']);
            $table->unique(['entreprise_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soldes_initiaux');
    }
};
