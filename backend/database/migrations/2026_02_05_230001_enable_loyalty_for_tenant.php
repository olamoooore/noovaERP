<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
