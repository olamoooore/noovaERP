<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_hierarchy_closure', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('ancestor_id')->index();
            $table->uuid('descendant_id')->index();
            $table->integer('depth')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'ancestor_id', 'descendant_id'], 'contact_hierarchy_unique');
            $table->index(['tenant_id', 'descendant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_hierarchy_closure');
    }
};
