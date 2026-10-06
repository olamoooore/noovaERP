<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #334 (Q5): send quotation with acceptance link and portal accept.
 *
 * The #149 acceptance portal (quotation-accept/{token}) was built for the
 * SalesOrder; quotations need the same token/signer columns so the acceptance
 * link issued by the new send endpoint resolves to the Quotation it belongs
 * to. Mirrors the sales_orders columns from #149.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'acceptance_token')) {
                $table->string('acceptance_token', 64)->nullable()->unique()->after('notes');
            }
            if (! Schema::hasColumn('quotations', 'acceptance_expires_at')) {
                $table->timestamp('acceptance_expires_at')->nullable()->after('acceptance_token');
            }
            if (! Schema::hasColumn('quotations', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('acceptance_expires_at');
            }
            if (! Schema::hasColumn('quotations', 'signer_name')) {
                $table->string('signer_name')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('quotations', 'signer_email')) {
                $table->string('signer_email')->nullable()->after('signer_name');
            }
            if (! Schema::hasColumn('quotations', 'signature_data')) {
                $table->text('signature_data')->nullable()->after('signer_email');
            }
            if (! Schema::hasColumn('quotations', 'acceptance_source')) {
                $table->string('acceptance_source', 32)->nullable()->after('signature_data');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('quotations', 'acceptance_token') ? 'acceptance_token' : null,
                Schema::hasColumn('quotations', 'acceptance_expires_at') ? 'acceptance_expires_at' : null,
                Schema::hasColumn('quotations', 'accepted_at') ? 'accepted_at' : null,
                Schema::hasColumn('quotations', 'signer_name') ? 'signer_name' : null,
                Schema::hasColumn('quotations', 'signer_email') ? 'signer_email' : null,
                Schema::hasColumn('quotations', 'signature_data') ? 'signature_data' : null,
                Schema::hasColumn('quotations', 'acceptance_source') ? 'acceptance_source' : null,
            ]);

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
