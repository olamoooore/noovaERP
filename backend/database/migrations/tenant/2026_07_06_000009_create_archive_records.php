<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_archive_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('archived_by');
            $table->timestamp('archived_at');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->text('restore_reason')->nullable();
            $table->integer('retention_days')->default(2555);
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'entity_type', 'restored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_archive_records');
    }
};
