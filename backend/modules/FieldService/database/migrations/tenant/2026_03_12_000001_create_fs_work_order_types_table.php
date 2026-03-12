<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_work_order_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('color', 7)->default('#3B82F6');
            $table->decimal('default_duration_hours', 5, 2)->default(1.00);
            $table->boolean('auto_invoice')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed default types
        \Illuminate\Support\Facades\DB::table('fs_work_order_types')->insert([
            ['name' => 'Installation',  'color' => '#3B82F6', 'default_duration_hours' => 2.00, 'auto_invoice' => true,  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Maintenance',   'color' => '#10B981', 'default_duration_hours' => 1.50, 'auto_invoice' => true,  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Repair',        'color' => '#F59E0B', 'default_duration_hours' => 2.00, 'auto_invoice' => true,  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Inspection',    'color' => '#8B5CF6', 'default_duration_hours' => 1.00, 'auto_invoice' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Delivery',      'color' => '#EC4899', 'default_duration_hours' => 0.50, 'auto_invoice' => true,  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Other',         'color' => '#6B7280', 'default_duration_hours' => 1.00, 'auto_invoice' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_work_order_types');
    }
};
