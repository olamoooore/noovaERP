<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('group_name');
            $table->string('group_type', 50)->default('custom'); // custom, system, tag, category
            $table->text('description')->nullable();
            $table->uuid('parent_group_id')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'group_name'], 'contact_group_unique');
        });

        Schema::create('contact_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->uuid('group_id')->index();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'group_id'], 'contact_group_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_group_members');
        Schema::dropIfExists('contact_groups');
    }
};
