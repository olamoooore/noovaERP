<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #338 ([SALES-ODOO]): quotation templates + optional quotation lines.
 *
 * Additive only — new tables and new nullable/defaulted columns. Existing
 * quotation lines default to is_optional = false, so every existing total
 * is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            // Days from the quotation date; NULL = use the tenant default
            // (Sales Settings → Quotations), 0 = no expiry.
            $table->unsignedInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('quotation_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_template_id')->constrained('quotation_templates')->cascadeOnDelete();
            $table->string('line_type', 20)->default('product');
            $table->integer('sequence')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('description');
            $table->text('product_notes')->nullable();
            $table->decimal('quantity', 15, 4)->nullable();
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->boolean('is_optional')->default(false);
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('quotation_template_id')->nullable()->constrained('quotation_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quotation_template_id');
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropColumn('is_optional');
        });

        Schema::dropIfExists('quotation_template_lines');
        Schema::dropIfExists('quotation_templates');
    }
};
