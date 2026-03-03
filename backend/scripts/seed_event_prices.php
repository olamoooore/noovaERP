<?php
// Run: php artisan tinker --execute="require 'scripts/seed_event_prices.php';"

DB::statement('SET search_path TO "e255e52c-a4a6-4158-954a-af04408e9386", public');

// Add columns if not exist
try {
    DB::statement('ALTER TABLE events ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT 0');
    echo "price column added\n";
} catch (Exception $e) {
    echo "price: " . $e->getMessage() . "\n";
}
try {
    DB::statement('ALTER TABLE events ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL');
    echo "image_url column added\n";
} catch (Exception $e) {
    echo "image_url: " . $e->getMessage() . "\n";
}

// Seed prices and images
DB::table('events')->where('id', 1)->update([
    'price' => 75.00,
    'image_url' => 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=800&q=80',
]);
DB::table('events')->where('id', 2)->update([
    'price' => 150.00,
    'image_url' => 'https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=800&q=80',
]);
DB::table('events')->where('id', 3)->update([
    'price' => 299.00,
    'image_url' => 'https://images.unsplash.com/photo-1492684223066-81342ee5ff30?w=800&q=80',
]);
DB::table('events')->where('id', 4)->update([
    'price' => 0,
    'image_url' => 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=800&q=80',
]);

echo "Prices and images seeded!\n";
$events = DB::select('SELECT id, title, price FROM events');
foreach ($events as $e) {
    echo $e->id . ': ' . $e->title . ' - $' . $e->price . "\n";
}
