<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1001: uploaded customer payment advices (HSBC/UOB/DBS/SCB PDFs and
 * screenshots), the parsed lines with their invoice matches, and the
 * review-before-posting state. The uploaded file itself is retained on disk
 * and linked to the payments it produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_advice_imports')) {
            Schema::create('payment_advice_imports', function (Blueprint $table) {
                $table->id();
                $table->string('original_name');
                $table->string('file_path');
                $table->string('mime_type', 100)->nullable();
                $table->string('bank_code', 30)->default('unknown');
                $table->string('extractor', 40)->default('unknown');
                $table->string('status', 20)->default('draft');
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('payer_name')->nullable();
                $table->date('value_date')->nullable();
                $table->string('currency_code', 3)->nullable();
                $table->decimal('total_amount', 15, 2)->nullable();
                $table->decimal('extracted_total', 15, 2)->nullable();
                $table->decimal('variance', 15, 2)->nullable();
                $table->string('bank_reference')->nullable();
                $table->json('lines');
                $table->json('suggestions')->nullable();
                $table->json('warnings')->nullable();
                $table->json('payment_ids')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('status');
                $table->index('bank_code');
            });
        }

        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'payment_advice_import_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('payment_advice_import_id')->nullable()->after('invoice_id')
                    ->constrained('payment_advice_imports')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'payment_advice_import_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_advice_import_id');
            });
        }

        Schema::dropIfExists('payment_advice_imports');
    }
};
