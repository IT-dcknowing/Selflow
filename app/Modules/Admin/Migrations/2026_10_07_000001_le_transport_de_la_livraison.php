<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le transport d'une livraison (chantier 15.3 du plan).
 *
 * Le bon ne portait que les quantités : ni où l'on livrait, ni qui livrait, ni
 * quand. Tranché par le propriétaire le 07/10/2026 : **tout est obligatoire,
 * et le bon imprimé le porte.**
 *
 * Deux temps, parce que deux de ces informations n'existent qu'après coup :
 *
 * | Au départ (création du bon) | À l'arrivée (« marquer livré ») |
 * |---|---|
 * | adresse, livreur, véhicule, heure de départ | heure d'arrivée, réceptionnaire, sa signature, observations |
 *
 * Toutes nullables en base : les bons déjà établis n'ont rien de tout cela,
 * et l'obligation se pose au formulaire, pas sur l'historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_livraison', function (Blueprint $table) {
            if (!Schema::hasColumn('bons_livraison', 'adresse_livraison')) {
                $table->string('adresse_livraison', 255)->nullable()->after('notes');
                // `personnel` (un membre de l'entreprise) ou `prestataire`.
                $table->string('livreur_type', 20)->nullable()->after('adresse_livraison');
                $table->foreignId('livreur_utilisateur_id')->nullable()->after('livreur_type')
                    ->constrained('utilisateurs')->nullOnDelete();
                $table->string('livreur_nom', 150)->nullable()->after('livreur_utilisateur_id');
                $table->string('vehicule', 60)->nullable()->after('livreur_nom');
                $table->dateTime('heure_depart')->nullable()->after('vehicule');
                $table->dateTime('heure_arrivee')->nullable()->after('heure_depart');
                $table->string('receptionnaire_nom', 150)->nullable()->after('heure_arrivee');
                // L'image de la signature tracée à l'écran, en `data:image/png`.
                $table->longText('receptionnaire_signature')->nullable()->after('receptionnaire_nom');
                $table->text('observations')->nullable()->after('receptionnaire_signature');
            }
        });

        // Les quantités d'un bon étaient des entiers : la file « Livraisons »
        // du stock, qui crée désormais son bon, livre au gramme près, et
        // 12,5 kg y seraient devenus 12. Même précision que `stocks`.
        Schema::table('bon_livraison_details', function (Blueprint $table) {
            $table->decimal('qte_commandee', 15, 3)->change();
            $table->decimal('qte_livree', 15, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('bon_livraison_details', function (Blueprint $table) {
            $table->integer('qte_commandee')->change();
            $table->integer('qte_livree')->change();
        });

        Schema::table('bons_livraison', function (Blueprint $table) {
            if (Schema::hasColumn('bons_livraison', 'adresse_livraison')) {
                $table->dropConstrainedForeignId('livreur_utilisateur_id');
                $table->dropColumn([
                    'adresse_livraison', 'livreur_type', 'livreur_nom', 'vehicule', 'heure_depart',
                    'heure_arrivee', 'receptionnaire_nom', 'receptionnaire_signature', 'observations',
                ]);
            }
        });
    }
};
