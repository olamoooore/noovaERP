<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Checking Tenants ===\n";
$tenants = \App\Models\Tenant::take(5)->get();

if ($tenants->isEmpty()) {
    echo "No tenants found!\n";
} else {
    echo "Found {$tenants->count()} tenants:\n\n";
    foreach ($tenants as $tenant) {
        echo "- ID: {$tenant->id}\n";
        echo "  Name: {$tenant->name}\n";
        echo "  Email: {$tenant->email}\n\n";
    }
}

echo "\n=== Checking Current User ===\n";
$user = \App\Models\User::first();
if ($user) {
    echo "User: {$user->name}\n";
    echo "Email: {$user->email}\n";
    echo "Tenant ID: " . ($user->tenant_id ?? 'NULL') . "\n";
    
    if (!$user->tenant_id && $tenants->isNotEmpty()) {
        echo "\n⚠️  User has no tenant_id! Assigning first tenant...\n";
        $firstTenant = $tenants->first();
        $user->tenant_id = $firstTenant->id;
        $user->save();
        echo "✓ User assigned to tenant: {$firstTenant->name} ({$firstTenant->id})\n";
    }
}
