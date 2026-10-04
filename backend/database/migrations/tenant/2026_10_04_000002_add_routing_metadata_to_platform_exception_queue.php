<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #902 (PC8): the exception queue must carry routing metadata so the
 * operations console (#914) and the affected document can surface a queued
 * failure — which document, which legal entity, which role owns it and which
 * retry handler can re-run the operation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_exception_queue', function (Blueprint $table) {
            $table->string('document_type')->nullable()->after('exception_type');
            $table->string('document_id')->nullable()->after('document_type');
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('document_id');
            $table->string('owner_role')->nullable()->after('severity');
            $table->string('retry_handler')->nullable()->after('context');

            $table->index(['tenant_id', 'document_type', 'document_id'], 'exception_queue_document_idx');
            $table->index(['tenant_id', 'owner_role'], 'exception_queue_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_exception_queue', function (Blueprint $table) {
            $table->dropIndex('exception_queue_document_idx');
            $table->dropIndex('exception_queue_owner_idx');
            $table->dropColumn([
                'document_type',
                'document_id',
                'legal_entity_id',
                'owner_role',
                'retry_handler',
            ]);
        });
    }
};
