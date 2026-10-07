<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #331 follow-up — backfill the `customer_deposit` mapping for tenants
 * that applied the MY CoA template before #331 added the key.
 *
 * Decision (7 Oct 2026, product owner): the MY template's default deposit
 * account is 221200 Receipt in Advance (Customer Prepayments). Since #763 a
 * template tenant never gets a generic account auto-created, so an existing
 * MY tenant without this mapping cannot post a down-payment invoice to the
 * decided account.
 *
 * Insert-only and idempotent: inserts the default (document_type NULL)
 * customer_deposit → 221200 mapping only when the tenant's
 * accounting.coa_template is MY, account 221200 exists and no default
 * customer_deposit mapping exists. Never updates or deletes a mapping and
 * never creates an account; a tenant without 221200 is left as is and the
 * #775 account-mapping health check lists the role as unmapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')
            || ! Schema::hasTable('chart_of_accounts')
            || ! Schema::hasTable('account_mappings')) {
            return;
        }

        $raw = DB::table('settings')->where('key', 'accounting.coa_template')->value('value');
        $template = is_string($raw) ? json_decode($raw, true) : $raw;
        if ($template !== 'MY') {
            return;
        }

        $alreadyMapped = DB::table('account_mappings')
            ->where('mapping_key', 'customer_deposit')
            ->whereNull('document_type')
            ->exists();
        if ($alreadyMapped) {
            return;
        }

        $accountId = DB::table('chart_of_accounts')->where('code', '221200')->orderBy('id')->value('id');
        if (! $accountId) {
            return;
        }

        DB::table('account_mappings')->insert([
            'mapping_key' => 'customer_deposit',
            'account_id' => $accountId,
            'document_type' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Forward-only: the mapping may have been edited or posted against
        // since; it is managed in Accounting Settings > Default Accounts.
    }
};
