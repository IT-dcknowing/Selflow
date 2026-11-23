<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selflow sur mesure, suite (23/11/2026) : la couleur du menu et de son
 * élément actif réglables à part, l'envoi de la facture au client par e-mail,
 * et les préférences du client (métiers de la beauté : style, cheveux…).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('espaces_sur_mesure')) {
            Schema::table('espaces_sur_mesure', function (Blueprint $table) {
                if (! Schema::hasColumn('espaces_sur_mesure', 'couleur_menu')) {
                    $table->string('couleur_menu', 7)->nullable();
                }
                if (! Schema::hasColumn('espaces_sur_mesure', 'couleur_menu_accent')) {
                    $table->string('couleur_menu_accent', 7)->nullable();
                }
                if (! Schema::hasColumn('espaces_sur_mesure', 'facture_email_moment')) {
                    $table->string('facture_email_moment', 20)->default('emission');
                }
            });
        }

        // La date d'envoi de la facture au client : rien ne part deux fois sans le vouloir.
        if (Schema::hasTable('ventes') && ! Schema::hasColumn('ventes', 'envoyee_au_client_le')) {
            Schema::table('ventes', function (Blueprint $table) {
                $table->timestamp('envoyee_au_client_le')->nullable();
            });
        }

        if (Schema::hasTable('clients') && ! Schema::hasColumn('clients', 'preferences')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->json('preferences')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('espaces_sur_mesure')) {
            Schema::table('espaces_sur_mesure', function (Blueprint $table) {
                foreach (['couleur_menu', 'couleur_menu_accent', 'facture_email_moment'] as $c) {
                    if (Schema::hasColumn('espaces_sur_mesure', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
        if (Schema::hasTable('ventes') && Schema::hasColumn('ventes', 'envoyee_au_client_le')) {
            Schema::table('ventes', fn (Blueprint $table) => $table->dropColumn('envoyee_au_client_le'));
        }
        if (Schema::hasTable('clients') && Schema::hasColumn('clients', 'preferences')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('preferences'));
        }
    }
};
