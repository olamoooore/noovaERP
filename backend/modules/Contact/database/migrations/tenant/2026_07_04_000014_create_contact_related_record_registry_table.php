<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_related_record_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('module_key', 50); // accounting, crm, sales, inventory, pos, hr, property, project
            $table->string('record_type', 50); // invoice, bill, order, opportunity, ticket, etc.
            $table->uuid('record_id');
            $table->string('record_label')->nullable();
            $table->decimal('record_amount', 15, 2)->nullable();
            $table->string('record_currency', 3)->nullable();
            $table->date('record_date')->nullable();
            $table->string('record_status', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'contact_id', 'module_key']);
            $table->index(['tenant_id', 'module_key', 'record_type']);
            $table->index(['tenant_id', 'contact_id', 'record_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_related_record_registry');
    }
};
