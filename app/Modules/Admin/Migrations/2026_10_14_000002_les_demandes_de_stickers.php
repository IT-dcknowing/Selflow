<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes de stickers confiées à DC-Knowing — retest d'octobre 2026.
 *
 * L'écran des stickers a été retiré le 25/09/2026 : il laissait croire que
 * Selflow vendait les vignettes. Le propriétaire le fait revenir sous une
 * autre forme : l'entreprise dit ce qu'il lui faut, et **DC-Knowing l'achète
 * pour elle** auprès de la DGI. La demande est gardée ici, envoyée par
 * courriel, et suivie par le superadministrateur jusqu'à la livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demandes_stickers')) {
            return;
        }

        Schema::create('demandes_stickers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $table->foreignId('point_de_vente_id')->nullable()->constrained('points_de_vente')->nullOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->unsignedInteger('quantite');
            $table->decimal('prix_unitaire', 10, 2);
            $table->decimal('montant_estime', 15, 2);
            $table->string('urgence', 20)->default('normale');
            $table->string('contact_telephone', 30)->nullable();
            $table->text('commentaire')->nullable();
            $table->string('statut', 20)->default('recue');
            $table->text('note_dcknowing')->nullable();
            $table->foreignId('traitee_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('traitee_le')->nullable();
            $table->boolean('courriel_envoye')->default(false);
            $table->timestamps();

            $table->index(['entreprise_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_stickers');
    }
};
