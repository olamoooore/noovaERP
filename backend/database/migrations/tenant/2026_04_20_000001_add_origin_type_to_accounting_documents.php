<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add origin_type and accounting-origin metadata to invoices, bills, and journal_entries.
     *
     * origin_type tracks HOW a document was created:
     *   - sales_order, pos, ecommerce, manual, admin, accounting_origin
     *
     * Additional metadata fields:
     *   - origin_reference_type / origin_reference_id: polymorphic link to source doc
     *   - origin_context: JSON bag for channel-specific metadata
     *   - created_via_module / created_via_role: audit trail
     */
    public function up(): void
    {
        // ── Invoices ─────────────────────────────────────────
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('origin_type', 50)->nullable()->after('created_by')
                ->comment('How this invoice was created: sales_order|pos|ecommerce|manual|admin|accounting_origin');
            $table->string('origin_reference_type', 100)->nullable()->after('origin_type');
            $table->unsignedBigInteger('origin_reference_id')->nullable()->after('origin_reference_type');
            $table->jsonb('origin_context')->nullable()->after('origin_reference_id');
            $table->string('created_via_module', 100)->nullable()->after('origin_context');
            $table->string('created_via_role', 100)->nullable()->after('created_via_module');

            $table->index('origin_type');
            $table->index(['origin_reference_type', 'origin_reference_id'], 'invoices_origin_ref_idx');
        });

        // ── Bills ────────────────────────────────────────────
        Schema::table('bills', function (Blueprint $table) {
            $table->string('origin_type', 50)->nullable()->after('created_by')
                ->comment('How this bill was created: procurement|expense|manual|admin|accounting_origin');
            $table->string('origin_reference_type', 100)->nullable()->after('origin_type');
            $table->unsignedBigInteger('origin_reference_id')->nullable()->after('origin_reference_type');
            $table->jsonb('origin_context')->nullable()->after('origin_reference_id');
            $table->string('created_via_module', 100)->nullable()->after('origin_context');
            $table->string('created_via_role', 100)->nullable()->after('created_via_module');

            $table->index('origin_type');
            $table->index(['origin_reference_type', 'origin_reference_id'], 'bills_origin_ref_idx');
        });

        // ── Journal Entries ──────────────────────────────────
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('origin_type', 50)->nullable()->after('created_by')
                ->comment('How this JE was created: invoice|bill|payment|manual|adjustment|pos|ecommerce');
            $table->string('origin_reference_type', 100)->nullable()->after('origin_type');
            $table->unsignedBigInteger('origin_reference_id')->nullable()->after('origin_reference_type');
            $table->jsonb('origin_context')->nullable()->after('origin_reference_id');
            $table->string('created_via_module', 100)->nullable()->after('origin_context');

            $table->index('origin_type');
            $table->index(['origin_reference_type', 'origin_reference_id'], 'je_origin_ref_idx');
        });

        // ── Backfill existing records ────────────────────────
        // Invoices: infer origin_type from existing foreign keys
        DB::statement("
            UPDATE invoices SET origin_type = CASE
                WHEN pos_order_id IS NOT NULL THEN 'pos'
                WHEN web_order_id IS NOT NULL THEN 'ecommerce'
                WHEN sales_order_id IS NOT NULL THEN 'sales_order'
                ELSE 'manual'
            END
            WHERE origin_type IS NULL
        ");

        // Bills: infer from bill_type (if column exists)
        if (Schema::hasColumn('bills', 'bill_type')) {
            DB::statement("
                UPDATE bills SET origin_type = CASE
                    WHEN bill_type = 'procurement' THEN 'procurement'
                    WHEN bill_type = 'expense' THEN 'expense'
                    ELSE 'manual'
                END
                WHERE origin_type IS NULL
            ");
        }

        // Journal entries: infer from type column
        DB::statement("
            UPDATE journal_entries SET origin_type = type
            WHERE origin_type IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_origin_ref_idx');
            $table->dropIndex(['origin_type']);
            $table->dropColumn([
                'origin_type', 'origin_reference_type', 'origin_reference_id',
                'origin_context', 'created_via_module', 'created_via_role',
            ]);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_origin_ref_idx');
            $table->dropIndex(['origin_type']);
            $table->dropColumn([
                'origin_type', 'origin_reference_type', 'origin_reference_id',
                'origin_context', 'created_via_module', 'created_via_role',
            ]);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('je_origin_ref_idx');
            $table->dropIndex(['origin_type']);
            $table->dropColumn([
                'origin_type', 'origin_reference_type', 'origin_reference_id',
                'origin_context', 'created_via_module',
            ]);
        });
    }
};
