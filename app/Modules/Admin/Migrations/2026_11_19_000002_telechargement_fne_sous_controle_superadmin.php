<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fne_credentials', function (Blueprint $table) {
            $table->boolean('telechargement_actif')->default(false)->after('acces_fourni_at')
                ->comment('Autorisation explicite du superadministrateur pour le scraping du portail FNE.');
        });
    }

    public function down(): void
    {
        Schema::table('fne_credentials', function (Blueprint $table) {
            $table->dropColumn('telechargement_actif');
        });
    }
};
