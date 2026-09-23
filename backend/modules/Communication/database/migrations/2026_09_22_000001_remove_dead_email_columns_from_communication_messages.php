<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #80: the email reply-to/threading columns on communication_messages
 * were never populated by any code path (no incoming-email integration exists),
 * so they advertised a feature that isn't implemented. Removed per the issue's
 * recommendation.
 *
 * Safety: each column is dropped only when EVERY value is NULL — a populated
 * column is left in place rather than destroying data.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'email_from', 'email_to', 'email_cc', 'email_bcc',
        'email_message_id', 'email_in_reply_to', 'parent_id',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('communication_messages')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('communication_messages', $column)) {
                continue;
            }

            if (DB::table('communication_messages')->whereNotNull($column)->exists()) {
                continue;
            }

            Schema::table('communication_messages', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_messages')) {
            return;
        }

        Schema::table('communication_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('communication_messages', 'email_from')) {
                $table->string('email_from')->nullable();
            }
            if (! Schema::hasColumn('communication_messages', 'email_to')) {
                $table->text('email_to')->nullable();
            }
            if (! Schema::hasColumn('communication_messages', 'email_cc')) {
                $table->text('email_cc')->nullable();
            }
            if (! Schema::hasColumn('communication_messages', 'email_bcc')) {
                $table->text('email_bcc')->nullable();
            }
            if (! Schema::hasColumn('communication_messages', 'email_message_id')) {
                $table->string('email_message_id')->nullable()->index();
            }
            if (! Schema::hasColumn('communication_messages', 'email_in_reply_to')) {
                $table->string('email_in_reply_to')->nullable();
            }
            if (! Schema::hasColumn('communication_messages', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable();
            }
        });
    }
};
