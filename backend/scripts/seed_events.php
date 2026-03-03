<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET search_path TO "e255e52c-a4a6-4158-954a-af04408e9386", public');

$count = DB::table('events')->count();
if ($count > 0) {
    echo "Events already exist ($count). Skipping.\n";
    exit;
}

$typeId = DB::table('event_types')->value('id');
if (!$typeId) {
    DB::table('event_types')->insert(['name'=>'Concert','slug'=>'concert','created_at'=>now(),'updated_at'=>now()]);
    $typeId = DB::table('event_types')->value('id');
}
echo "Using event_type_id: $typeId\n";

$now = now();

$rows = [
    ['title'=>'K-Pop Night Vol. 1','description'=>'An amazing night of K-Pop performances.','venue'=>'Manila Arena, Philippines','event_date'=>$now->copy()->addDays(30)->toDateTimeString(),'start_date'=>$now->copy()->addDays(30)->toDateTimeString(),'end_date'=>$now->copy()->addDays(30)->addHours(4)->toDateTimeString(),'total_capacity'=>5000,'available_capacity'=>5000,'status'=>'published','event_type_id'=>$typeId,'ticket_acquisition_mode'=>'buy','sale_start_date'=>$now->copy()->addDay()->toDateTimeString(),'sale_end_date'=>$now->copy()->addDays(28)->toDateTimeString(),'created_at'=>$now,'updated_at'=>$now],
    ['title'=>'NoovaERP Music Festival 2026','description'=>'Annual music festival with local and international artists.','venue'=>'MOA Arena, Pasay City','event_date'=>$now->copy()->addDays(60)->toDateTimeString(),'start_date'=>$now->copy()->addDays(60)->toDateTimeString(),'end_date'=>$now->copy()->addDays(62)->toDateTimeString(),'total_capacity'=>15000,'available_capacity'=>15000,'status'=>'published','event_type_id'=>$typeId,'ticket_acquisition_mode'=>'buy','sale_start_date'=>$now->copy()->addDays(5)->toDateTimeString(),'sale_end_date'=>$now->copy()->addDays(55)->toDateTimeString(),'created_at'=>$now,'updated_at'=>$now],
    ['title'=>'Exclusive Bid Concert','description'=>'Bid-only premium concert at Solaire.','venue'=>'Solaire Entertainment City','event_date'=>$now->copy()->addDays(45)->toDateTimeString(),'start_date'=>$now->copy()->addDays(45)->toDateTimeString(),'end_date'=>$now->copy()->addDays(45)->addHours(3)->toDateTimeString(),'total_capacity'=>800,'available_capacity'=>800,'status'=>'published','event_type_id'=>$typeId,'ticket_acquisition_mode'=>'bid','sale_start_date'=>$now->copy()->addDays(2)->toDateTimeString(),'sale_end_date'=>$now->copy()->addDays(40)->toDateTimeString(),'created_at'=>$now,'updated_at'=>$now],
    ['title'=>'Staff Training Workshop','description'=>'Internal staff training and operational coordination.','venue'=>'NoovaERP HQ Conference Room','event_date'=>$now->copy()->addDays(10)->toDateTimeString(),'start_date'=>$now->copy()->addDays(10)->toDateTimeString(),'end_date'=>$now->copy()->addDays(10)->addHours(8)->toDateTimeString(),'total_capacity'=>50,'available_capacity'=>50,'status'=>'published','event_type_id'=>$typeId,'ticket_acquisition_mode'=>'buy','sale_start_date'=>$now->toDateTimeString(),'sale_end_date'=>$now->copy()->addDays(8)->toDateTimeString(),'created_at'=>$now,'updated_at'=>$now],
];

DB::table('events')->insert($rows);
echo "Seeded " . count($rows) . " events. Total in DB: " . DB::table('events')->count() . "\n";
