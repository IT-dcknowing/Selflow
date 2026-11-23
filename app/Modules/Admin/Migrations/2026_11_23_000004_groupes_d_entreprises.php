<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les groupes d'entreprises (23/11/2026) : une même personne qui dirige
 * plusieurs entreprises passe de l'une à l'autre, une fois le groupe lié et
 * activé par le superadministrateur, et seulement si elle y est autorisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('groupes_entreprises')) {
            Schema::create('groupes_entreprises', function (Blueprint $table) {
                $table->id();
                $table->string('nom');
                $table->boolean('actif')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('groupe_entreprise')) {
            Schema::create('groupe_entreprise', function (Blueprint $table) {
                $table->id();
                $table->foreignId('groupe_id')->constrained('groupes_entreprises')->cascadeOnDelete();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['groupe_id', 'entreprise_id']);
            });
        }

        if (!Schema::hasTable('groupe_utilisateur')) {
            Schema::create('groupe_utilisateur', function (Blueprint $table) {
                $table->id();
                $table->foreignId('groupe_id')->constrained('groupes_entreprises')->cascadeOnDelete();
                $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['groupe_id', 'utilisateur_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_utilisateur');
        Schema::dropIfExists('groupe_entreprise');
        Schema::dropIfExists('groupes_entreprises');
    }
};
