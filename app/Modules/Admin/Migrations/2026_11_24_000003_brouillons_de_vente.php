<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les brouillons de vente (24/11/2026) : une vente préparée, qui n'est encore
 * ni une facture, ni un mouvement de stock, ni une écriture, ni une pièce
 * envoyée à la DGI. « Passer en facture » la rouvre dans l'écran de vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brouillons_vente')) {
            Schema::create('brouillons_vente', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entreprise_id')->index();
                $table->unsignedBigInteger('point_de_vente_id')->nullable()->index();
                $table->unsignedBigInteger('utilisateur_id')->nullable();
                $table->string('libelle')->nullable();
                $table->decimal('total', 15, 2)->default(0);
                $table->unsignedInteger('lignes')->default(0);
                $table->json('donnees');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brouillons_vente');
    }
};
