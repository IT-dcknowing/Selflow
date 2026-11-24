<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temps d'affichage par page et par bouton (24/11/2026, Maintenance) :
 * - `visites.route` : le nom de la route, pour regrouper les mesures du
 *   serveur par page ou par action, quels que soient les identifiants ;
 * - `mesures_affichage` : le temps d'affichage mesuré dans le navigateur,
 *   cumulé par jour, route et entreprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visites') && ! Schema::hasColumn('visites', 'route')) {
            Schema::table('visites', function (Blueprint $table) {
                $table->string('route', 150)->nullable()->index();
            });
        }

        if (! Schema::hasTable('mesures_affichage')) {
            Schema::create('mesures_affichage', function (Blueprint $table) {
                $table->id();
                $table->date('jour')->index();
                $table->string('route', 150);
                $table->unsignedBigInteger('entreprise_id')->nullable()->index();
                $table->unsignedInteger('nb')->default(0);
                $table->unsignedBigInteger('total_ms')->default(0);
                $table->unsignedInteger('max_ms')->default(0);
                $table->timestamps();
                $table->unique(['jour', 'route', 'entreprise_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mesures_affichage');
        if (Schema::hasTable('visites') && Schema::hasColumn('visites', 'route')) {
            Schema::table('visites', fn (Blueprint $t) => $t->dropColumn('route'));
        }
    }
};
