<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Feature;

return new class extends Migration
{
    public function up(): void
    {
        Feature::firstOrCreate(
            ['key' => 'loyalty'],
            [
                'name' => 'Loyalty Program',
                'description' => 'Customer loyalty and rewards program',
                'is_core' => false,
                'is_enabled' => true,
            ]
        );
    }

    public function down(): void
    {
        Feature::where('key', 'loyalty')->delete();
    }
};
