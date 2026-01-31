<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive enhancement for Customer (res.partner pattern) and POS enterprise features
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================
        // CUSTOMER ENHANCEMENTS (res.partner pattern from Odoo)
        // =====================================================
        
        // Customer Categories/Tags
        Schema::create('customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->nullable(); // For UI display
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Customer-Category pivot
        Schema::create('customer_category', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_category_id')->constrained()->onDelete('cascade');
            $table->primary(['customer_id', 'customer_category_id']);
        });

        // Customer Contacts (multiple contacts per customer)
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Customer Addresses (multiple addresses)
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['billing', 'shipping', 'other'])->default('shipping');
            $table->string('label')->nullable(); // "Home", "Office", etc.
            $table->string('street')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Customer Price Lists (customer-specific pricing)
        Schema::create('customer_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Price list items (product-specific prices)
        Schema::create('customer_price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('customer_price_lists')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->decimal('price', 12, 2);
            $table->integer('min_quantity')->default(1);
            $table->timestamps();
        });

        // Customer Loyalty (points system)
        Schema::create('customer_loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('points_per_currency', 10, 4)->default(1); // points per $1
            $table->decimal('points_value', 10, 4)->default(0.01); // $ value per point
            $table->integer('min_points_redeem')->default(100);
            $table->integer('max_points_redeem_per_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Customer loyalty balances
        Schema::create('customer_loyalty_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('program_id')->constrained('customer_loyalty_programs')->onDelete('cascade');
            $table->integer('points_balance')->default(0);
            $table->integer('points_earned_total')->default(0);
            $table->integer('points_redeemed_total')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            
            $table->unique(['customer_id', 'program_id']);
        });

        // Loyalty transactions
        Schema::create('customer_loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('program_id')->constrained('customer_loyalty_programs')->onDelete('cascade');
            $table->enum('type', ['earn', 'redeem', 'adjust', 'expire']);
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable(); // 'pos_order', 'invoice', etc.
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Enhance customers table with additional fields
        Schema::table('customers', function (Blueprint $table) {
            // Customer type
            if (!Schema::hasColumn('customers', 'type')) {
                $table->enum('type', ['individual', 'company'])->default('individual')->after('id');
            }
            
            // Internal reference
            if (!Schema::hasColumn('customers', 'code')) {
                $table->string('code')->nullable()->after('type');
            }
            
            // Parent company (for contacts of companies)
            if (!Schema::hasColumn('customers', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('code')->constrained('customers')->nullOnDelete();
            }
            
            // Website & social
            if (!Schema::hasColumn('customers', 'website')) {
                $table->string('website')->nullable();
            }
            
            // Mobile phone
            if (!Schema::hasColumn('customers', 'mobile')) {
                $table->string('mobile')->nullable();
            }
            
            // Preferred language
            if (!Schema::hasColumn('customers', 'language')) {
                $table->string('language', 10)->nullable();
            }
            
            // Price list
            if (!Schema::hasColumn('customers', 'price_list_id')) {
                $table->foreignId('price_list_id')->nullable()->constrained('customer_price_lists')->nullOnDelete();
            }
            
            // Sales person
            if (!Schema::hasColumn('customers', 'salesperson_id')) {
                $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            }
            
            // Date of birth (for individuals)
            if (!Schema::hasColumn('customers', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable();
            }
            
            // Gender
            if (!Schema::hasColumn('customers', 'gender')) {
                $table->enum('gender', ['male', 'female', 'other'])->nullable();
            }
            
            // Preferred contact method
            if (!Schema::hasColumn('customers', 'preferred_contact')) {
                $table->enum('preferred_contact', ['email', 'phone', 'sms', 'whatsapp'])->default('email');
            }
            
            // Marketing opt-in
            if (!Schema::hasColumn('customers', 'marketing_opt_in')) {
                $table->boolean('marketing_opt_in')->default(false);
            }
            
            // Customer since
            if (!Schema::hasColumn('customers', 'customer_since')) {
                $table->date('customer_since')->nullable();
            }
            
            // Last interaction
            if (!Schema::hasColumn('customers', 'last_interaction_at')) {
                $table->timestamp('last_interaction_at')->nullable();
            }
            
            // Total purchases
            if (!Schema::hasColumn('customers', 'total_purchases')) {
                $table->decimal('total_purchases', 15, 2)->default(0);
            }
            
            // Total orders
            if (!Schema::hasColumn('customers', 'total_orders')) {
                $table->integer('total_orders')->default(0);
            }
            
            // Average order value
            if (!Schema::hasColumn('customers', 'average_order_value')) {
                $table->decimal('average_order_value', 12, 2)->default(0);
            }
        });

        // =====================================================
        // POS ENTERPRISE ENHANCEMENTS
        // =====================================================

        // POS Terminals/Registers
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable(); // Terminal-specific settings
            $table->timestamps();
        });

        // POS Sessions (shift management)
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('session_number')->unique();
            $table->enum('status', ['open', 'closing', 'closed'])->default('open');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('closing_balance', 12, 2)->nullable();
            $table->decimal('expected_balance', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index(['terminal_id', 'status']);
        });

        // POS Cash Movements (cash in/out during session)
        Schema::create('pos_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->onDelete('cascade');
            $table->enum('type', ['in', 'out']);
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // POS Held/Parked Orders
        Schema::create('pos_held_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('pos_sessions')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->json('cart_data'); // Full cart state
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamp('held_at');
            $table->timestamps();
        });

        // Update pos_orders table with additional fields
        Schema::table('pos_orders', function (Blueprint $table) {
            // Session tracking
            if (!Schema::hasColumn('pos_orders', 'session_id')) {
                $table->foreignId('session_id')->nullable()->after('id')->constrained('pos_sessions')->nullOnDelete();
            }
            
            // Terminal tracking
            if (!Schema::hasColumn('pos_orders', 'terminal_id')) {
                $table->foreignId('terminal_id')->nullable()->after('session_id')->constrained('pos_terminals')->nullOnDelete();
            }
            
            // Customer
            if (!Schema::hasColumn('pos_orders', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('terminal_id')->constrained()->nullOnDelete();
            }
            
            // Return/refund support
            if (!Schema::hasColumn('pos_orders', 'is_return')) {
                $table->boolean('is_return')->default(false);
            }
            if (!Schema::hasColumn('pos_orders', 'original_order_id')) {
                $table->foreignId('original_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            }
            
            // Loyalty points used
            if (!Schema::hasColumn('pos_orders', 'loyalty_points_earned')) {
                $table->integer('loyalty_points_earned')->default(0);
            }
            if (!Schema::hasColumn('pos_orders', 'loyalty_points_redeemed')) {
                $table->integer('loyalty_points_redeemed')->default(0);
            }
            if (!Schema::hasColumn('pos_orders', 'loyalty_discount')) {
                $table->decimal('loyalty_discount', 12, 2)->default(0);
            }
            
            // Receipt
            if (!Schema::hasColumn('pos_orders', 'receipt_number')) {
                $table->string('receipt_number')->nullable();
            }
            
            // Cashier name cache
            if (!Schema::hasColumn('pos_orders', 'cashier_name')) {
                $table->string('cashier_name')->nullable();
            }
        });

        // POS Payment Methods
        Schema::create('pos_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['cash', 'card', 'mobile', 'voucher', 'credit', 'other'])->default('cash');
            $table->string('icon')->nullable();
            $table->boolean('opens_cash_drawer')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->decimal('surcharge_percent', 5, 2)->default(0);
            $table->decimal('surcharge_fixed', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Update pos_payments to link to payment methods
        Schema::table('pos_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_payments', 'payment_method_id')) {
                $table->foreignId('payment_method_id')->nullable()->after('method')->constrained('pos_payment_methods')->nullOnDelete();
            }
            if (!Schema::hasColumn('pos_payments', 'reference')) {
                $table->string('reference')->nullable(); // Card last 4, transaction ID, etc.
            }
            if (!Schema::hasColumn('pos_payments', 'surcharge')) {
                $table->decimal('surcharge', 10, 2)->default(0);
            }
        });

        // Seed default payment methods
        DB::table('pos_payment_methods')->insert([
            ['name' => 'Cash', 'code' => 'cash', 'type' => 'cash', 'icon' => 'solar:wallet-money-bold', 'opens_cash_drawer' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Card', 'code' => 'card', 'type' => 'card', 'icon' => 'solar:card-bold', 'opens_cash_drawer' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Mobile Money', 'code' => 'mobile', 'type' => 'mobile', 'icon' => 'solar:smartphone-bold', 'opens_cash_drawer' => false, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bank Transfer', 'code' => 'transfer', 'type' => 'other', 'icon' => 'solar:bank-bold', 'opens_cash_drawer' => false, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed a default loyalty program
        DB::table('customer_loyalty_programs')->insert([
            'name' => 'Standard Loyalty Program',
            'description' => 'Earn points on every purchase',
            'points_per_currency' => 1,
            'points_value' => 0.01,
            'min_points_redeem' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('pos_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_id', 'reference', 'surcharge']);
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
            $table->dropForeign(['terminal_id']);
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['original_order_id']);
            $table->dropColumn([
                'session_id', 'terminal_id', 'customer_id', 'is_return', 'original_order_id',
                'loyalty_points_earned', 'loyalty_points_redeemed', 'loyalty_discount',
                'receipt_number', 'cashier_name'
            ]);
        });

        Schema::dropIfExists('pos_payment_methods');
        Schema::dropIfExists('pos_held_orders');
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_terminals');
        
        Schema::dropIfExists('customer_loyalty_transactions');
        Schema::dropIfExists('customer_loyalty_balances');
        Schema::dropIfExists('customer_loyalty_programs');
        Schema::dropIfExists('customer_price_list_items');
        Schema::dropIfExists('customer_price_lists');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_contacts');
        Schema::dropIfExists('customer_category');
        Schema::dropIfExists('customer_categories');

        Schema::table('customers', function (Blueprint $table) {
            $columns = [
                'type', 'code', 'parent_id', 'website', 'mobile', 'language', 
                'price_list_id', 'salesperson_id', 'date_of_birth', 'gender',
                'preferred_contact', 'marketing_opt_in', 'customer_since',
                'last_interaction_at', 'total_purchases', 'total_orders', 'average_order_value'
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
