<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('queue_configs', 'deleted_at')) {
            Schema::table('queue_configs', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('queue_sessions', 'deleted_at')) {
            Schema::table('queue_sessions', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::table('queue_configs', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
