<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Get the first user to find their tenant
$user = \App\Models\User::first();

if (!$user) {
    echo "No users found in database\n";
    exit(1);
}

echo "Current User: {$user->name}\n";
echo "Tenant ID: {$user->tenant_id}\n\n";

// Check what the FeatureGateService returns
$service = app(\App\SuperAdmin\Application\FeatureGateService::class);
$features = $service->all($user->tenant_id, true); // Force fresh

echo "=== Features returned by FeatureGateService ===\n";
echo "Total features: " . count($features) . "\n\n";

foreach ($features as $feature) {
    echo "- {$feature['key']}: enabled=" . ($feature['enabled'] ? 'true' : 'false') . ", source={$feature['source']}\n";
}

// Check specifically for HRM
$hrmFeature = collect($features)->firstWhere('key', 'hrm');

echo "\n=== HRM Feature Check ===\n";
if ($hrmFeature) {
    echo "✓ HRM Feature FOUND\n";
    echo "  Key: {$hrmFeature['key']}\n";
    echo "  Name: {$hrmFeature['name']}\n";
    echo "  Enabled: " . ($hrmFeature['enabled'] ? 'true' : 'false') . "\n";
    echo "  Source: {$hrmFeature['source']}\n";
} else {
    echo "✗ HRM Feature NOT FOUND in response!\n";
    
    // Check if HRM exists in catalog_items
    echo "\n=== Checking catalog_items table ===\n";
    $catalogItem = \App\SuperAdmin\Domain\CatalogItem::where('key', 'hrm')->first();
    if ($catalogItem) {
        echo "✓ HRM exists in catalog_items (id={$catalogItem->id})\n";
        
        // Check if tenant has entitlement
        echo "\n=== Checking tenant_entitlements table ===\n";
        $entitlement = \App\SuperAdmin\Domain\TenantEntitlement::where('tenant_id', $user->tenant_id)
            ->where('catalog_item_id', $catalogItem->id)
            ->first();
            
        if ($entitlement) {
            echo "✓ Entitlement exists\n";
            echo "  Is Entitled: " . ($entitlement->is_entitled ? 'true' : 'false') . "\n";
            echo "  Source: {$entitlement->source}\n";
        } else {
            echo "✗ NO ENTITLEMENT RECORD for this tenant!\n";
            echo "  This is why HRM is not appearing.\n";
            echo "  Run: php artisan hrm:rebuild-entitlements --tenant={$user->tenant_id}\n";
        }
    } else {
        echo "✗ HRM does NOT exist in catalog_items\n";
        echo "  Run: php artisan db:seed --class=ModuleCatalogSeeder\n";
    }
}
