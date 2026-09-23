<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #94: CapabilityRegistry declares ImpairmentRecord as owned by ACC-007
 * (Fixed Asset Accounting) but no impairment implementation existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impairment_records')) {
            return;
        }

        Schema::create('impairment_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('fixed_asset_id')->index();
            $table->date('impairment_date');
            $table->decimal('carrying_amount_before', 15, 2);
            $table->decimal('recoverable_amount', 15, 2);
            $table->decimal('impairment_loss', 15, 2);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('fixed_asset_id')->references('id')->on('fixed_assets')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impairment_records');
    }
};
