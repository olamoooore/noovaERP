<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_visibility', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('visibility_scope', 50); // tenant, entity, department, restricted
            $table->uuid('scope_id')->nullable(); // entity_id or department_id
            $table->string('access_level', 50)->default('read'); // read, write, admin
            $table->boolean('is_restricted')->default(false);
            $table->text('restriction_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'visibility_scope', 'scope_id'], 'contact_visibility_unique');
            $table->index(['tenant_id', 'visibility_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_visibility');
    }
};
