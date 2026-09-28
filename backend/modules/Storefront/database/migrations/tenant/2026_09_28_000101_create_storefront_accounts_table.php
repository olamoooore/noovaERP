<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_accounts — the BUYER AUTH identity (decision D2, Appendix E §E1):
 * auth fields live here, NOT on CRM `customers`; linked 1:1 by email on
 * signup/guest-order-claim. Email uniqueness is case-insensitive and
 * per-tenant (the tenant schema IS the tenant boundary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->nullOnDelete();
            $table->string('email', 255);
            $table->string('password');
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('marketing_email_opt_in')->default(false);
            $table->boolean('marketing_sms_opt_in')->default(false);
            $table->boolean('marketing_whatsapp_opt_in')->default(false);
            $table->string('consent_source', 60)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("CREATE UNIQUE INDEX storefront_accounts_email_unique ON \"{$schema}\".storefront_accounts (lower(email)) WHERE deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_accounts');
    }
};
