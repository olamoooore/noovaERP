<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_search_index', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->unique();
            $table->string('display_name');
            $table->string('search_name'); // lowercase, normalized
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('company_name')->nullable();
            $table->text('roles_text')->nullable(); // comma-separated roles
            $table->text('identifiers_text')->nullable(); // comma-separated identifiers
            $table->text('address_text')->nullable(); // combined address
            $table->string('primary_role')->nullable();
            $table->string('primary_identifier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'primary_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_search_index');
    }
};
