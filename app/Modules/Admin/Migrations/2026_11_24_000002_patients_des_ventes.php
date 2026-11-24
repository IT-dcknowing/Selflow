<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prestataires externes et patients (24/11/2026, laboratoire d'analyses) :
 * une facture porte un payeur (le prestataire externe, ou le patient venu
 * seul) et un ou plusieurs patients ; chaque ligne peut être affectée à un
 * patient. Les factures « apporteur » existantes sont reprises : l'apporteur
 * reste le payeur, le bénéficiaire devient le patient.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vente_patients')) {
            Schema::create('vente_patients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('vente_id')->index();
                $table->unsignedBigInteger('client_id')->index();
                $table->timestamps();
                $table->unique(['vente_id', 'client_id']);
            });
        }

        if (Schema::hasTable('vente_details') && ! Schema::hasColumn('vente_details', 'patient_id')) {
            Schema::table('vente_details', function (Blueprint $table) {
                $table->unsignedBigInteger('patient_id')->nullable()->index();
            });
        }

        // Reprise des factures déjà faites avec un apporteur.
        if (Schema::hasColumn('ventes', 'apporteur_id') && Schema::hasColumn('ventes', 'beneficiaire_id')) {
            DB::table('ventes')->whereNotNull('apporteur_id')->whereNotNull('beneficiaire_id')
                ->orderBy('id')->select(['id', 'beneficiaire_id'])
                ->chunk(500, function ($ventes) {
                    $lignes = $ventes->map(fn ($v) => ['vente_id' => $v->id, 'client_id' => $v->beneficiaire_id, 'created_at' => now(), 'updated_at' => now()])->all();
                    DB::table('vente_patients')->insertOrIgnore($lignes);
                    foreach ($ventes as $v) {
                        DB::table('vente_details')->where('vente_id', $v->id)->whereNull('patient_id')->update(['patient_id' => $v->beneficiaire_id]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vente_details') && Schema::hasColumn('vente_details', 'patient_id')) {
            Schema::table('vente_details', fn (Blueprint $table) => $table->dropColumn('patient_id'));
        }
        Schema::dropIfExists('vente_patients');
    }
};
