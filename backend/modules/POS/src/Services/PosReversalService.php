<?php

namespace Modules\POS\Services;

use Illuminate\Support\Facades\Log;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;

class PosReversalService
{
    public function __construct(
        private readonly PosPostingService $posPostingService,
    ) {}

    public function createVoidRefundPayment(
        PosOrder $order,
        int $approvedBy,
        string $reason
    ): PosPayment {
        $idempotencyKey = "void_refund_{$order->id}";

        $existing = PosPayment::where('pos_order_id', $order->id)
            ->where('method', 'void_refund')
            ->where('reference', "VOID-{$order->order_number}")
            ->first();

        if ($existing) {
            return $existing;
        }

        $payment = PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => 'void_refund',
            'amount' => -$order->total,
            'reference' => "VOID-{$order->order_number}",
            'notes' => $reason,
            'received_by' => $approvedBy,
        ]);

        $this->postVoidRefundGL($order, $reason);

        Log::info('Void refund payment created via PosReversalService', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
        ]);

        return $payment;
    }

    public function createRefundPayment(
        PosOrder $order,
        string $refundMethod,
        int $approvedBy,
        string $reason
    ): PosPayment {
        $idempotencyKey = "refund_{$order->id}";

        $existing = PosPayment::where('pos_order_id', $order->id)
            ->where('method', $refundMethod)
            ->where('amount', -$order->total)
            ->where('reference', "REFUND-{$order->order_number}")
            ->first();

        if ($existing) {
            return $existing;
        }

        $payment = PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => $refundMethod,
            'amount' => -$order->total,
            'reference' => "REFUND-{$order->order_number}",
            'notes' => $reason,
            'received_by' => $approvedBy,
        ]);

        $this->postRefundPaymentGL($order, $refundMethod, $order->total, $reason);

        Log::info('Refund payment created via PosReversalService', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'refund_method' => $refundMethod,
        ]);

        return $payment;
    }

    public function createReturnPayment(
        PosOrder $order,
        string $refundMethod,
        float $returnTotal,
        int $approvedBy,
        string $notes,
        ?string $idempotencyKey = null
    ): PosPayment {
        $idempotencyKey = $idempotencyKey ?? "return_{$order->id}_".md5(json_encode(['method' => $refundMethod, 'total' => $returnTotal]));

        $existing = PosPayment::where('pos_order_id', $order->id)
            ->where('method', $refundMethod)
            ->where('amount', -$returnTotal)
            ->where('reference', "RETURN-{$order->order_number}")
            ->first();

        if ($existing) {
            return $existing;
        }

        $payment = PosPayment::create([
            'pos_order_id' => $order->id,
            'method' => $refundMethod,
            'amount' => -$returnTotal,
            'reference' => "RETURN-{$order->order_number}",
            'notes' => $notes,
            'received_by' => $approvedBy,
        ]);

        $this->postRefundPaymentGL($order, $refundMethod, $returnTotal, $notes);

        Log::info('Return payment created via PosReversalService', [
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'return_total' => $returnTotal,
        ]);

        return $payment;
    }

    private function postVoidRefundGL(PosOrder $order, string $reason): void
    {
        try {
            $journalEntry = $this->posPostingService->postRefundPayment(
                $order,
                'void_refund',
                $order->total,
                "Void refund payment for order #{$order->order_number}: {$reason}"
            );

            if ($journalEntry) {
                PosPayment::where('pos_order_id', $order->id)
                    ->where('reference', "VOID-{$order->order_number}")
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $journalEntry->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to post void refund GL entry', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function postRefundPaymentGL(
        PosOrder $order,
        string $refundMethod,
        float $refundAmount,
        string $description
    ): void {
        try {
            $journalEntry = $this->posPostingService->postRefundPayment(
                $order,
                $refundMethod,
                $refundAmount,
                "Refund payment for order #{$order->order_number}: {$description}"
            );

            if ($journalEntry) {
                PosPayment::where('pos_order_id', $order->id)
                    ->where('reference', "REFUND-{$order->order_number}")
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $journalEntry->id]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to post refund payment GL entry', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
