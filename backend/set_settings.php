<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

Setting::setValue('iot_agent_url', 'https://ump.example.com');
echo "Set iot_agent_url\n";

Setting::setValue('iot_agent_token', 'ump_6d99550cafe0b09a68801c7d6b89061bd8221893d370c8d955e542a94da0e87f');
echo "Set iot_agent_token\n";

// Verify
echo "URL: " . Setting::getValue('iot_agent_url') . PHP_EOL;
echo "Token: " . Setting::getValue('iot_agent_token') . PHP_EOL;
