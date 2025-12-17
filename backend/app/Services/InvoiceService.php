<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Invoice Service - Handles invoice workflow
 * Auto-posts to accounting ledger
 * Updates inventory
 */
class InvoiceService
{
    public function __construct(
        private AccountingService $accountingService
    ) {}

    /**
     * Create invoice with lines
     */
    public function createInvoice(array $data): Invoice
    {
        DB::beginTransaction();
        
        try {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['created_by'] = auth()->id();
            $data['invoice_date'] = $data['invoice_date'] ?? now();
            $data['due_date'] = $data['due_date'] ?? now()->addDays(30);
            $data['status'] = 'draft';

            $invoice = Invoice::create($data);

            // Add lines
            foreach ($lines as $lineData) {
                $invoice->lines()->create([
                    'product_id' => $lineData['product_id'] ?? null,
                    'description' => $lineData['description'],
                    'quantity' => $lineData['quantity'],
                    'unit_price' => $lineData['unit_price'],
                    'tax_rate' => $lineData['tax_rate'] ?? 0,
                    'discount_amount' => $lineData['discount_amount'] ?? 0,
                ]);
            }

            $invoice->refresh();
            $invoice->calculateTotals();

            DB::commit();
            
            return $invoice->load(['customer', 'lines.product']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post invoice (creates journal entry, reduces inventory)
     */
    public function postInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new \Exception('Only draft invoices can be posted');
        }

        DB::beginTransaction();
        
        try {
            // Post to accounting ledger
            $journalEntry = $this->accountingService->postInvoice($invoice);
            
            $invoice->update([
                'status' => 'sent',
                'sent_at' => now(),
                'journal_entry_id' => $journalEntry->id,
            ]);

            // Reduce inventory for product lines
            foreach ($invoice->lines as $line) {
                if ($line->product && $line->product->track_inventory) {
                    $line->product->adjustStock(
                        $line->quantity,
                        'out',
                        $invoice->invoice_number
                    );
                }
            }

            // Update customer outstanding
            $invoice->customer->updateOutstanding();

            DB::commit();
            
            return $invoice->fresh(['journalEntry', 'lines']);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Record payment against invoice
     */
    public function recordPayment(Invoice $invoice, array $paymentData): Payment
    {
        DB::beginTransaction();
        
        try {
            $paymentData['customer_id'] = $invoice->customer_id;
            $paymentData['invoice_id'] = $invoice->id;
            $paymentData['payment_date'] = $paymentData['payment_date'] ?? now();
            $paymentData['received_by'] = auth()->id();

            $payment = Payment::create($paymentData);

            // Post to accounting ledger
            $journalEntry = $this->accountingService->postPayment($payment);
            
            $payment->update([
                'journal_entry_id' => $journalEntry->id,
            ]);

            // Update invoice paid amount and balance
            $invoice->paid_amount += $payment->amount;
            $invoice->balance = $invoice->total - $invoice->paid_amount;
            
            if ($invoice->balance <= 0) {
                $invoice->status = 'paid';
            }
            
            $invoice->save();

            // Update customer outstanding
            $invoice->customer->updateOutstanding();

            DB::commit();
            
            return $payment->fresh('journalEntry');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
