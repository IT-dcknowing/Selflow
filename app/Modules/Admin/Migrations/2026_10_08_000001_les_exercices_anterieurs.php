<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La comptabilité des exercices antérieurs (propriétaire, 08/10/2026).
 *
 * Une entreprise qui facturait déjà dans Selflow et veut sa comptabilité des
 * années passées la **demande**, exercice par exercice. Le superadministrateur
 * valide la demande et choisit le ou les exercices qu'il accorde. Seuls les
 * exercices accordés partent chez Comptaflow ; l'exercice en cours suit son
 * cours sans rien demander.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demandes_exercices_anterieurs')) {
            return;
        }

        Schema::create('demandes_exercices_anterieurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            // Les années demandées, puis celles que le superadministrateur accorde.
            $table->json('annees_demandees');
            $table->json('annees_accordees')->nullable();
            // en_attente, validee, refusee
            $table->string('statut', 20)->default('en_attente');
            $table->foreignId('demandee_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->foreignId('traitee_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('traitee_at')->nullable();
            $table->text('motif_refus')->nullable();
            $table->timestamps();

            $table->index(['entreprise_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_exercices_anterieurs');
    }
};
