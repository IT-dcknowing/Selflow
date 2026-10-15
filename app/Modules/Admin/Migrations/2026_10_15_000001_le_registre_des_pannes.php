<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des pannes et les réglages de la plateforme — 15/10/2026.
 *
 * Une page qui tombe ne montre plus l'erreur à l'utilisateur : il voit « page
 * en maintenance » et les contacts de l'assistance. L'erreur, elle, est
 * consignée ici pour le superadministrateur — un code et une adresse, le
 * détail au clic — et quitte la liste une fois corrigée.
 *
 * `parametres_plateforme` porte ce que le superadministrateur saisit pour toute
 * la plateforme : d'abord le courriel et le téléphone de l'assistance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pannes')) {
            Schema::create('pannes', function (Blueprint $table) {
                $table->id();
                // Classe, fichier et ligne : deux occurrences du même défaut
                // n'en font qu'une ligne.
                $table->string('empreinte', 64)->unique();
                $table->string('code', 20);
                $table->string('type', 255);
                $table->text('message')->nullable();
                $table->string('fichier', 500)->nullable();
                $table->unsignedInteger('ligne')->nullable();
                $table->text('url')->nullable();
                $table->string('methode', 10)->nullable();
                $table->unsignedBigInteger('utilisateur_id')->nullable();
                $table->unsignedBigInteger('entreprise_id')->nullable();
                $table->longText('trace')->nullable();
                // Le contenu du fichier fautif au moment de la panne : s'il
                // change (un correctif déployé), la panne est tenue pour
                // corrigée ; si elle revient, elle se rouvre.
                $table->string('empreinte_fichier', 64)->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->timestamp('premiere_le')->nullable();
                $table->timestamp('derniere_le')->nullable();
                $table->timestamp('corrigee_le')->nullable();
                $table->string('corrigee_par', 150)->nullable();
                $table->timestamps();

                $table->index('corrigee_le');
            });
        }

        if (! Schema::hasTable('parametres_plateforme')) {
            Schema::create('parametres_plateforme', function (Blueprint $table) {
                $table->id();
                $table->string('cle', 100)->unique();
                $table->text('valeur')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pannes');
        Schema::dropIfExists('parametres_plateforme');
    }
};
