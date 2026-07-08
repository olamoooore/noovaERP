<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_type', 40)->index();
            $table->uuid('template_id');
            $table->string('scope_type', 20)->default('tenant'); // tenant|branch|warehouse|currency|language|customer_group
            $table->string('scope_value')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('document_templates')->cascadeOnDelete();
            $table->index(['document_type', 'scope_type', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_assignments');
    }
};
