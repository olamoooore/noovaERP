<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_packs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('module_defaults')->nullable();
            $table->jsonb('posting_profiles')->nullable();
            $table->jsonb('dimension_defaults')->nullable();
            $table->jsonb('mandatory_account_roles')->nullable();
            $table->jsonb('validation_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $packs = [
            [
                'code' => 'trading',
                'name' => 'Trading',
                'description' => 'Wholesale and distribution businesses',
                'module_defaults' => json_encode(['accounting', 'inventory', 'sales']),
                'posting_profiles' => json_encode(['cogs_tracking' => true, 'inventory_method' => 'weighted_average']),
                'dimension_defaults' => json_encode(['warehouse', 'product_category']),
                'mandatory_account_roles' => json_encode(['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'INVENTORY', 'COGS', 'RETAINED_EARNINGS']),
                'validation_rules' => json_encode(['require_inventory_valuation_tieout' => true]),
            ],
            [
                'code' => 'services',
                'name' => 'Services',
                'description' => 'Professional and consulting services',
                'module_defaults' => json_encode(['accounting', 'sales', 'field_service']),
                'posting_profiles' => json_encode(['cogs_tracking' => false, 'project_costing' => true]),
                'dimension_defaults' => json_encode(['project', 'department']),
                'mandatory_account_roles' => json_encode(['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'RETAINED_EARNINGS']),
                'validation_rules' => json_encode(['require_project_allocation' => true]),
            ],
            [
                'code' => 'manufacturing',
                'name' => 'Manufacturing',
                'description' => 'Production and manufacturing businesses',
                'module_defaults' => json_encode(['accounting', 'inventory', 'sales']),
                'posting_profiles' => json_encode(['cogs_tracking' => true, 'inventory_method' => 'weighted_average', 'wip_tracking' => true]),
                'dimension_defaults' => json_encode(['warehouse', 'production_line', 'product_category']),
                'mandatory_account_roles' => json_encode(['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'INVENTORY', 'COGS', 'RETAINED_EARNINGS']),
                'validation_rules' => json_encode(['require_inventory_valuation_tieout' => true, 'require_wip_reconciliation' => true]),
            ],
            [
                'code' => 'retail_pos',
                'name' => 'Retail / POS',
                'description' => 'Brick-and-mortar and omni-channel retail',
                'module_defaults' => json_encode(['accounting', 'inventory', 'pos', 'sales', 'loyalty']),
                'posting_profiles' => json_encode(['cogs_tracking' => true, 'inventory_method' => 'weighted_average', 'pos_integration' => true]),
                'dimension_defaults' => json_encode(['location', 'product_category']),
                'mandatory_account_roles' => json_encode(['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'BANK_MAIN', 'INVENTORY', 'COGS', 'RETAINED_EARNINGS']),
                'validation_rules' => json_encode(['require_cash_reconciliation' => true]),
            ],
            [
                'code' => 'projects',
                'name' => 'Projects',
                'description' => 'Project-based businesses (construction, IT)',
                'module_defaults' => json_encode(['accounting', 'field_service', 'sales']),
                'posting_profiles' => json_encode(['cogs_tracking' => false, 'project_costing' => true, 'wip_tracking' => true]),
                'dimension_defaults' => json_encode(['project', 'department', 'cost_center']),
                'mandatory_account_roles' => json_encode(['AR_CONTROL', 'AP_CONTROL', 'CASH_MAIN', 'RETAINED_EARNINGS', 'ACCRUED_EXPENSES']),
                'validation_rules' => json_encode(['require_project_allocation' => true]),
            ],
            [
                'code' => 'nonprofit',
                'name' => 'Nonprofit',
                'description' => 'Charities, foundations, and NGOs',
                'module_defaults' => json_encode(['accounting']),
                'posting_profiles' => json_encode(['fund_accounting' => true]),
                'dimension_defaults' => json_encode(['fund', 'program', 'grant']),
                'mandatory_account_roles' => json_encode(['CASH_MAIN', 'BANK_MAIN', 'RETAINED_EARNINGS']),
                'validation_rules' => json_encode(['require_fund_balance_tieout' => true]),
            ],
        ];

        foreach ($packs as $pack) {
            DB::table('industry_packs')->insert(array_merge($pack, [
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_packs');
    }
};
