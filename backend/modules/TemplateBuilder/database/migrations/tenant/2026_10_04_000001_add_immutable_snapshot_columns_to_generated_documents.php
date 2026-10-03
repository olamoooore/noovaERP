<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #613 (TP3): a posted/issued document must be reproducible exactly as
 * it was issued. `generated_documents` already recorded which template version
 * produced a render, but not the bytes — so reprints re-rendered against the
 * current template and current company data.
 *
 * Immutable snapshots store the rendered bytes on a disk plus their checksum.
 * Regenerating creates a NEW row and marks the previous current row
 * superseded (kept for audit), so "download" can serve the currently issued
 * copy while every historical issue stays retrievable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->string('disk', 40)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('is_immutable')->default(false);
            $table->timestamp('superseded_at')->nullable();
            $table->index(
                ['document_type', 'document_id', 'output_format', 'is_immutable'],
                'generated_documents_immutable_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropIndex('generated_documents_immutable_lookup_idx');
            $table->dropColumn(['disk', 'checksum', 'is_immutable', 'superseded_at']);
        });
    }
};
