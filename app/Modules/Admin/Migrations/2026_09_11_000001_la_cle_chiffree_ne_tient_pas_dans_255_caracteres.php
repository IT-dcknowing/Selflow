<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clé de liaison chiffrée ne tenait pas dans sa colonne.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui s'est passé
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Première liaison lancée pour de vrai, les deux applications côte à côte :
 *
 *     SQLSTATE[22001] 1406 Data too long for column 'comptaflow_sync_key'
 *
 * Comptaflow avait créé le dossier et rendu la clé. Selflow n'a pas pu la
 * ranger : la colonne est un `varchar(255)`, posée en juin quand la clé s'y
 * écrivait **en clair**. Le lot 15 a posé le chiffrement — cast `encrypted` —
 * sans toucher à la colonne. Or une clé chiffrée pèse **288 caractères** :
 * elle n'y est jamais entrée, et n'y serait jamais entrée.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Pourquoi la suite d'épreuves ne disait rien
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Elle tourne sur SQLite, **qui ignore la longueur déclarée d'un `varchar`** :
 * il range la chaîne entière sans un mot. MySQL, lui, refuse. Quarante-deux
 * épreuves de liaison passaient au vert sur un chemin que la production ne
 * pouvait pas emprunter.
 *
 * C'est la même famille que le lot 21 — la base de production ne ressemble pas
 * à celle des épreuves —, prise par l'autre bout : là, une colonne absente ;
 * ici, une colonne trop étroite.
 *
 * `text` et non `varchar(1024)` : la taille d'un chiffré dépend de la longueur
 * de la clé, du vecteur d'initialisation et de l'empreinte. Elle changera le
 * jour où le chiffrement changera. On ne remet pas un plafond qu'il faudra
 * relever. Les trois colonnes chiffrées de `fne_credentials` sont déjà en
 * `text` ; celle-ci était la seule restée en arrière.
 *
 * Rien à convertir : aucune clé n'a jamais pu s'y écrire depuis le lot 15.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('entreprises', 'comptaflow_sync_key')) {
            return;
        }

        Schema::table('entreprises', function (Blueprint $table) {
            $table->text('comptaflow_sync_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('entreprises', 'comptaflow_sync_key')) {
            return;
        }

        // Revenir en arrière tronquerait toute clé déjà posée, donc la rendrait
        // indéchiffrable sans que rien ne le dise. On vide plutôt : une liaison
        // se redemande, une clé à moitié lue ne se répare pas.
        DB::table('entreprises')->update(['comptaflow_sync_key' => null]);

        Schema::table('entreprises', function (Blueprint $table) {
            $table->string('comptaflow_sync_key', 255)->nullable()->change();
        });
    }
};
