<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_loyalty_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('membership_id')->nullable();
            $table->string('membership_tier', 50)->default('standard');
            $table->integer('loyalty_points')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->date('membership_start_date')->nullable();
            $table->date('membership_expiry_date')->nullable();
            $table->decimal('total_spend', 15, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->date('last_purchase_date')->nullable();
            $table->json('preferences')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'membership_id']);
            $table->index(['tenant_id', 'membership_tier']);
        });

        Schema::create('property_contact_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('contact_role', 50); // tenant, owner, guarantor, emergency
            $table->uuid('property_id')->nullable()->index();
            $table->uuid('unit_id')->nullable()->index();
            $table->date('lease_start_date')->nullable();
            $table->date('lease_end_date')->nullable();
            $table->decimal('monthly_rent', 15, 2)->nullable();
            $table->decimal('security_deposit', 15, 2)->nullable();
            $table->string('payment_schedule', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'contact_role', 'unit_id'], 'property_profile_unique');
            $table->index(['tenant_id', 'contact_role']);
        });

        Schema::create('project_contact_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('contact_role', 50); // client, contractor, subcontractor, consultant
            $table->uuid('project_id')->nullable()->index();
            $table->string('contract_number')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'contact_role', 'project_id'], 'project_profile_unique');
            $table->index(['tenant_id', 'contact_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contact_profiles');
        Schema::dropIfExists('property_contact_profiles');
        Schema::dropIfExists('pos_loyalty_profiles');
    }
};
