<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes de démonstration déposées depuis la page d'accueil (23/11/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('demandes_demo')) {
            Schema::create('demandes_demo', function (Blueprint $table) {
                $table->id();
                $table->string('email');
                $table->string('whatsapp', 40);
                $table->json('profils')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('statut', 12)->default('nouvelle'); // nouvelle | transmise
                $table->unsignedInteger('envois')->default(0);
                $table->timestamp('transmise_le')->nullable();
                $table->unsignedBigInteger('transmise_par')->nullable();
                $table->timestamps();
                $table->index(['email', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_demo');
    }
};
