<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;

$s = Setting::where('key', 'iot_agent_token')->first();
echo 'Setting record: '.json_encode($s).PHP_EOL;
echo 'Value: '.Setting::getValue('iot_agent_token').PHP_EOL;

// Also check agent URL
echo 'Agent URL: '.Setting::getValue('iot_agent_url').PHP_EOL;
