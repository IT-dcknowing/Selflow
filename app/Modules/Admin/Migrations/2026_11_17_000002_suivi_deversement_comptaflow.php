<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecritures_comptables')
            && !Schema::hasColumn('ecritures_comptables', 'comptaflow_sync_error')) {
            Schema::table('ecritures_comptables', function (Blueprint $table) {
                $table->text('comptaflow_sync_error')->nullable()->after('comptaflow_sync_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ecritures_comptables')
            && Schema::hasColumn('ecritures_comptables', 'comptaflow_sync_error')) {
            Schema::table('ecritures_comptables', function (Blueprint $table) {
                $table->dropColumn('comptaflow_sync_error');
            });
        }
    }
};
