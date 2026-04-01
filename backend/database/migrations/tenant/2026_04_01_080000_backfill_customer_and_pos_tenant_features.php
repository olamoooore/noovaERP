<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createCustomerTables();
        $this->enhanceCustomersTable();
        $this->createPosTables();
        $this->enhancePosOrdersTable();
        $this->enhancePosPaymentsTable();
        $this->seedDefaults();
    }

    public function down(): void
    {
        // Backfill migration only. Do not drop tenant business tables on rollback.
    }

    private function createCustomerTables(): void
    {
        if (!Schema::hasTable('customer_categories')) {
            Schema::create('customer_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('color')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_category')) {
            Schema::create('customer_category', function (Blueprint $table) {
                $table->foreignId('customer_id')->constrained()->onDelete('cascade');
                $table->foreignId('customer_category_id')->constrained()->onDelete('cascade');
                $table->primary(['customer_id', 'customer_category_id']);
            });
        }

        if (!Schema::hasTable('customer_contacts')) {
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
        }

        if (!Schema::hasTable('customer_addresses')) {
            Schema::create('customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->onDelete('cascade');
                $table->enum('type', ['billing', 'shipping', 'other'])->default('shipping');
                $table->string('label')->nullable();
                $table->string('street')->nullable();
                $table->string('street2')->nullable();
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                $table->string('zip')->nullable();
                $table->string('country')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_price_lists')) {
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
        }

        if (!Schema::hasTable('customer_price_list_items')) {
            Schema::create('customer_price_list_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_list_id')->constrained('customer_price_lists')->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->decimal('price', 12, 2);
                $table->integer('min_quantity')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_loyalty_programs')) {
            Schema::create('customer_loyalty_programs', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('points_per_currency', 10, 4)->default(1);
                $table->decimal('points_value', 10, 4)->default(0.01);
                $table->integer('min_points_redeem')->default(100);
                $table->integer('max_points_redeem_per_order')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customer_loyalty_balances')) {
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
        }

        if (!Schema::hasTable('customer_loyalty_transactions')) {
            Schema::create('customer_loyalty_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->onDelete('cascade');
                $table->foreignId('program_id')->constrained('customer_loyalty_programs')->onDelete('cascade');
                $table->enum('type', ['earn', 'redeem', 'adjust', 'expire']);
                $table->integer('points');
                $table->integer('balance_after');
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }
    }

    private function enhanceCustomersTable(): void
    {
        $columns = [
            'type' => fn (Blueprint $table) => $table->enum('type', ['individual', 'company'])->default('individual')->after('id'),
            'code' => fn (Blueprint $table) => $table->string('code')->nullable()->after('type'),
            'parent_id' => fn (Blueprint $table) => $table->foreignId('parent_id')->nullable()->after('code')->constrained('customers')->nullOnDelete(),
            'website' => fn (Blueprint $table) => $table->string('website')->nullable(),
            'mobile' => fn (Blueprint $table) => $table->string('mobile')->nullable(),
            'language' => fn (Blueprint $table) => $table->string('language', 10)->nullable(),
            'price_list_id' => fn (Blueprint $table) => $table->foreignId('price_list_id')->nullable()->constrained('customer_price_lists')->nullOnDelete(),
            'salesperson_id' => fn (Blueprint $table) => $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete(),
            'date_of_birth' => fn (Blueprint $table) => $table->date('date_of_birth')->nullable(),
            'gender' => fn (Blueprint $table) => $table->enum('gender', ['male', 'female', 'other'])->nullable(),
            'preferred_contact' => fn (Blueprint $table) => $table->enum('preferred_contact', ['email', 'phone', 'sms', 'whatsapp'])->default('email'),
            'marketing_opt_in' => fn (Blueprint $table) => $table->boolean('marketing_opt_in')->default(false),
            'customer_since' => fn (Blueprint $table) => $table->date('customer_since')->nullable(),
            'last_interaction_at' => fn (Blueprint $table) => $table->timestamp('last_interaction_at')->nullable(),
            'total_purchases' => fn (Blueprint $table) => $table->decimal('total_purchases', 15, 2)->default(0),
            'total_orders' => fn (Blueprint $table) => $table->integer('total_orders')->default(0),
            'average_order_value' => fn (Blueprint $table) => $table->decimal('average_order_value', 12, 2)->default(0),
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('customers', $column)) {
                Schema::table('customers', $definition);
            }
        }
    }

    private function createPosTables(): void
    {
        if (!Schema::hasTable('pos_terminals')) {
            Schema::create('pos_terminals', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->string('receipt_header')->nullable();
                $table->string('receipt_footer')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_sessions')) {
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
        }

        if (!Schema::hasTable('pos_cash_movements')) {
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
        }

        if (!Schema::hasTable('pos_held_orders')) {
            Schema::create('pos_held_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('pos_sessions')->onDelete('cascade');
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('reference')->nullable();
                $table->json('cart_data');
                $table->decimal('subtotal', 12, 2);
                $table->decimal('tax_amount', 12, 2);
                $table->decimal('total', 12, 2);
                $table->text('notes')->nullable();
                $table->timestamp('held_at');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_payment_methods')) {
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
        }
    }

    private function enhancePosOrdersTable(): void
    {
        if (!Schema::hasTable('pos_orders')) {
            return;
        }

        $columns = [
            'session_id' => fn (Blueprint $table) => $table->foreignId('session_id')->nullable()->after('id')->constrained('pos_sessions')->nullOnDelete(),
            'terminal_id' => fn (Blueprint $table) => $table->foreignId('terminal_id')->nullable()->after('session_id')->constrained('pos_terminals')->nullOnDelete(),
            'customer_id' => fn (Blueprint $table) => $table->foreignId('customer_id')->nullable()->after('terminal_id')->constrained()->nullOnDelete(),
            'is_return' => fn (Blueprint $table) => $table->boolean('is_return')->default(false),
            'original_order_id' => fn (Blueprint $table) => $table->foreignId('original_order_id')->nullable()->constrained('pos_orders')->nullOnDelete(),
            'loyalty_points_earned' => fn (Blueprint $table) => $table->integer('loyalty_points_earned')->default(0),
            'loyalty_points_redeemed' => fn (Blueprint $table) => $table->integer('loyalty_points_redeemed')->default(0),
            'loyalty_discount' => fn (Blueprint $table) => $table->decimal('loyalty_discount', 12, 2)->default(0),
            'receipt_number' => fn (Blueprint $table) => $table->string('receipt_number')->nullable(),
            'cashier_name' => fn (Blueprint $table) => $table->string('cashier_name')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('pos_orders', $column)) {
                Schema::table('pos_orders', $definition);
            }
        }
    }

    private function enhancePosPaymentsTable(): void
    {
        if (!Schema::hasTable('pos_payments')) {
            return;
        }

        if (!Schema::hasColumn('pos_payments', 'payment_method_id')) {
            Schema::table('pos_payments', function (Blueprint $table) {
                $table->foreignId('payment_method_id')->nullable()->after('method')->constrained('pos_payment_methods')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('pos_payments', 'reference')) {
            Schema::table('pos_payments', function (Blueprint $table) {
                $table->string('reference')->nullable();
            });
        }

        if (!Schema::hasColumn('pos_payments', 'surcharge')) {
            Schema::table('pos_payments', function (Blueprint $table) {
                $table->decimal('surcharge', 10, 2)->default(0);
            });
        }
    }

    private function seedDefaults(): void
    {
        if (Schema::hasTable('pos_payment_methods')) {
            foreach ([
                ['name' => 'Cash', 'code' => 'cash', 'type' => 'cash', 'icon' => 'solar:wallet-money-bold', 'opens_cash_drawer' => true, 'sort_order' => 1],
                ['name' => 'Card', 'code' => 'card', 'type' => 'card', 'icon' => 'solar:card-bold', 'opens_cash_drawer' => false, 'sort_order' => 2],
                ['name' => 'Mobile Money', 'code' => 'mobile', 'type' => 'mobile', 'icon' => 'solar:smartphone-bold', 'opens_cash_drawer' => false, 'sort_order' => 3],
                ['name' => 'Bank Transfer', 'code' => 'transfer', 'type' => 'other', 'icon' => 'solar:bank-bold', 'opens_cash_drawer' => false, 'sort_order' => 4],
            ] as $method) {
                DB::table('pos_payment_methods')->updateOrInsert(
                    ['code' => $method['code']],
                    array_merge($method, ['updated_at' => now(), 'created_at' => now()])
                );
            }
        }

        if (Schema::hasTable('customer_loyalty_programs')) {
            DB::table('customer_loyalty_programs')->updateOrInsert(
                ['name' => 'Standard Loyalty Program'],
                [
                    'description' => 'Earn points on every purchase',
                    'points_per_currency' => 1,
                    'points_value' => 0.01,
                    'min_points_redeem' => 100,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
};