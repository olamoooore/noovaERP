<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EnableAllFeaturesSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = '0a281004-25ef-406b-8404-5a1ddc7d5679';
        $features = DB::table('features')->get();

        foreach ($features as $feature) {
            DB::table('tenant_features')->updateOrInsert(
                ['tenant_id' => $tenantId, 'feature_id' => $feature->id],
                ['is_enabled' => true, 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->command->info('Enabled '.$features->count().' features for demo tenant');
    }
}
