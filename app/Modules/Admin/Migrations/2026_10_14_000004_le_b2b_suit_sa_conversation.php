<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le B2B approfondi (lot 75) : qui a parlé en dernier, et qui l'a lu.
 *
 * La négociation gardait ses messages dans `historique_discussions`, sans
 * savoir lesquels l'autre partie n'avait pas encore vus : aucun compteur ne
 * pouvait dire « une réponse vous attend ». Quatre colonnes suffisent :
 * l'heure et l'auteur (client ou fournisseur) du dernier événement, et
 * l'heure à laquelle chaque partie a ouvert la négociation pour la dernière
 * fois. Les pièces nées de la négociation — le bon de commande du client et
 * celui du fournisseur — portent déjà `b2b_negotiation_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('b2b_negotiations', 'derniere_activite_le')) {
            Schema::table('b2b_negotiations', function (Blueprint $table) {
                $table->timestamp('derniere_activite_le')->nullable();
                $table->string('derniere_activite_par', 12)->nullable();
                $table->timestamp('lu_client_le')->nullable();
                $table->timestamp('lu_fournisseur_le')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('b2b_negotiations', 'derniere_activite_le')) {
            Schema::table('b2b_negotiations', function (Blueprint $table) {
                $table->dropColumn(['derniere_activite_le', 'derniere_activite_par', 'lu_client_le', 'lu_fournisseur_le']);
            });
        }
    }
};
