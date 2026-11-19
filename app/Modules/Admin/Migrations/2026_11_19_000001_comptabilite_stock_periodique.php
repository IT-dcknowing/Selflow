<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('entreprises')) {
            Schema::table('entreprises', function (Blueprint $table) {
                if (!Schema::hasColumn('entreprises', 'comptabilite_stock_active')) {
                    $table->boolean('comptabilite_stock_active')->default(false);
                }
                if (!Schema::hasColumn('entreprises', 'comptabilite_stock_quotidienne')) {
                    $table->boolean('comptabilite_stock_quotidienne')->default(false);
                }
                if (!Schema::hasColumn('entreprises', 'comptabilite_stock_periode_mois')) {
                    $table->unsignedSmallInteger('comptabilite_stock_periode_mois')->default(1);
                }
                if (!Schema::hasColumn('entreprises', 'comptabilite_stock_date_debut')) {
                    $table->date('comptabilite_stock_date_debut')->nullable();
                }
                if (!Schema::hasColumn('entreprises', 'comptabilite_stock_date_fin')) {
                    $table->date('comptabilite_stock_date_fin')->nullable();
                }
            });
        }

        if (!Schema::hasTable('stock_comptabilites_periodiques')) {
            Schema::create('stock_comptabilites_periodiques', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entreprise_id');
                $table->date('date_debut');
                $table->date('date_fin');
                $table->unsignedInteger('operations_creees')->default(0);
                $table->timestamps();
                $table->unique(
                    ['entreprise_id', 'date_debut', 'date_fin'],
                    'stock_compta_periode_unique'
                );
                $table->index(['entreprise_id', 'date_fin'], 'stock_compta_periode_fin_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_comptabilites_periodiques');

        if (Schema::hasTable('entreprises')) {
            foreach ([
                'comptabilite_stock_active',
                'comptabilite_stock_quotidienne',
                'comptabilite_stock_periode_mois',
                'comptabilite_stock_date_debut',
                'comptabilite_stock_date_fin',
            ] as $column) {
                if (Schema::hasColumn('entreprises', $column)) {
                    Schema::table('entreprises', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
