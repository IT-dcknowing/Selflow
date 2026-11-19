<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('comptaflow_comptes_propagations')) {
            Schema::create('comptaflow_comptes_propagations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entreprise_id');
                $table->string('ancien_numero', 30);
                $table->string('nouveau_numero', 30);
                $table->string('statut', 20)->default('en_attente');
                $table->unsignedSmallInteger('tentatives')->default(0);
                $table->text('dernier_message')->nullable();
                $table->json('resultat')->nullable();
                $table->timestamps();
                $table->unique(
                    ['entreprise_id', 'ancien_numero', 'nouveau_numero'],
                    'comptaflow_compte_propagation_unique'
                );
                $table->index(['entreprise_id', 'statut'], 'comptaflow_compte_propagation_statut_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comptaflow_comptes_propagations');
    }
};
