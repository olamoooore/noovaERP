<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer portal uploads (signed delivery orders, payment evidence, signed
 * invoices) are not made by a platform user, so `uploaded_by_id` is null and
 * the chatter rendered those rows as "System" — losing exactly the information
 * the log exists for: who signed or sent what.
 *
 * Records the display name of a non-user uploader alongside the (nullable)
 * user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatter_attachments') && ! Schema::hasColumn('chatter_attachments', 'uploaded_by_name')) {
            Schema::table('chatter_attachments', function (Blueprint $table) {
                $table->string('uploaded_by_name')->nullable()->after('uploaded_by_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chatter_attachments') && Schema::hasColumn('chatter_attachments', 'uploaded_by_name')) {
            Schema::table('chatter_attachments', function (Blueprint $table) {
                $table->dropColumn('uploaded_by_name');
            });
        }
    }
};
