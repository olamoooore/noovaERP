<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #153: signed proof-of-delivery needs explicit signer metadata
 * (name, email, signed_at) so it can travel with the resulting Invoice for
 * client AP/payment processing. The earlier #177 migration added the file
 * path + upload timestamp; this adds the actor-side columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'signed_by_name')) {
                $table->string('signed_by_name')->nullable()->after('signed_document_uploaded_at');
            }
            if (! Schema::hasColumn('delivery_orders', 'signed_by_email')) {
                $table->string('signed_by_email')->nullable()->after('signed_by_name');
            }
            if (! Schema::hasColumn('delivery_orders', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('signed_by_email');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_orders')) {
            return;
        }

        Schema::table('delivery_orders', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('delivery_orders', 'signed_by_name') ? 'signed_by_name' : null,
                Schema::hasColumn('delivery_orders', 'signed_by_email') ? 'signed_by_email' : null,
                Schema::hasColumn('delivery_orders', 'signed_at') ? 'signed_at' : null,
            ]);

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
