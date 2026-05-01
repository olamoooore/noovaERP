<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chart_of_accounts') || !Schema::hasTable('account_mappings')) {
            return;
        }

        // Seed shipping_revenue mapping for existing tenants
        $account = \App\Models\ChartOfAccount::where('code', '4101')->first();
        if ($account && !\App\Models\AccountMapping::where('mapping_key', 'shipping_revenue')->whereNull('document_type')->exists()) {
            \App\Models\AccountMapping::create([
                'mapping_key' => 'shipping_revenue',
                'account_id' => $account->id,
                'document_type' => null,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Do nothing - account mappings are managed individually
    }
};