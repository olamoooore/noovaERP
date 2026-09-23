<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Issue #155: Payment.invoice_id is a single FK, so one payment could never be
 * split across invoices or held as unapplied credit. payment_applications
 * makes the payment→invoice application explicit and many-to-many, while the
 * legacy invoice_id remains as the primary/back-compat pointer.
 *
 * Backfills one application row per existing payment that already carries an
 * invoice_id, so the allocation ledger is authoritative from day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_applications')) {
            Schema::create('payment_applications', function (Blueprint $table) {
                $table->id();
                $table->uuid('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('payment_id');
                $table->unsignedBigInteger('invoice_id');
                $table->decimal('applied_amount', 15, 2);
                $table->string('currency_code', 3)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('payment_id')->references('id')->on('payments')->cascadeOnDelete();
                $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
                $table->index(['invoice_id', 'payment_id']);
            });
        }

        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'invoice_id')) {
            return;
        }

        // Idempotent backfill: existing payments with an invoice link become a
        // single application for their full amount.
        DB::table('payments')
            ->select('id', 'tenant_id', 'invoice_id', 'amount', 'currency', 'received_by')
            ->whereNotNull('invoice_id')
            ->where('amount', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($payments) {
                foreach ($payments as $payment) {
                    $exists = DB::table('payment_applications')
                        ->where('payment_id', $payment->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('payment_applications')->insert([
                        'tenant_id' => $payment->tenant_id,
                        'payment_id' => $payment->id,
                        'invoice_id' => $payment->invoice_id,
                        'applied_amount' => $payment->amount,
                        'currency_code' => $payment->currency,
                        'created_by' => $payment->received_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_applications');
    }
};
