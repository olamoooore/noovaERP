<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant: Payments (posts to ledger, updates invoice)
     */
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            $hasTenantShape = Schema::hasColumn('payments', 'customer_id')
                && Schema::hasColumn('payments', 'payment_date')
                && Schema::hasColumn('payments', 'received_by')
                && Schema::hasColumn('payments', 'method');

            if ($hasTenantShape) {
                return;
            }

            // In tests, central-schema migrations may have already created a different
            // `payments` table (subscription billing). Replace it so tenant migrations
            // can build the canonical tenant AR payments schema expected by reports/APIs.
            if (app()->environment('testing') && Schema::hasColumn('payments', 'tenant_id')) {
                if (Schema::hasTable('payment_attempts')) {
                    Schema::dropIfExists('payment_attempts');
                }
                Schema::dropIfExists('payments');
            } else {
                throw new \RuntimeException(
                    "Existing 'payments' table is incompatible with tenant payments schema."
                );
            }
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique(); // PAY-2024-0001
            $table->foreignId('customer_id')->constrained()->onDelete('restrict');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('restrict');
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'check', 'other'])->default('cash');
            $table->string('reference')->nullable(); // Check #, Transaction ID
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('payment_number');
            $table->index('customer_id');
            $table->index('invoice_id');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
