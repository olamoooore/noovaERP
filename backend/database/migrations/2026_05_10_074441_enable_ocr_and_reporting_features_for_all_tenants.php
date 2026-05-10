<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add ocr-bookkeeping and financial-reporting features to all tenant schemas.
     */
    public function up(): void
    {
        // Get all tenants
        $tenants = DB::table('public.tenants')->get(['id']);
        $featuresToAdd = [14, 15]; // ocr-bookkeeping, financial-reporting

        foreach ($tenants as $tenant) {
            $schema = $tenant->id;

            // Add features to tenant schema (if not exists)
            $existingFeatures = DB::table("{$schema}.features")
                ->whereIn('id', $featuresToAdd)
                ->pluck('id');

            $featuresToInsert = array_diff($featuresToAdd, $existingFeatures->toArray());

            if (!empty($featuresToInsert)) {
                $publicFeatures = DB::table('public.features')
                    ->whereIn('id', $featuresToInsert)
                    ->get(['id', 'key', 'name', 'description', 'is_core']);

                foreach ($publicFeatures as $feature) {
                    DB::table("{$schema}.features")->insert([
                        'id' => $feature->id,
                        'key' => $feature->key,
                        'name' => $feature->name,
                        'description' => $feature->description,
                        'is_core' => $feature->is_core,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Enable features in tenant_features
            foreach ($featuresToAdd as $featureId) {
                $exists = DB::table("{$schema}.tenant_features")
                    ->where('feature_id', $featureId)
                    ->exists();

                if (!$exists) {
                    DB::table("{$schema}.tenant_features")->insert([
                        'feature_id' => $featureId,
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table("{$schema}.tenant_features")
                        ->where('feature_id', $featureId)
                        ->update(['is_enabled' => true]);
                }
            }

            // Invalidate feature cache for this tenant
            cache()->forget("feature_gate:{$schema}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This migration adds features to tenant schemas.
        // Reversal would be destructive; not recommended for production.
        // If needed, manually disable features or restore from backup.
    }
};
