<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #149: online quotation acceptance / eSignature portal.
 *
 * Adds the columns the public acceptance endpoint needs to identify an
 * un-authenticated customer link and record who accepted + when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'acceptance_token')) {
                $table->string('acceptance_token', 64)->nullable()->unique()->after('notes');
            }
            if (! Schema::hasColumn('sales_orders', 'acceptance_expires_at')) {
                $table->timestamp('acceptance_expires_at')->nullable()->after('acceptance_token');
            }
            if (! Schema::hasColumn('sales_orders', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('acceptance_expires_at');
            }
            if (! Schema::hasColumn('sales_orders', 'signer_name')) {
                $table->string('signer_name')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('sales_orders', 'signer_email')) {
                $table->string('signer_email')->nullable()->after('signer_name');
            }
            if (! Schema::hasColumn('sales_orders', 'signature_data')) {
                $table->text('signature_data')->nullable()->after('signer_email');
            }
            if (! Schema::hasColumn('sales_orders', 'acceptance_source')) {
                $table->string('acceptance_source', 32)->nullable()->after('signature_data');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('sales_orders', 'acceptance_token') ? 'acceptance_token' : null,
                Schema::hasColumn('sales_orders', 'acceptance_expires_at') ? 'acceptance_expires_at' : null,
                Schema::hasColumn('sales_orders', 'accepted_at') ? 'accepted_at' : null,
                Schema::hasColumn('sales_orders', 'signer_name') ? 'signer_name' : null,
                Schema::hasColumn('sales_orders', 'signer_email') ? 'signer_email' : null,
                Schema::hasColumn('sales_orders', 'signature_data') ? 'signature_data' : null,
                Schema::hasColumn('sales_orders', 'acceptance_source') ? 'acceptance_source' : null,
            ]);

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
