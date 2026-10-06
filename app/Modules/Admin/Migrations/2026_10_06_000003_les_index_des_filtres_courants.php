<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les index qui manquaient aux filtres courants — chantier 12.3.
 *
 * Relevés le 06/10/2026 sur la base de mesure (MariaDB, 10 000 articles) :
 *
 * - `ecritures_comptables.comptaflow_sync_status` : la reprise du déversement
 *   la relit toutes les cinq minutes, sur une table qui ne fait que grossir ;
 * - `ventes.etape` : chaque liste de pièces filtre le site puis l'étape.
 *
 * `achats.numero_fne` en manque aussi, et reste sans : la colonne appartient
 * au périmètre FNE gelé (voir CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('ecritures_comptables', 'ecritures_entreprise_sync_index')) {
            Schema::table('ecritures_comptables', function (Blueprint $table) {
                $table->index(['entreprise_id', 'comptaflow_sync_status'], 'ecritures_entreprise_sync_index');
            });
        }

        if (!Schema::hasIndex('ventes', 'ventes_site_etape_index')) {
            Schema::table('ventes', function (Blueprint $table) {
                $table->index(['point_de_vente_id', 'etape'], 'ventes_site_etape_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('ecritures_comptables', 'ecritures_entreprise_sync_index')) {
            Schema::table('ecritures_comptables', fn (Blueprint $t) => $t->dropIndex('ecritures_entreprise_sync_index'));
        }

        if (Schema::hasIndex('ventes', 'ventes_site_etape_index')) {
            Schema::table('ventes', fn (Blueprint $t) => $t->dropIndex('ventes_site_etape_index'));
        }
    }
};
