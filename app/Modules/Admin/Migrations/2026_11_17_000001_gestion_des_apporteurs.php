<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('est_apporteur')->default(false)->after('numero_original');
        });

        Schema::table('ventes', function (Blueprint $table) {
            $table->unsignedBigInteger('apporteur_id')->nullable()->after('client_id')->index();
            $table->unsignedBigInteger('beneficiaire_id')->nullable()->after('apporteur_id')->index();
            $table->foreign('apporteur_id', 'fk_ventes_apporteur')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('beneficiaire_id', 'fk_ventes_beneficiaire')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign('fk_ventes_apporteur');
            $table->dropForeign('fk_ventes_beneficiaire');
            $table->dropColumn(['apporteur_id', 'beneficiaire_id']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('est_apporteur');
        });
    }
};
