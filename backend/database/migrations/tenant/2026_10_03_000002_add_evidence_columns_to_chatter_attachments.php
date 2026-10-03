<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #630 (SALES-CHAT / CH2): chatter attachments that mirror an evidence
 * artifact (invoice evidence, e-sign signed copy, signed delivery order) must
 * carry a schema-level reference to that evidence so deletion can never
 * destroy the underlying file. Path-based detection (#629) stays as a safety
 * net for rows registered before this column existed — no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chatter_attachments', 'evidence_type')) {
            return;
        }

        Schema::table('chatter_attachments', function (Blueprint $table) {
            $table->string('evidence_type', 120)->nullable()->after('checksum');
            $table->unsignedBigInteger('evidence_id')->nullable()->after('evidence_type');
            $table->index(['evidence_type', 'evidence_id'], 'chatter_att_evidence_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chatter_attachments', 'evidence_type')) {
            return;
        }

        Schema::table('chatter_attachments', function (Blueprint $table) {
            $table->dropIndex('chatter_att_evidence_idx');
            $table->dropColumn(['evidence_type', 'evidence_id']);
        });
    }
};
