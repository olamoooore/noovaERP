<?php
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;

$infinitiTenant = Tenant::where('email', 'like', '%infiniti%')->first();
if ($infinitiTenant) {
    echo "Infiniti Tenant: " . $infinitiTenant->name . " (" . $infinitiTenant->email . ")\n";
    echo "Tenant ID: " . $infinitiTenant->id . "\n";

    tenancy()->initialize($infinitiTenant);

    echo "\n=== Features ===\n";
    $features = \App\Models\Feature::all();
    foreach ($features as $f) {
        echo $f->id . ": " . $f->name . "\n";
    }

    echo "\n=== Tenant Features ===\n";
    $tf = \App\Models\TenantFeature::all();
    foreach ($tf as $t) {
        echo $t->id . ": feature_id=" . $t->feature_id . ", is_active=" . $t->is_active . "\n";
    }

    tenancy()->end();
} else {
    echo "Infiniti tenant not found\n";
}