<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->string('module_code');
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'module_code']);
        });

        Schema::create('module_dependency_rules', function (Blueprint $table) {
            $table->id();
            $table->string('module_code');
            $table->string('depends_on_module_code');
            $table->boolean('is_hard_dependency')->default(true);
            $table->timestamps();

            $table->unique(['module_code', 'depends_on_module_code']);
        });

        // Seed core dependency rules
        $rules = [
            ['module_code' => 'inventory',    'depends_on_module_code' => 'accounting', 'is_hard_dependency' => true],
            ['module_code' => 'pos',          'depends_on_module_code' => 'accounting', 'is_hard_dependency' => true],
            ['module_code' => 'pos',          'depends_on_module_code' => 'inventory',  'is_hard_dependency' => true],
            ['module_code' => 'ecommerce',    'depends_on_module_code' => 'inventory',  'is_hard_dependency' => true],
            ['module_code' => 'ecommerce',    'depends_on_module_code' => 'accounting', 'is_hard_dependency' => true],
            ['module_code' => 'ocr',          'depends_on_module_code' => 'accounting', 'is_hard_dependency' => true],
            ['module_code' => 'payroll',      'depends_on_module_code' => 'accounting', 'is_hard_dependency' => true],
            ['module_code' => 'field_service', 'depends_on_module_code' => 'accounting', 'is_hard_dependency' => false],
        ];

        foreach ($rules as $rule) {
            DB::table('module_dependency_rules')->insert(array_merge($rule, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_dependency_rules');
        Schema::dropIfExists('entity_modules');
    }
};
