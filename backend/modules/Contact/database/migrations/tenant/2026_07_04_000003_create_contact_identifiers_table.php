<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_identifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('identifier_type', 50); // customer_id, vendor_id, staff_id, membership_id, legacy_id, external_ref, tax_id, registration_number
            $table->string('identifier_value');
            $table->string('identifier_label')->nullable();
            $table->string('source_system')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'identifier_type', 'identifier_value'], 'contact_identifier_unique');
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'identifier_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_identifiers');
    }
};
