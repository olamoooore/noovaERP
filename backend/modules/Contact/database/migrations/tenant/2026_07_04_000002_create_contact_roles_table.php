<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('role', 50); // customer, vendor, staff, member, contractor, supplier, lead, owner, resident, tenant
            $table->string('role_label')->nullable(); // custom label
            $table->uuid('entity_id')->nullable()->index();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'role', 'entity_id'], 'contact_role_unique');
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'role', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_roles');
    }
};
