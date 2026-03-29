<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('session_id');
            $table->foreign('legal_entity_id')
                ->references('id')->on('legal_entities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('migration_jobs', function (Blueprint $table) {
            $table->dropForeign(['legal_entity_id']);
            $table->dropColumn('legal_entity_id');
        });
    }
};
