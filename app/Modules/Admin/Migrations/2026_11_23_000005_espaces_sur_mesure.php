<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selflow sur mesure (23/11/2026) : une entreprise reçoit sa propre adresse,
 * avec sa page d'accueil et sa page de connexion à ses couleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('espaces_sur_mesure')) {
            return;
        }

        Schema::create('espaces_sur_mesure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->unique()->constrained('entreprises')->cascadeOnDelete();
            $table->boolean('actif')->default(false);
            // Les deux formules, au choix du superadministrateur : un
            // sous-domaine de Selflow, un domaine propre, ou les deux.
            $table->string('sous_domaine', 63)->nullable()->unique();
            $table->string('domaine', 190)->nullable()->unique();
            $table->timestamp('domaine_verifie_le')->nullable();
            $table->json('modules')->nullable();
            $table->string('couleur_principale', 7)->default('#1d4ed8');
            $table->string('couleur_secondaire', 7)->default('#0f172a');
            $table->string('logo')->nullable();
            $table->string('logo_blanc')->nullable();
            $table->string('icone')->nullable();
            $table->string('image_fond')->nullable();
            $table->string('modele_accueil', 20)->default('horizon');
            $table->string('modele_connexion', 20)->default('panneau');
            $table->string('titre_accueil')->nullable();
            $table->string('sous_titre_accueil', 500)->nullable();
            $table->text('presentation')->nullable();
            $table->json('atouts')->nullable();
            $table->string('message_connexion')->nullable();
            $table->string('telephone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('adresse')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espaces_sur_mesure');
    }
};
