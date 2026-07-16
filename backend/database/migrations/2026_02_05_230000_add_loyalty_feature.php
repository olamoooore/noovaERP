<?php

use App\Models\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

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
