<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: CRM customer profile alignment with the canonical Contact Master.
 *
 * - customers.core_contact_uuid: canonical ORGANISATION identity link (CT-VEN-003).
 * - customers.legal_entity_id + unique (legal_entity_id, code): customer-code
 *   ownership/uniqueness scope (tenant_id, legal_entity_id, customer_code)
 *   (CT-VEN-011). Tenant isolation is provided by schema-per-tenant; the
 *   expression index coalesces NULL entity ids so unscoped codes stay unique.
 * - customers.billing_party: legacy billing legal name/address/attention kept
 *   as CRM migration/compatibility data only (CT-VEN-012/029).
 * - customer_contacts.core_contact_uuid: canonical PERSON identity link.
 * - customer_contacts: transaction-safe single active primary per account
 *   via unique partial index (CT-VEN-007/028). Pre-existing duplicate
 *   primaries are resolved deterministically (earliest row kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'core_contact_uuid')) {
                $table->uuid('core_contact_uuid')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('customers', 'billing_party')) {
                $table->json('billing_party')->nullable()->after('shipping_address');
            }
        });

        if (Schema::hasTable('legal_entities') && ! Schema::hasColumn('customers', 'legal_entity_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('legal_entity_id')->nullable()->after('core_contact_uuid')
                    ->constrained('legal_entities')->nullOnDelete();
            });
        }

        $indexExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'customers' AND indexname = 'customers_entity_code_unique'"
        ))->isNotEmpty();
        if (! $indexExists) {
            DB::statement('CREATE UNIQUE INDEX customers_entity_code_unique ON customers (COALESCE(legal_entity_id, 0), code) WHERE code IS NOT NULL AND deleted_at IS NULL');
        }

        Schema::table('customer_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_contacts', 'core_contact_uuid')) {
                $table->uuid('core_contact_uuid')->nullable()->after('customer_id')->index();
            }
            if (! Schema::hasColumn('customer_contacts', 'department_name')) {
                $table->string('department_name', 150)->nullable()->after('contact_type');
            }
        });

        // Deterministically resolve pre-existing multiple primaries per account
        // before enforcing the invariant (keep the earliest association).
        DB::statement('
            UPDATE customer_contacts cc
            SET is_primary = false
            FROM (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY customer_id ORDER BY id
                    ) AS rn
                    FROM customer_contacts
                    WHERE is_primary = true
                ) ranked
                WHERE rn > 1
            ) dupes
            WHERE cc.id = dupes.id
        ');

        $primaryIndexExists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'customer_contacts' AND indexname = 'customer_contacts_one_primary'"
        ))->isNotEmpty();
        if (! $primaryIndexExists) {
            DB::statement('CREATE UNIQUE INDEX customer_contacts_one_primary ON customer_contacts (customer_id) WHERE is_primary = true');
        }
    }

    public function down(): void
    {
        // Schema-qualified: unqualified DROP INDEX can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".customer_contacts_one_primary");
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".customers_entity_code_unique");

        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropColumn(['core_contact_uuid', 'department_name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'legal_entity_id')) {
                $table->dropConstrainedForeignId('legal_entity_id');
            }
            $table->dropColumn(['core_contact_uuid', 'billing_party']);
        });
    }
};
