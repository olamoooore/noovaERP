<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Import batches - tracks each import session
        Schema::create('accounting_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('import_type', 50)->index(); // bill, invoice, journal_entry, payment
            $table->string('file_name', 255);
            $table->string('file_hash', 64);
            $table->integer('total_rows')->default(0);
            $table->integer('valid_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->integer('imported_rows')->default(0);
            $table->string('status', 20)->default('pending')->index(); // pending, validated, importing, completed, failed
            $table->json('mapping_config')->nullable();
            $table->json('error_summary')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'import_type']);
            $table->index(['tenant_id', 'status']);
        });

        // Import staging rows - individual records awaiting validation/commit
        Schema::create('accounting_import_staging', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('batch_id')->constrained('accounting_import_batches')->onDelete('cascade');
            $table->integer('row_number')->unsigned();
            $table->string('source_record_key', 100)->nullable();
            $table->json('raw_data');
            $table->json('mapped_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('original_legacy_values')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending, valid, warning, error, imported
            $table->string('source_system', 50)->default('manual');
            $table->string('source_payload_hash', 64)->nullable();
            $table->uuid('target_entity_id')->nullable(); // created entity ID after import
            $table->string('target_entity_type', 50)->nullable(); // Bill, Invoice, JournalEntry
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
            $table->index(['tenant_id', 'batch_id', 'status']);
            $table->unique(['batch_id', 'row_number']);
        });

        // Import audit trail - tracks all import operations
        Schema::create('accounting_import_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->uuid('staging_id')->nullable()->index();
            $table->string('operation', 50); // staged, validated, committed, rejected, error
            $table->json('details')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'batch_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_import_audit');
        Schema::dropIfExists('accounting_import_staging');
        Schema::dropIfExists('accounting_import_batches');
    }
};
