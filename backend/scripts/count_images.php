<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

tenancy()->initialize('e255e52c-a4a6-4158-954a-af04408e9386');

$withmuu = \App\Models\Product::where('image', 'like', '%withmuu.com%')->count();
$ktown = \App\Models\Product::where('image', 'like', '%ktown4u.com%')->count();
$weverse = \App\Models\Product::where('image', 'like', '%weverse%')->count();
$smtown = \App\Models\Product::where('image', 'like', '%smtown%')->count();
$withdrama = \App\Models\Product::where('image', 'like', '%withdrama%')->count();
$other = \App\Models\Product::where('image', 'like', 'http%')
    ->where('image', 'not like', '%withmuu%')
    ->where('image', 'not like', '%ktown4u%')
    ->where('image', 'not like', '%weverse%')
    ->where('image', 'not like', '%smtown%')
    ->where('image', 'not like', '%withdrama%')
    ->count();
$local = \App\Models\Product::where('image', 'like', 'products/%')->count();
$noImage = \App\Models\Product::whereNull('image')->orWhere('image', '')->count();

echo "=== Image Source Breakdown ===\n";
echo "withmuu.com:  $withmuu\n";
echo "ktown4u.com:  $ktown\n";
echo "weverse:      $weverse\n";
echo "smtown:       $smtown\n";
echo "withdrama:    $withdrama\n";
echo "other web:    $other\n";
echo "local files:  $local\n";
echo "no image:     $noImage\n";
echo 'TOTAL:        '.\App\Models\Product::count()."\n\n";

// Get unique goodsNo for withmuu
$goodsNos = \App\Models\Product::where('image', 'like', '%withmuu.com%')
    ->pluck('image')
    ->map(function ($url) {
        preg_match('/goodsNo=(\d+)/', $url, $m);

        return $m[1] ?? null;
    })
    ->filter()
    ->unique();
echo 'Unique withmuu goodsNo: '.$goodsNos->count()."\n";

// Show some other URLs
echo "\n=== Sample 'other' URLs ===\n";
$others = \App\Models\Product::where('image', 'like', 'http%')
    ->where('image', 'not like', '%withmuu%')
    ->where('image', 'not like', '%ktown4u%')
    ->where('image', 'not like', '%weverse%')
    ->where('image', 'not like', '%smtown%')
    ->where('image', 'not like', '%withdrama%')
    ->limit(10)
    ->pluck('image');
foreach ($others as $url) {
    echo "  $url\n";
}
