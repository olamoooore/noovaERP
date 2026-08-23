<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // Add a json 'barcodes' column on product_variants to mirror
            // products.barcodes. Product::findSellableByBarcodeOrSku()
            // queries both products.barcodes and product_variants.barcodes
            // via orWhereJsonContains; without this column the query
            // throws "Undefined column: 7 ERROR: column barcodes does
            // not exist" against the tenant schema.
            if (! Schema::hasColumn('product_variants', 'barcodes')) {
                $table->json('barcodes')->nullable()->after('barcode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('product_variants', 'barcodes')) {
                $table->dropColumn('barcodes');
            }
        });
    }
};
