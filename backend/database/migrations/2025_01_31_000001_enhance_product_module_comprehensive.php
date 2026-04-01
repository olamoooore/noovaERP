<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive Product Module Enhancement Migration
 * Adds sophisticated ERP features for products, suppliers, bundles, tracking, and more
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: products is a tenant table — skip when running central-only migrations
        if (!Schema::hasTable('products')) {
            return;
        }

        // 1. Enhance products table with new fields
        Schema::table('products', function (Blueprint $table) {
            // Status workflow
            if (!Schema::hasColumn('products', 'status')) {
                $table->string('status', 30)->default('active')->after('is_active');
            }
            if (!Schema::hasColumn('products', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }
            
            // Physical attributes
            if (!Schema::hasColumn('products', 'weight')) {
                $table->decimal('weight', 10, 3)->nullable()->after('unit');
            }
            if (!Schema::hasColumn('products', 'length')) {
                $table->decimal('length', 10, 2)->nullable()->after('weight');
            }
            if (!Schema::hasColumn('products', 'width')) {
                $table->decimal('width', 10, 2)->nullable()->after('length');
            }
            if (!Schema::hasColumn('products', 'height')) {
                $table->decimal('height', 10, 2)->nullable()->after('width');
            }
            if (!Schema::hasColumn('products', 'dimensional_weight')) {
                $table->decimal('dimensional_weight', 10, 3)->nullable()->after('height');
            }
            
            // Hazmat & storage
            if (!Schema::hasColumn('products', 'is_hazmat')) {
                $table->boolean('is_hazmat')->default(false)->after('dimensional_weight');
            }
            if (!Schema::hasColumn('products', 'hazmat_class')) {
                $table->string('hazmat_class', 20)->nullable()->after('is_hazmat');
            }
            if (!Schema::hasColumn('products', 'storage_conditions')) {
                $table->string('storage_conditions')->nullable()->after('hazmat_class');
            }
            if (!Schema::hasColumn('products', 'is_fragile')) {
                $table->boolean('is_fragile')->default(false)->after('storage_conditions');
            }
            if (!Schema::hasColumn('products', 'shelf_life_days')) {
                $table->integer('shelf_life_days')->nullable()->after('is_fragile');
            }
            
            // Tracking method
            if (!Schema::hasColumn('products', 'tracking_method')) {
                $table->enum('tracking_method', ['none', 'quantity', 'batch', 'serial'])->default('quantity')->after('track_inventory');
            }
            
            // Multi-barcode support
            if (!Schema::hasColumn('products', 'barcodes')) {
                $table->json('barcodes')->nullable()->after('barcode');
            }
            
            // Inventory thresholds
            if (!Schema::hasColumn('products', 'safety_stock')) {
                $table->integer('safety_stock')->nullable()->after('reorder_level');
            }
            if (!Schema::hasColumn('products', 'max_stock')) {
                $table->integer('max_stock')->nullable()->after('safety_stock');
            }
            if (!Schema::hasColumn('products', 'reorder_quantity')) {
                $table->integer('reorder_quantity')->nullable()->after('max_stock');
            }
            
            // Pricing fields
            if (!Schema::hasColumn('products', 'wholesale_price')) {
                $table->decimal('wholesale_price', 12, 2)->nullable()->after('price');
            }
            if (!Schema::hasColumn('products', 'msrp')) {
                $table->decimal('msrp', 12, 2)->nullable()->after('wholesale_price');
            }
            if (!Schema::hasColumn('products', 'min_price')) {
                $table->decimal('min_price', 12, 2)->nullable()->after('msrp');
            }
            if (!Schema::hasColumn('products', 'max_price')) {
                $table->decimal('max_price', 12, 2)->nullable()->after('min_price');
            }
            
            // Compliance
            if (!Schema::hasColumn('products', 'hs_code')) {
                $table->string('hs_code', 20)->nullable();
            }
            if (!Schema::hasColumn('products', 'country_of_origin')) {
                $table->string('country_of_origin', 2)->nullable();
            }
            if (!Schema::hasColumn('products', 'warranty_months')) {
                $table->integer('warranty_months')->nullable();
            }
            if (!Schema::hasColumn('products', 'warranty_type')) {
                $table->string('warranty_type', 50)->nullable();
            }
            if (!Schema::hasColumn('products', 'certifications')) {
                $table->json('certifications')->nullable();
            }
            
            // SEO
            if (!Schema::hasColumn('products', 'meta_title')) {
                $table->string('meta_title')->nullable();
            }
            if (!Schema::hasColumn('products', 'meta_description')) {
                $table->text('meta_description')->nullable();
            }
            if (!Schema::hasColumn('products', 'meta_keywords')) {
                $table->string('meta_keywords')->nullable();
            }
            
            // Digital product fields
            if (!Schema::hasColumn('products', 'is_digital')) {
                $table->boolean('is_digital')->default(false);
            }
            if (!Schema::hasColumn('products', 'is_subscription')) {
                $table->boolean('is_subscription')->default(false);
            }
            if (!Schema::hasColumn('products', 'download_url')) {
                $table->string('download_url')->nullable();
            }
            if (!Schema::hasColumn('products', 'license_key')) {
                $table->string('license_key')->nullable();
            }
            
            // Bundle fields
            if (!Schema::hasColumn('products', 'is_bundle')) {
                $table->boolean('is_bundle')->default(false);
            }
            if (!Schema::hasColumn('products', 'bundle_type')) {
                $table->enum('bundle_type', ['fixed', 'dynamic', 'customizable'])->nullable();
            }
            
            // Manufacturer
            if (!Schema::hasColumn('products', 'manufacturer')) {
                $table->string('manufacturer')->nullable();
            }
            if (!Schema::hasColumn('products', 'manufacturer_part_number')) {
                $table->string('manufacturer_part_number', 100)->nullable();
            }
            
            // Localization
            if (!Schema::hasColumn('products', 'localized_names')) {
                $table->json('localized_names')->nullable();
            }
            if (!Schema::hasColumn('products', 'localized_descriptions')) {
                $table->json('localized_descriptions')->nullable();
            }
            
            // Approval workflow
            if (!Schema::hasColumn('products', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('products', 'discontinued_by')) {
                $table->foreignId('discontinued_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('products', 'discontinued_at')) {
                $table->timestamp('discontinued_at')->nullable();
            }
            
            // Index for status
            $table->index(['status', 'is_active']);
        });
        
        // 2. Create suppliers table
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 50)->unique();
                $table->string('contact_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('website')->nullable();
                $table->string('address_line_1')->nullable();
                $table->string('address_line_2')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('state', 100)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('country', 2)->nullable();
                $table->string('tax_id', 50)->nullable();
                $table->integer('payment_terms_days')->default(30);
                $table->string('currency', 3)->default('USD');
                $table->integer('default_lead_time_days')->default(7);
                $table->decimal('minimum_order_value', 12, 2)->nullable();
                $table->decimal('quality_rating', 3, 2)->nullable();
                $table->decimal('delivery_rating', 3, 2)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                
                $table->index(['is_active', 'name']);
            });
        }
        
        // 3. Create product_suppliers pivot table
        if (!Schema::hasTable('product_suppliers')) {
            Schema::create('product_suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
                $table->string('supplier_sku', 100)->nullable();
                $table->string('supplier_barcode', 100)->nullable();
                $table->decimal('cost', 12, 2);
                $table->decimal('last_cost', 12, 2)->nullable();
                $table->integer('lead_time_days')->default(7);
                $table->integer('moq')->default(1)->comment('Minimum Order Quantity');
                $table->integer('order_multiple')->default(1);
                $table->integer('priority')->default(5);
                $table->boolean('is_preferred')->default(false);
                $table->date('last_purchase_date')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->unique(['product_id', 'supplier_id']);
                $table->index(['is_preferred', 'is_active']);
            });
        }
        
        // 4. Create product_uoms table
        if (!Schema::hasTable('product_uoms')) {
            Schema::create('product_uoms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('name', 50);
                $table->string('abbreviation', 10);
                $table->decimal('conversion_factor', 12, 4)->default(1);
                $table->boolean('is_base_unit')->default(false);
                $table->boolean('is_purchase_unit')->default(false);
                $table->boolean('is_sales_unit')->default(false);
                $table->string('barcode', 50)->nullable();
                $table->decimal('price_multiplier', 8, 4)->default(1);
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['product_id', 'is_base_unit']);
            });
        }
        
        // 5. Create product_bundles table
        if (!Schema::hasTable('product_bundles')) {
            Schema::create('product_bundles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('component_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->integer('quantity')->default(1);
                $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
                $table->decimal('discount_value', 12, 2)->default(0);
                $table->boolean('is_optional')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                
                $table->unique(['bundle_product_id', 'component_product_id', 'component_variant_id'], 'bundle_component_unique');
            });
        }
        
        // 6. Create product_relationships table
        if (!Schema::hasTable('product_relationships')) {
            Schema::create('product_relationships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
                $table->enum('type', ['cross_sell', 'up_sell', 'substitute', 'accessory', 'complementary']);
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->unique(['product_id', 'related_product_id', 'type'], 'product_relationship_unique');
            });
        }
        
        // 7. Create product_price_tiers table
        if (!Schema::hasTable('product_price_tiers')) {
            Schema::create('product_price_tiers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('customer_group')->nullable();
                $table->integer('min_quantity')->default(1);
                $table->integer('max_quantity')->nullable();
                $table->enum('discount_type', ['percentage', 'fixed', 'fixed_price'])->default('percentage');
                $table->decimal('discount_value', 12, 2);
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                
                $table->index(['product_id', 'is_active']);
                $table->index(['valid_from', 'valid_until']);
            });
        }
        
        // 8. Create product_batches table
        if (!Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->string('batch_number', 100);
                $table->string('lot_number', 100)->nullable();
                $table->integer('quantity');
                $table->integer('quantity_reserved')->default(0);
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->date('received_date')->nullable();
                $table->string('supplier_batch_number', 100)->nullable();
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('cost', 12, 2)->nullable();
                $table->enum('status', ['available', 'quarantine', 'expired', 'recalled'])->default('available');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                
                $table->index(['product_id', 'status']);
                $table->index(['expiry_date', 'status']);
                $table->unique(['product_id', 'batch_number']);
            });
        }
        
        // 9. Create product_serials table
        if (!Schema::hasTable('product_serials')) {
            Schema::create('product_serials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->string('serial_number', 100)->unique();
                $table->string('mac_address', 20)->nullable();
                $table->string('imei', 20)->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('warranty_start_date')->nullable();
                $table->date('warranty_end_date')->nullable();
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('cost', 12, 2)->nullable();
                $table->enum('status', ['available', 'reserved', 'sold', 'returned', 'defective', 'scrapped'])->default('available');
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('sold_at')->nullable();
                $table->string('sale_reference', 100)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                
                $table->index(['product_id', 'status']);
                $table->index(['warranty_end_date']);
            });
        }
        
        // 10. Create product_audit_logs table
        if (!Schema::hasTable('product_audit_logs')) {
            Schema::create('product_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 50);
                $table->string('field')->nullable();
                $table->text('old_value')->nullable();
                $table->text('new_value')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent')->nullable();
                $table->timestamp('created_at');
                
                $table->index(['product_id', 'created_at']);
                $table->index(['action', 'created_at']);
            });
        }
        
        // 11. Create product_documents table
        if (!Schema::hasTable('product_documents')) {
            Schema::create('product_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->enum('type', ['manual', 'specification', 'certificate', 'safety_sheet', 'datasheet', 'image', 'video', 'other']);
                $table->string('name');
                $table->string('file_path');
                $table->string('file_name');
                $table->string('mime_type', 100)->nullable();
                $table->bigInteger('file_size')->nullable();
                $table->string('language', 5)->default('en');
                $table->date('valid_until')->nullable();
                $table->boolean('is_public')->default(false);
                $table->integer('sort_order')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                
                $table->index(['product_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        // Drop new tables in reverse order
        Schema::dropIfExists('product_documents');
        Schema::dropIfExists('product_audit_logs');
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_price_tiers');
        Schema::dropIfExists('product_relationships');
        Schema::dropIfExists('product_bundles');
        Schema::dropIfExists('product_uoms');
        Schema::dropIfExists('product_suppliers');
        Schema::dropIfExists('suppliers');
        
        // Remove columns from products table
        Schema::table('products', function (Blueprint $table) {
            $columns = [
                'status', 'slug', 'weight', 'length', 'width', 'height', 'dimensional_weight',
                'is_hazmat', 'hazmat_class', 'storage_conditions', 'is_fragile', 'shelf_life_days',
                'tracking_method', 'barcodes', 'safety_stock', 'max_stock', 'reorder_quantity',
                'wholesale_price', 'msrp', 'min_price', 'max_price',
                'hs_code', 'country_of_origin', 'warranty_months', 'warranty_type', 'certifications',
                'meta_title', 'meta_description', 'meta_keywords',
                'is_digital', 'is_subscription', 'download_url', 'license_key',
                'is_bundle', 'bundle_type',
                'manufacturer', 'manufacturer_part_number',
                'localized_names', 'localized_descriptions',
                'approved_by', 'approved_at', 'discontinued_by', 'discontinued_at'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
