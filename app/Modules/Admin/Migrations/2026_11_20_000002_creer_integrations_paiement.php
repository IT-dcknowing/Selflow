<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations_paiement', function (Blueprint $table): void {
            $table->id();
            $table->string('fournisseur', 40)->unique();
            $table->string('environnement', 20)->default('sandbox');
            $table->string('api_url', 500)->nullable();
            $table->string('merchant_id', 255)->nullable();
            $table->string('client_id', 255)->nullable();
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->longText('configuration')->nullable();
            $table->boolean('actif')->default(false)->index();
            $table->unsignedBigInteger('modifie_par')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations_paiement');
    }
};
