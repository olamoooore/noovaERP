<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('registration_type', 50); // tax_id, vat_number, gst_number, company_registration, business_license
            $table->string('registration_value');
            $table->string('issuing_country', 2)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'registration_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_registrations');
    }
};
