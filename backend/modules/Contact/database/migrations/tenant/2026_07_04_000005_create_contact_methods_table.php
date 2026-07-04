<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('method_type', 50); // email, phone, mobile, fax, whatsapp, linkedin, portal_login, emergency
            $table->string('method_value');
            $table->string('method_label')->nullable(); // "Work Email", "Home Phone"
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_for_notifications')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'method_type']);
            $table->index(['tenant_id', 'method_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_methods');
    }
};
