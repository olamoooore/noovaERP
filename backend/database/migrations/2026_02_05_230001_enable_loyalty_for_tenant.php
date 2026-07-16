<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('tenant_features')) {
            return;
        }

        $featureId = DB::table('features')->where('key', 'loyalty')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->insert([
                'feature_id' => $featureId,
                'is_enabled' => true,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $featureId = DB::table('features')->where('key', 'loyalty')->value('id');
        if ($featureId) {
            DB::table('tenant_features')->where('feature_id', $featureId)->delete();
        }
    }
};
