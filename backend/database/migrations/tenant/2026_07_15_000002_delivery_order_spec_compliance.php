<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Expand delivery_orders status enum ──────────────────────────
        // Drop the old CHECK constraint from the original enum, then change
        // to a plain varchar so we can freely add 'done', 'ready', etc.
        DB::statement('ALTER TABLE delivery_orders DROP CONSTRAINT IF EXISTS delivery_orders_status_check');
        DB::statement('ALTER TABLE delivery_orders ALTER COLUMN status TYPE varchar(20)');
        DB::statement("ALTER TABLE delivery_orders ALTER COLUMN status SET DEFAULT 'draft'");

        // Migrate existing 'confirmed' records to 'done' since the old code
        // deducted stock on confirm (they are effectively completed).
        DB::table('delivery_orders')->where('status', 'confirmed')->update(['status' => 'done']);

        // ── Add new fields to delivery_orders ────────────────────────────
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->datetime('completed_at')->nullable()->after('expected_date');
            $table->string('source', 30)->default('sales_order')->after('completed_at');
            $table->string('external_reference')->nullable()->after('source');
            $table->boolean('shipping_address_overridden')->default(false)->after('shipping_address');
            $table->foreignId('completed_by')->nullable()->after('confirmed_by')
                ->constrained('users')->nullOnDelete();

            $table->index('external_reference');
            $table->index(['status', 'delivery_date']);
        });

        // ── Add invoicing_policy to sales_order_lines ────────────────────
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('invoicing_policy', 20)->default('ordered_qty')->after('quantity_invoiced');
        });

        // Backfill: orders that have bill_by_delivered_qty = true get 'delivered_qty'
        DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.bill_by_delivered_qty', true)
            ->update(['sales_order_lines.invoicing_policy' => 'delivered_qty']);
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['completed_at', 'source', 'external_reference', 'shipping_address_overridden', 'completed_by']);
            // Restore old enum values
            $table->enum('status', ['draft', 'confirmed', 'cancelled'])->default('draft')->change();
        });

        // Migrate 'done' back to 'confirmed'
        DB::table('delivery_orders')->where('status', 'done')->update(['status' => 'confirmed']);

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('invoicing_policy');
        });
    }
};
