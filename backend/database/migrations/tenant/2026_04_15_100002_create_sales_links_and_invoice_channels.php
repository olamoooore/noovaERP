<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_orders') && Schema::hasTable('invoices')) {
            Schema::create('sales_invoice_links', function (Blueprint $table) {
                $table->id();

                $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');
                $table->foreignId('invoice_id')->constrained('invoices')->onDelete('restrict');

                $table->enum('link_type', ['primary', 'supplementary', 'credit'])->default('primary');
                $table->enum('link_state', ['active', 'superseded', 'reversed'])->default('active');

                $table->decimal('invoiced_qty', 15, 4)->default(0);
                $table->decimal('invoiced_value', 15, 2)->default(0);

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['sales_order_id', 'invoice_id'], 'uq_sales_invoice_link');
                $table->index('invoice_id');
                $table->index('link_state');
            });

            Schema::create('sales_channel_references', function (Blueprint $table) {
                $table->id();

                $table->foreignId('sales_order_id')->constrained('sales_orders')->onDelete('cascade');
                $table->enum('channel', ['pos', 'ecommerce', 'admin'])->index();

                $table->string('external_id')->index();
                $table->string('external_number')->nullable();
                $table->json('raw_document_ids')->nullable();

                $table->timestamps();

                $table->unique(['channel', 'external_id'], 'uq_sales_channel_ref_external');
                $table->index(['sales_order_id', 'channel']);
            });
        }

        if (! Schema::hasTable('invoices')) {
            return;
        }

        if (! Schema::hasColumn('invoices', 'pos_order_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('pos_order_id')->nullable()->after('journal_entry_id');
                $table->index('pos_order_id');
            });
            if (Schema::hasTable('pos_orders')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->foreign('pos_order_id')->references('id')->on('pos_orders')->onDelete('set null');
                });
            }
        }

        if (! Schema::hasColumn('invoices', 'web_order_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('web_order_id')->nullable()->after('pos_order_id');
                $table->index('web_order_id');
            });
            if (Schema::hasTable('web_orders')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->foreign('web_order_id')->references('id')->on('web_orders')->onDelete('set null');
                });
            }
        }

        if (! Schema::hasColumn('invoices', 'sales_order_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_order_id')->nullable()->after('web_order_id');
                $table->index('sales_order_id');
            });
            if (Schema::hasTable('sales_orders')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->foreign('sales_order_id')->references('id')->on('sales_orders')->onDelete('set null');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            if (Schema::hasColumn('invoices', 'pos_order_id')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropForeign(['pos_order_id']);
                    $table->dropColumn('pos_order_id');
                });
            }
            if (Schema::hasColumn('invoices', 'web_order_id')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropForeign(['web_order_id']);
                    $table->dropColumn('web_order_id');
                });
            }
            if (Schema::hasColumn('invoices', 'sales_order_id')) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropForeign(['sales_order_id']);
                    $table->dropColumn('sales_order_id');
                });
            }
        }

        Schema::dropIfExists('sales_channel_references');
        Schema::dropIfExists('sales_invoice_links');
    }
};
