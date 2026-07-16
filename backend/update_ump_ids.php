<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Modules\IoT\Models\DeviceConfiguration;

$kpos = DeviceConfiguration::where('name', 'UMP KPOS_80')->first();
if ($kpos) {
    $info = $kpos->connection_info ?? [];
    $info['ump_device_id'] = '69fa8cdbbefc2f1f0ce70d0f';
    $info['ump_agent_id'] = '69fa709edc6e455083bb7383';
    $kpos->update(['connection_info' => $info]);
    echo 'KPOS updated: '.json_encode($kpos->connection_info).PHP_EOL;
}

$gp = DeviceConfiguration::where('name', 'UMP Gprinter GP-D320FX')->first();
if ($gp) {
    $info = $gp->connection_info ?? [];
    $info['ump_device_id'] = '69fa8cdbbefc2f1f0ce70d13';
    $info['ump_agent_id'] = '69fa709edc6e455083bb7383';
    $gp->update(['connection_info' => $info]);
    echo 'Gprinter updated: '.json_encode($gp->connection_info).PHP_EOL;
}

echo "Done\n";
