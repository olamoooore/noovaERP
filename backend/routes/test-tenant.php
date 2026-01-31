<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api/v1')->group(function () {
    Route::get('/test-tenant-context', function () {
        $tenancy = app(\Stancl\Tenancy\Tenancy::class);
        
        $data = [
            'tenant_initialized' => $tenancy->initialized,
            'tenant_id' => $tenancy->initialized ? tenant()->id : null,
            'connection' => DB::getDefaultConnection(),
            'database' => DB::connection()->getDatabaseName(),
            'bootstrappers_config' => config('tenancy.bootstrappers'),
        ];
        
        // Check if bootstrappers were run
        if ($tenancy->initialized) {
            $data['tenant_key'] = tenant()->getTenantKey();
        }
        
        // Get search_path BEFORE any queries
        try {
            $result = DB::select("SHOW search_path");
            $data['search_path_before'] = $result[0]->search_path ?? 'unknown';
        } catch (\Exception $e) {
            $data['search_path_error'] = $e->getMessage();
        }
        
        // Manually set search_path
        if ($tenancy->initialized) {
            $tenantId = tenant()->id;
            DB::statement("SET search_path TO \"{$tenantId}\", public");
            
            // Get search_path AFTER manual set
            try {
                $result = DB::select("SHOW search_path");
                $data['search_path_after_manual_set'] = $result[0]->search_path ?? 'unknown';
            } catch (\Exception $e) {
                $data['search_path_after_error'] = $e->getMessage();
            }
        }
        
        // Test query
        try {
            $data['journal_entries_count'] = DB::table('journal_entries')->count();
            $data['posted_entries_count'] = DB::table('journal_entries')->where('status', 'posted')->count();
        } catch (\Exception $e) {
            $data['query_error'] = $e->getMessage();
        }
        
        return response()->json($data);
    });
});
