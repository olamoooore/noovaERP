<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->string('contact_type', 50)->default('individual'); // individual, company
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('display_name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('mobile')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->string('source')->nullable(); // manual, import, api, migration
            $table->string('legacy_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_duplicate')->default(false);
            $table->uuid('merged_into_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entity_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'contact_type']);
            $table->index(['tenant_id', 'display_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_contacts');
    }
};
