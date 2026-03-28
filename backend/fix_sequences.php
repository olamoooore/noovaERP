<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement("SET search_path TO 'e255e52c-a4a6-4158-954a-af04408e9386', public");

$tables = [
    'product_attributes',
    'product_attribute_values',
    'product_features',
    'product_feature_options',
    'product_custom_fields',
    'product_categories',
    'product_units',
    'products',
];

$fixed = 0;
foreach ($tables as $t) {
    try {
        $maxId = DB::selectOne("SELECT COALESCE(MAX(id), 0) as max_id FROM \"$t\"");
        $seqName = "{$t}_id_seq";
        $seq = DB::selectOne("SELECT last_value, is_called FROM \"$seqName\"");
        $nextVal = $seq->is_called ? $seq->last_value + 1 : $seq->last_value;

        if ($maxId->max_id >= $nextVal) {
            $newVal = $maxId->max_id + 1;
            DB::statement("SELECT setval('\"$seqName\"', $newVal, false)");
            echo "FIXED $t: was $nextVal, now $newVal (max_id={$maxId->max_id})\n";
            $fixed++;
        } else {
            echo "OK    $t: seq=$nextVal max_id={$maxId->max_id}\n";
        }
    } catch (Exception $e) {
        echo "ERROR $t: " . substr($e->getMessage(), 0, 80) . "\n";
    }
}
echo "\nFixed $fixed sequence(s).\n";
