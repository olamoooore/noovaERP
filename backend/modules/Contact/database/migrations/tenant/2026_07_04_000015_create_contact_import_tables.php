<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('file_name');
            $table->string('file_hash')->index();
            $table->integer('total_rows')->default(0);
            $table->integer('valid_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->integer('duplicate_rows')->default(0);
            $table->string('status', 50)->default('pending'); // pending, validating, validated, importing, completed, failed
            $table->text('error_summary')->nullable();
            $table->json('mapping_config')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('contact_import_staging', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('batch_id')->index();
            $table->integer('row_number');
            $table->json('raw_data');
            $table->json('mapped_data')->nullable();
            $table->string('status', 50)->default('pending'); // pending, valid, error, duplicate, imported
            $table->text('errors')->nullable();
            $table->uuid('matched_contact_id')->nullable();
            $table->string('match_type')->nullable(); // exact, fuzzy, new
            $table->timestamps();

            $table->index(['tenant_id', 'batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_staging');
        Schema::dropIfExists('contact_import_batches');
    }
};
