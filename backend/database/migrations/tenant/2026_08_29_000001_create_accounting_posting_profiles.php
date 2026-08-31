<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-005 §12 — versioned posting profile catalog.
     *
     * Operational domains may not submit unrestricted debit/credit lines.
     * Every posting must be resolved through a versioned posting profile
     * mapped via source_capability + posting_profile_code.
     */
    public function up(): void
    {
        Schema::create('accounting_posting_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('entity_id')->nullable()->index();
            $table->uuid('accounting_book_id')->nullable()->index();
            $table->string('source_capability', 32)->index();   // ACC-003, ACC-004, ACC-005A, etc.
            $table->string('posting_profile_code', 64)->index(); // e.g. AR_INVOICE_POST
            $table->string('version', 16)->default('1.0');       // monotonically increasing per (capability, profile)
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('mappings'); // { RECEIVABLE: { account_role, direction }, REVENUE: {...}, ... }
            $table->json('control_account_requirements')->nullable();
            $table->json('validation_requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            // One active profile per (tenant, entity, book, capability, code)
            $table->unique(
                ['tenant_id', 'entity_id', 'accounting_book_id', 'source_capability', 'posting_profile_code', 'version'],
                'app_unique_version'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_posting_profiles');
    }
};
