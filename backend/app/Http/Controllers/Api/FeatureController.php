<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\TenantFeature;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function index()
    {
        $features = Feature::all()->map(function ($feature) {
            $tenantFeature = TenantFeature::where('feature_id', $feature->id)->first();
            
            return [
                'id' => $feature->id,
                'name' => $feature->name,
                'key' => $feature->key,
                'description' => $feature->description,
                'is_core' => $feature->is_core,
                'enabled' => $feature->is_core || ($tenantFeature && $tenantFeature->is_enabled),
            ];
        });

        return response()->json($features);
    }

    public function toggle(Request $request, $id)
    {
        $feature = Feature::findOrFail($id);

        if ($feature->is_core) {
            return response()->json(['message' => 'Cannot disable core features'], 403);
        }

        $tenantFeature = TenantFeature::firstOrNew(['feature_id' => $feature->id]);
        $tenantFeature->is_enabled = $request->boolean('enabled');
        
        if ($tenantFeature->is_enabled && !$tenantFeature->enabled_at) {
            $tenantFeature->enabled_at = now();
        }
        
        $tenantFeature->save();

        return response()->json([
            'message' => $tenantFeature->is_enabled ? 'Feature enabled' : 'Feature disabled',
            'enabled' => $tenantFeature->is_enabled
        ]);
    }
}
