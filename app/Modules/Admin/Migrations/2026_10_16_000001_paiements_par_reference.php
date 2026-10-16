<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le paiement par référence, en attendant l'API de paiement — 16/10/2026.
 *
 * L'entreprise dépose le montant sur le numéro du cabinet (Wave, Orange,
 * MTN, Moov) et saisit la référence de la transaction ; le superadministrateur
 * la vérifie et fait avancer la demande. Chaque étape garde son heure, pour
 * la traçabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes_stickers', function (Blueprint $table) {
            foreach ([
                'operateur_paiement'   => fn () => $table->string('operateur_paiement', 30)->nullable(),
                'reference_paiement'   => fn () => $table->string('reference_paiement', 100)->nullable(),
                'motif_echec'          => fn () => $table->string('motif_echec', 255)->nullable(),
                'en_cours_le'          => fn () => $table->timestamp('en_cours_le')->nullable(),
                'achetee_le'           => fn () => $table->timestamp('achetee_le')->nullable(),
                'echec_le'             => fn () => $table->timestamp('echec_le')->nullable(),
                'annulee_le'           => fn () => $table->timestamp('annulee_le')->nullable(),
                'stock_communique'     => fn () => $table->unsignedInteger('stock_communique')->nullable(),
                'solde_communique'     => fn () => $table->decimal('solde_communique', 15, 2)->nullable(),
                'notification_vue_le'  => fn () => $table->timestamp('notification_vue_le')->nullable(),
            ] as $colonne => $ajouter) {
                if (! Schema::hasColumn('demandes_stickers', $colonne)) {
                    $ajouter();
                }
            }
        });

        // « Livrée sur l'espace FNE » est retiré : la demande est terminée
        // une fois achetée.
        DB::table('demandes_stickers')->where('statut', 'livree')->update(['statut' => 'achetee']);

        if (! Schema::hasTable('paiements_abonnements')) {
            Schema::create('paiements_abonnements', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
                $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
                $table->string('offre', 30);
                $table->string('nature', 20)->default('souscription'); // souscription | renouvellement
                $table->unsignedInteger('mois')->default(1);
                $table->decimal('montant', 15, 2);
                $table->string('operateur_paiement', 30);
                $table->string('reference_paiement', 100);
                $table->string('statut', 20)->default('recu'); // recu | en_cours | active | echec
                $table->string('motif_echec', 255)->nullable();
                $table->timestamp('en_cours_le')->nullable();
                $table->timestamp('active_le')->nullable();
                $table->timestamp('echec_le')->nullable();
                $table->foreignId('traite_par')->nullable()->constrained('utilisateurs')->nullOnDelete();
                $table->timestamps();

                $table->index(['statut', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements_abonnements');
        Schema::table('demandes_stickers', function (Blueprint $table) {
            foreach (['operateur_paiement', 'reference_paiement', 'motif_echec', 'en_cours_le', 'achetee_le', 'echec_le', 'annulee_le', 'stock_communique', 'solde_communique', 'notification_vue_le'] as $colonne) {
                if (Schema::hasColumn('demandes_stickers', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
