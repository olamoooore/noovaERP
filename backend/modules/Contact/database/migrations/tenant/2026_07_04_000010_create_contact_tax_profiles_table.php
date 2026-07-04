<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tax_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('tax_profile_type', 50); // customer_tax, vendor_tax, withholding
            $table->string('tax_classification', 50)->nullable(); // registered, exempt, non_registered
            $table->string('tax_id')->nullable();
            $table->string('tax_id_type', 50)->nullable(); // tin, vat, gst
            $table->decimal('withholding_tax_rate', 5, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->text('exemption_reason')->nullable();
            $table->date('exemption_expiry')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'tax_profile_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tax_profiles');
    }
};
