<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (!Schema::hasColumn('entreprises', 'comptaflow_mode_deversement')) {
                $table->string('comptaflow_mode_deversement', 16)->default('manuel')->after('comptaflow_sync_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('entreprises', function (Blueprint $table) {
            if (Schema::hasColumn('entreprises', 'comptaflow_mode_deversement')) {
                $table->dropColumn('comptaflow_mode_deversement');
            }
        });
    }
};
