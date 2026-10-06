<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La configuration globale des comptes — section 6 du plan.
 *
 * Les comptes se saisissaient sur chaque fiche produit. Ils se saisissent
 * désormais une fois : pour tous les articles, par catégorie, ou par type
 * d'article. Le plus précis l'emporte — type, puis catégorie, puis général —
 * et une exception portée par l'article lui-même passe devant tout.
 *
 * `portee` dit à quoi s'applique la ligne, `cle` l'identifie dans sa portée :
 * vide pour le général, l'identifiant de la catégorie, ou le code du type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('configurations_comptes')) {
            return;
        }

        Schema::create('configurations_comptes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entreprise_id');
            $table->string('portee', 20);
            $table->string('cle', 50)->default('');
            $table->string('compte_vente', 20)->nullable();
            $table->string('compte_achat', 20)->nullable();
            $table->timestamps();

            $table->unique(['entreprise_id', 'portee', 'cle'], 'config_comptes_unique');
            $table->foreign('entreprise_id', 'fk_config_comptes_entreprises')
                ->references('id')->on('entreprises')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurations_comptes');
    }
};
