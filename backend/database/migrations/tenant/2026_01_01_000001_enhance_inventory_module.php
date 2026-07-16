<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enhance Inventory Module - Add warehouse stock levels, suppliers, purchase orders, transfers
     */
    public function up(): void
    {
        // Add is_default to warehouses
        if (! Schema::hasColumn('warehouses', 'is_default')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('is_active');
                $table->string('phone')->nullable()->after('address');
                $table->string('email')->nullable()->after('phone');
                $table->string('manager_name')->nullable()->after('email');
            });
        }

        // Brands table
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('logo')->nullable();
                $table->string('website')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Add brand_id to products if not exists
        if (! Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
                $table->integer('min_stock_threshold')->default(0)->after('reorder_level');
            });
        }

        // Stock levels per warehouse
        if (! Schema::hasTable('stock_levels')) {
            Schema::create('stock_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
                $table->integer('quantity')->default(0);
                $table->integer('reserved_quantity')->default(0);
                $table->integer('min_quantity')->default(0);
                $table->integer('max_quantity')->nullable();
                $table->string('bin_location')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'warehouse_id']);
                $table->index('quantity');
            });
        }

        // Suppliers table
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('contact_person')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable();
                $table->string('tax_number')->nullable();
                $table->string('website')->nullable();
                $table->integer('payment_terms')->default(30);
                $table->decimal('credit_limit', 15, 2)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Purchase orders table
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->string('po_number')->unique();
                $table->foreignId('supplier_id')->constrained()->onDelete('restrict');
                $table->foreignId('warehouse_id')->constrained()->onDelete('restrict');
                $table->enum('status', ['draft', 'pending', 'approved', 'ordered', 'partial', 'received', 'cancelled'])->default('draft');
                $table->date('order_date');
                $table->date('expected_date')->nullable();
                $table->date('received_date')->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('shipping_cost', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->string('currency', 3)->default('USD');
                $table->text('notes')->nullable();
                $table->text('internal_notes')->nullable();
                $table->string('invoice_number')->nullable();
                $table->string('invoice_file')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->foreignId('approved_by')->nullable()->constrained('users');
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('status');
                $table->index('order_date');
            });
        }

        // Purchase order items
        if (! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('restrict');
                $table->string('description')->nullable();
                $table->integer('quantity_ordered');
                $table->integer('quantity_received')->default(0);
                $table->decimal('unit_cost', 15, 2);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->decimal('line_total', 15, 2);
                $table->timestamps();

                $table->index('product_id');
            });
        }

        // Warehouse transfers
        if (! Schema::hasTable('warehouse_transfers')) {
            Schema::create('warehouse_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('transfer_number')->unique();
                $table->foreignId('from_warehouse_id')->constrained('warehouses')->onDelete('restrict');
                $table->foreignId('to_warehouse_id')->constrained('warehouses')->onDelete('restrict');
                $table->enum('status', ['draft', 'pending', 'in_transit', 'completed', 'cancelled'])->default('draft');
                $table->date('transfer_date');
                $table->date('expected_date')->nullable();
                $table->date('completed_date')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->foreignId('completed_by')->nullable()->constrained('users');
                $table->timestamps();

                $table->index('status');
            });
        }

        // Warehouse transfer items
        if (! Schema::hasTable('warehouse_transfer_items')) {
            Schema::create('warehouse_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('warehouse_transfer_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('restrict');
                $table->integer('quantity_requested');
                $table->integer('quantity_sent')->default(0);
                $table->integer('quantity_received')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // Stock adjustment reasons
        if (! Schema::hasTable('stock_adjustment_reasons')) {
            Schema::create('stock_adjustment_reasons', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->enum('type', ['increase', 'decrease', 'both'])->default('both');
                $table->boolean('requires_approval')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Enhance stock_movements table
        if (! Schema::hasColumn('stock_movements', 'warehouse_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('stock_movements', 'old_quantity')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->integer('old_quantity')->default(0)->after('quantity');
            });
        }

        if (! Schema::hasColumn('stock_movements', 'adjustment_reason_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('adjustment_reason_id')->nullable()->after('reference_id')->constrained('stock_adjustment_reasons')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('stock_movements', 'batch_number')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->string('batch_number')->nullable()->after('notes');
            });
        }

        if (! Schema::hasColumn('stock_movements', 'expiry_date')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            });
        }

        // Update type enum to include more movement types
        // Note: In PostgreSQL, we need to handle enum differently

        // Inventory settings
        if (! Schema::hasTable('inventory_settings')) {
            Schema::create('inventory_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('allow_negative_stock')->default(false);
                $table->boolean('auto_generate_sku')->default(true);
                $table->string('sku_prefix')->default('SKU');
                $table->integer('sku_length')->default(8);
                $table->boolean('track_batch_numbers')->default(false);
                $table->boolean('track_expiry_dates')->default(false);
                $table->boolean('require_adjustment_approval')->default(false);
                $table->integer('low_stock_threshold_days')->default(7);
                $table->boolean('enable_barcode_scanning')->default(true);
                $table->timestamps();
            });
        }

        // Seed default adjustment reasons
        if (\DB::table('stock_adjustment_reasons')->count() === 0) {
            \DB::table('stock_adjustment_reasons')->insert([
                ['name' => 'Damaged Goods', 'code' => 'DAMAGED', 'type' => 'decrease', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Expired Stock', 'code' => 'EXPIRED', 'type' => 'decrease', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Stock Count Correction', 'code' => 'COUNT', 'type' => 'both', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Theft/Loss', 'code' => 'LOSS', 'type' => 'decrease', 'requires_approval' => DB::raw('true'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Found Stock', 'code' => 'FOUND', 'type' => 'increase', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Return to Stock', 'code' => 'RETURN', 'type' => 'increase', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Opening Balance', 'code' => 'OPENING', 'type' => 'both', 'requires_approval' => DB::raw('false'), 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // Seed default inventory settings
        if (\DB::table('inventory_settings')->count() === 0) {
            \DB::table('inventory_settings')->insert([
                'allow_negative_stock' => DB::raw('false'),
                'auto_generate_sku' => DB::raw('true'),
                'sku_prefix' => 'SKU',
                'sku_length' => 8,
                'track_batch_numbers' => DB::raw('false'),
                'track_expiry_dates' => DB::raw('false'),
                'require_adjustment_approval' => DB::raw('false'),
                'low_stock_threshold_days' => 7,
                'enable_barcode_scanning' => DB::raw('true'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_settings');
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_adjustment_reasons');
        Schema::dropIfExists('brands');

        if (Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
                $table->dropColumn(['brand_id', 'min_stock_threshold']);
            });
        }

        if (Schema::hasColumn('warehouses', 'is_default')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn(['is_default', 'phone', 'email', 'manager_name']);
            });
        }

        if (Schema::hasColumn('stock_movements', 'warehouse_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropForeign(['warehouse_id']);
                $table->dropForeign(['adjustment_reason_id']);
                $table->dropColumn(['warehouse_id', 'old_quantity', 'adjustment_reason_id', 'batch_number', 'expiry_date']);
            });
        }
    }
};
