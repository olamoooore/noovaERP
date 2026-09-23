<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #178: quotations and delivery orders had no version/revision chain —
 * cancel/reset flipped status in place, so a rejected document left no
 * voided-but-preserved revision history and cross-module links could not point
 * at the latest active version. Mirrors the invoice/bill/journal versioning
 * columns.
 */
return new class extends Migration
{
    private const TABLES = ['quotations', 'delivery_orders'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'correction_group_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('correction_group_id')->nullable();
                $blueprint->unsignedInteger('version_number')->default(1);
                $blueprint->boolean('is_active_version')->default(true);
                $blueprint->unsignedBigInteger('original_document_id')->nullable();
                $blueprint->unsignedBigInteger('replacement_document_id')->nullable();
                $blueprint->timestamp('superseded_at')->nullable();
                $blueprint->string('correction_type', 30)->nullable();
                $blueprint->string('correction_reason_code', 50)->nullable();
                $blueprint->text('correction_reason_text')->nullable();
                $blueprint->index('correction_group_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'correction_group_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn([
                    'correction_group_id',
                    'version_number',
                    'is_active_version',
                    'original_document_id',
                    'replacement_document_id',
                    'superseded_at',
                    'correction_type',
                    'correction_reason_code',
                    'correction_reason_text',
                ]);
            });
        }
    }
};
