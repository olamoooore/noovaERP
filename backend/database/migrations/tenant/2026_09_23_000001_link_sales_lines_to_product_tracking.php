<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained('product_batches')->nullOnDelete();
            $table->foreignId('product_serial_id')->nullable()->after('product_batch_id')->constrained('product_serials')->nullOnDelete();
            $table->string('serial_number', 100)->nullable()->after('lot_number');
            $table->index(['product_id', 'product_batch_id']);
            $table->index(['product_id', 'product_serial_id']);
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->foreignId('product_batch_id')->nullable()->after('product_id')->constrained('product_batches')->nullOnDelete();
            $table->foreignId('product_serial_id')->nullable()->after('product_batch_id')->constrained('product_serials')->nullOnDelete();
            $table->string('serial_number', 100)->nullable()->after('batch_number');
            $table->index(['product_id', 'product_batch_id']);
            $table->index(['product_id', 'product_serial_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'product_batch_id']);
            $table->dropIndex(['product_id', 'product_serial_id']);
            $table->dropConstrainedForeignId('product_serial_id');
            $table->dropConstrainedForeignId('product_batch_id');
            $table->dropColumn('serial_number');
        });

        Schema::table('delivery_order_lines', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'product_batch_id']);
            $table->dropIndex(['product_id', 'product_serial_id']);
            $table->dropConstrainedForeignId('product_serial_id');
            $table->dropConstrainedForeignId('product_batch_id');
            $table->dropColumn('serial_number');
        });
    }
};
