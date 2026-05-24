<?php
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\\\\Contracts\Console\Kernel')->bootstrap();
use Illuminate\\\\Support\Facades\DB;
\$sourceEmail = 'admin@demo.com';
\$targetEmail = 'admin@demo4.com';
\$targetDomain = 'demo4.localhost';
try {
    \$tenant = DB::table('tenants')->where('email', \$targetEmail)->first();
    \$domain = DB::table('domains')->where('domain', \$targetDomain)->first();
    \$user = DB::table('users')->where('email', \$targetEmail)->first();
    \$access = \$user ? DB::table('user_company_access')->where('user_id', \$user->id)->first() : null;
    \$getEntitlements = function(\$email) {
        \$u = DB::table('users')->where('email', \$email)->first();
        if (!\$u) return [];
        \$tid = DB::table('user_company_access')->where('user_id', \$u->id)->value('tenant_id') ?? DB::table('user_company_access')->where('user_id', \$u->id)->value('company_id');
        if (!\$tid) return [];
        return DB::table('tenant_entitlements')->join('catalog_items', 'tenant_entitlements.catalog_item_id', '=', 'catalog_items.id')->where('tenant_entitlements.tenant_id', \$tid)->pluck('catalog_items.slug')->toArray();
    };
    \$sourceModules = \$getEntitlements(\$sourceEmail);
    \$targetModules = \$getEntitlements(\$targetEmail);
    echo json_encode([
        'tenant' => \$tenant ? ['id' => \$tenant->id, 'name' => \$tenant->name, 'email' => \$tenant->email] : null,
        'domain' => \$domain ? ['id' => \$domain->id, 'domain' => \$domain->domain, 'tid' => \$domain->tenant_id] : null,
        'user' => \$user ? ['id' => \$user->id, 'email' => \$user->email] : null,
        'access' => \$access ? ['id' => \$access->id, 'tid' => (\$access->tenant_id ?? \$access->company_id)] : null,
        'source_count' => count(\$sourceModules),
        'target_count' => count(\$targetModules),
        'missing' => array_values(array_diff(\$sourceModules, \$targetModules)),
        'extra' => array_values(array_diff(\$targetModules, \$sourceModules)),
    ], JSON_PRETTY_PRINT);
} catch (Exception \$e) {
    echo json_encode(['err' => \$e->getMessage()]);
}