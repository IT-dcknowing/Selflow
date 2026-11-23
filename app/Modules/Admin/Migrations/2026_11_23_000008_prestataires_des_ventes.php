<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selflow sur mesure, métiers de service (23/11/2026, salon FOR MEN) : qui a
 * réalisé chaque prestation. Un prestataire n'a pas besoin d'un compte Selflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intervenants')) {
            Schema::create('intervenants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->string('nom', 120);
                $table->string('specialite', 120)->nullable();
                $table->string('telephone', 40)->nullable();
                $table->boolean('actif')->default(true);
                $table->timestamps();
                $table->index(['entreprise_id', 'actif']);
            });
        }

        if (Schema::hasTable('vente_details') && ! Schema::hasColumn('vente_details', 'intervenant_id')) {
            Schema::table('vente_details', function (Blueprint $table) {
                $table->unsignedBigInteger('intervenant_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vente_details') && Schema::hasColumn('vente_details', 'intervenant_id')) {
            Schema::table('vente_details', fn (Blueprint $table) => $table->dropColumn('intervenant_id'));
        }
        Schema::dropIfExists('intervenants');
    }
};
