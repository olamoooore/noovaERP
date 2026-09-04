<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue noovaerp/NoovaERP#61 (security, highest priority).
 *
 * The ACC-005 settlement batch (payment_executions, settlements,
 * allocations, unapplied_cash) was provisioned in the CENTRAL (public)
 * schema with an app-level tenant_id column as the only isolation
 * discriminator. Tenant-scoped routes (routes/tenant.php) queried the
 * models with no $connection override, so with `public` in the
 * search_path fallback every tenant read and wrote the SAME shared
 * tables — a cross-tenant leakage vector and inconsistent with the
 * schema-per-tenant model used by every other accounting table.
 *
 * This tenant migration creates the four tables inside the active
 * tenant schema (backfilling any rows that belong to this tenant from
 * the old central tables, if they still exist and are non-empty) and
 * pairs with central migration
 * `2026_09_04_000003_drop_central_settlement_batch_tables.php` which
 * removes the shared copies so no silent public-schema fallback
 * remains possible. The allocations definition folds in the reversal
 * tracking columns (formerly central migration
 * 2026_08_25_000001_add_reversal_tracking_to_allocations).
 */
return new class extends Migration
{
    private const TABLES = [
        'payment_executions',
        'settlements',
        'allocations',
        'unapplied_cash',
    ];

    public function up(): void
    {
        // Create-in-tenant only when this exact schema lacks the table.
        // Schema::hasTable() must NOT be used: it resolves through
        // search_path and would report the shared public table as
        // "existing" in a tenant schema (and vice versa in single-schema
        // test databases where central and tenant steps share one schema).
        if (! $this->existsInCurrentSchema('payment_executions')) {
            $this->createPaymentExecutions();
        }
        if (! $this->existsInCurrentSchema('settlements')) {
            $this->createSettlements();
        }
        if (! $this->existsInCurrentSchema('allocations')) {
            $this->createAllocations();
        }
        if (! $this->existsInCurrentSchema('unapplied_cash')) {
            $this->createUnappliedCash();
        }

        $this->backfillFromCentral();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->existsInCurrentSchema($table)) {
                Schema::dropIfExists($table);
            }
        }
    }

    private function existsInCurrentSchema(string $table): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = current_schema() LIMIT 1',
            [$table]
        );
    }

    private function centralTableExists(string $table): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ? LIMIT 1",
            [$table]
        );
    }

    /** Tenant uuids as recorded in public.tenants for the active schema. */
    private function activeTenantIds(): array
    {
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;

        // Single-schema environments (test databases) run under `public`
        // itself — central and tenant "copies" are the same table there,
        // so there is nothing to backfill.
        if ($schema === 'public') {
            return [];
        }

        $candidates = [$schema];
        if (str_starts_with($schema, 'tenant_')) {
            $candidates[] = substr($schema, 7);
            $candidates[] = str_replace('_', '-', substr($schema, 7));
        }

        // PG would reject non-uuid literals in the whereIn — only keep
        // candidates that are plausibly tenant uuids.
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $candidates = array_values(array_filter(
            array_unique($candidates),
            fn (string $c) => (bool) preg_match($uuidPattern, $c)
        ));
        if (empty($candidates)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            DB::table('public.tenants')->whereIn('id', $candidates)->pluck('id')->all()
        )));
    }

    private function backfillFromCentral(): void
    {
        $tenantIds = $this->activeTenantIds();
        if (empty($tenantIds)) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! $this->centralTableExists($table)) {
                continue;
            }
            $count = DB::table("public.{$table}")->whereIn('tenant_id', $tenantIds)->count();
            if ($count === 0) {
                continue;
            }

            // Copy only this tenant's rows, skipping any already present
            // (idempotent re-run) matched on primary key.
            $select = DB::table("public.{$table}")->whereIn('tenant_id', $tenantIds);
            $existingIds = DB::table($table)->pluck('id')->all();
            if (! empty($existingIds)) {
                $select->whereNotIn('id', $existingIds);
            }
            foreach ($select->get() as $row) {
                $data = (array) $row;
                unset($data['tenant_id']);
                DB::table($table)->insert($data);
            }
        }
    }

    private function createPaymentExecutions(): void
    {
        Schema::create('payment_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->string('execution_number', 50)->unique();
            $table->unsignedBigInteger('payment_id')->index();
            $table->string('execution_state', 30)->default('PREPARED')->index();

            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 8)->nullable();

            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('provider_name', 100)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('provider_idempotency_key', 255)->nullable();
            $table->string('provider_operation_reference', 255)->nullable();
            $table->string('external_settlement_reference', 255)->nullable();

            $table->integer('submission_attempts')->default(0);
            $table->text('last_submission_error')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->boolean('approval_required')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();

            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'execution_state']);
            $table->index(['payment_id', 'execution_state']);
        });
    }

    private function createSettlements(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->string('settlement_number', 50)->unique();
            $table->string('settlement_state', 30)->default('UNRECOGNISED')->index();
            $table->string('settlement_type', 50)->nullable();
            $table->unsignedBigInteger('payment_execution_id')->nullable()->index();
            $table->string('source_record_type', 100)->nullable();
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->decimal('unapplied_amount', 15, 2)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'settlement_state']);
        });
    }

    private function createAllocations(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('settlement_id')->index();
            $table->string('allocation_type', 50)->nullable();
            $table->string('source_record_type', 100);
            $table->unsignedBigInteger('source_record_id')->index();
            $table->decimal('allocated_amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->decimal('exchange_rate', 15, 8)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->uuid('correlation_id')->nullable();

            // Reversal tracking (P0-5, ACC-005B) — previously central
            // migration 2026_08_25_000001, folded in here.
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversal_of_allocation_id')->nullable();
            $table->string('reversal_reason', 500)->nullable();

            $table->timestamps();
            $table->index(['settlement_id', 'source_record_type', 'source_record_id']);
        });
    }

    private function createUnappliedCash(): void
    {
        Schema::create('unapplied_cash', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('settlement_id')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency_code', 10)->default('USD');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('AVAILABLE')->index();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }
};
