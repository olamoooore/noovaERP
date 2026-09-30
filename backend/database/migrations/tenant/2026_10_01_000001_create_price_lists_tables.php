<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd §8.1 — general price-list engine tables.
 *
 * Replaces the two-field-of-logic `customer_price_lists` model with Odoo-parity
 * price lists: currency per list, ordered rules (top-down, first match wins),
 * fixed / percentage / formula compute types, min-qty tiers, validity windows,
 * per-list rounding and a discount display policy.
 *
 * `legacy_price_list_id` is the bridge column used by the legacy migration and
 * by `customers.price_list_id` re-pointing; the legacy tables are kept during
 * the deprecation window (FR-12.1) and never touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_lists')) {
            Schema::create('price_lists', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('code', 50)->nullable()->unique();
                $table->string('currency_code', 3);
                $table->text('description')->nullable();
                $table->integer('sequence')->default(10);
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('rounding_enabled')->default(false);
                $table->string('rounding_method', 10)->default('nearest');
                $table->decimal('rounding_precision', 12, 4)->default(0.01);
                // 'show_discounted_price' is 21 chars — the MRD draft's varchar(20)
                // could not hold its own default value; widened to 25.
                $table->string('discount_display', 25)->default('show_discounted_price');
                $table->unsignedBigInteger('legacy_price_list_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
                $table->index(['is_active', 'sequence']);
            });

            DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_rounding_method_check CHECK (rounding_method IN ('up','down','nearest'))");
            DB::statement("ALTER TABLE price_lists ADD CONSTRAINT price_lists_discount_display_check CHECK (discount_display IN ('show_discount','show_discounted_price'))");
        }

        if (! Schema::hasTable('price_list_items')) {
            Schema::create('price_list_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_list_id');
                $table->integer('sequence')->default(10);
                $table->string('apply_on', 15)->default('product');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->decimal('min_quantity', 15, 4)->default(1);
                $table->decimal('max_quantity', 15, 4)->nullable();
                $table->date('date_start')->nullable();
                $table->date('date_end')->nullable();
                $table->string('compute_type', 12);
                $table->decimal('fixed_price', 15, 4)->nullable();
                $table->decimal('percent', 8, 4)->nullable();
                $table->string('base', 15)->nullable();
                $table->unsignedBigInteger('other_price_list_id')->nullable();
                $table->decimal('markup_percent', 8, 4)->nullable();
                // Additive engine capability beyond the MRD draft schema:
                // legacy blanket "fixed amount off" discounts (discount_type
                // = 'fixed' on customer_price_lists) cannot be expressed as a
                // percentage. The new model reproduces them losslessly.
                $table->decimal('fixed_discount', 15, 4)->nullable();
                $table->timestamps();

                $table->foreign('price_list_id')->references('id')->on('price_lists')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('category_id')->references('id')->on('product_categories')->cascadeOnDelete();
                $table->foreign('other_price_list_id')->references('id')->on('price_lists')->nullOnDelete();
                $table->index(['price_list_id', 'sequence']);
            });

            DB::statement("ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_apply_on_check CHECK (apply_on IN ('product','category','all'))");
            DB::statement("ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_compute_type_check CHECK (compute_type IN ('fixed','percentage','formula'))");
            DB::statement("ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_base_check CHECK (base IS NULL OR base IN ('list_price','cost','other_pricelist'))");
        }

        if (! Schema::hasTable('price_list_applications')) {
            Schema::create('price_list_applications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('price_list_id');
                $table->string('applies_to_type', 15)->default('all');
                // customer_group → customer_categories.id, customer → customers.id
                $table->unsignedBigInteger('applies_to_id')->nullable();
                $table->timestamps();

                $table->foreign('price_list_id')->references('id')->on('price_lists')->cascadeOnDelete();
                $table->unique(['price_list_id', 'applies_to_type', 'applies_to_id'], 'price_list_applications_unique');
            });

            DB::statement("ALTER TABLE price_list_applications ADD CONSTRAINT price_list_applications_type_check CHECK (applies_to_type IN ('all','customer_group','customer'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_applications');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
