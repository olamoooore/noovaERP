<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #665 EI3: per-product LHDN classification code and UN/ECE unit code, so an
 * e-invoice line is not always sent as classification 001 / unit C62.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'einvoice_classification_code')) {
                $table->string('einvoice_classification_code', 20)->nullable()->after('unit');
            }

            if (! Schema::hasColumn('products', 'einvoice_unit_code')) {
                $table->string('einvoice_unit_code', 10)->nullable()->after('einvoice_classification_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['einvoice_classification_code', 'einvoice_unit_code']);
        });
    }
};
