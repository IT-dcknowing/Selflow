<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les deux tables qui manquaient entre un rejet de la DGI et le relevé du
 * portail.
 *
 * Jusqu'ici, une pièce refusée par la plateforme laissait une ligne dans
 * `storage/logs/laravel.log` et rien d'autre : le champ fautif, la valeur
 * envoyée et la raison de la DGI étaient construits par
 * `FneService::messageRejet()`, affichés une fois, puis perdus. On ne
 * diagnostique pas ce qu'on n'a pas gardé.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fne_rejets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();

            // 'vente' ou 'achat'. Deux chaînes plutôt qu'un `morphs` : la table
            // se lit à l'oeil en production, où l'on cherche un rejet sans
            // avoir le code sous les yeux.
            $table->string('piece_type', 20);
            $table->unsignedBigInteger('piece_id');

            // Recopié plutôt que joint : une pièce peut être supprimée, le
            // rejet reste, et « FA-0042 » vaut mieux que « pièce 731 disparue ».
            $table->string('numero_piece', 60)->nullable();

            // Le NCC de l'entreprise au moment du rejet — c'est la clé qui
            // relie un rejet aux relevés du portail (`portail_fne_*.login`).
            $table->string('login', 40)->nullable()->index();

            // Le message complet rendu par la plateforme, tel que
            // `FneService::messageRejet()` l'assemble.
            $table->text('message')->nullable();

            // Les champs que la DGI a nommés, et la valeur qu'on leur avait
            // donnée : `{"pointOfSale": "FACTURATION SIEGE"}`. C'est sur cette
            // liste que le rapprochement travaille.
            $table->json('champs')->nullable();

            // 'ouvert'    : constaté, pas encore rapproché d'un relevé ;
            // 'diagnostique' : un relevé postérieur a permis la comparaison ;
            // 'resolu'    : la pièce est passée depuis, ou quelqu'un a tranché.
            $table->string('statut', 20)->default('ouvert')->index();

            // Le résultat du rapprochement, tel qu'il a été montré. Conservé
            // parce qu'un diagnostic se relit : le relevé du jour ne dira plus
            // la même chose dans six mois.
            $table->json('diagnostic')->nullable();

            $table->timestamps();

            $table->index(['piece_type', 'piece_id']);
            $table->index(['entreprise_id', 'statut']);
        });

        Schema::create('portail_fne_demandes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('entreprise_id')->nullable()
                ->constrained('entreprises')->nullOnDelete();

            // Ce que le scraper doit aller relever. Le login, et rien d'autre :
            // il ne connaît pas les entreprises de Selflow.
            $table->string('login', 40)->index();

            // Pourquoi ce relevé est demandé — « rejet FA-0042 sur pointOfSale ».
            // Sert à qui lit la file, pas au scraper.
            $table->string('motif')->nullable();

            $table->foreignId('rejet_id')->nullable()
                ->constrained('fne_rejets')->nullOnDelete();

            // 'en_attente' | 'servie' | 'abandonnee'
            $table->string('statut', 20)->default('en_attente')->index();

            // L'import qui a satisfait la demande. C'est lui qui la ferme :
            // une demande n'est servie que lorsqu'un relevé est réellement
            // arrivé, jamais parce que le scraper a dit l'avoir fait.
            $table->foreignId('import_id')->nullable()
                ->constrained('portail_fne_imports')->nullOnDelete();

            $table->timestamp('servie_at')->nullable();
            $table->timestamps();

            $table->index(['login', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portail_fne_demandes');
        Schema::dropIfExists('fne_rejets');
    }
};
