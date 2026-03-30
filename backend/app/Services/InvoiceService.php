<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
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
        private PostingEngineInterface $postingEngine,
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
     * Post invoice (creates journal entry with COGS, reduces inventory)
     */
    public function postInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new \Exception('Only draft invoices can be posted');
        }

        DB::beginTransaction();
        
        try {
            // ── Build posting lines ──────────────────────────
            $lines = [];

            // DR Accounts Receivable (total)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: (float) $invoice->total,
                credit: 0,
                description: "Invoice {$invoice->invoice_number}",
            );

            // CR Sales Revenue (subtotal)
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: 0,
                credit: (float) $invoice->subtotal,
                description: "Sales - Invoice {$invoice->invoice_number}",
            );

            // CR Sales Tax Payable
            if ((float) $invoice->tax_amount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: (float) $invoice->tax_amount,
                    description: "Tax - Invoice {$invoice->invoice_number}",
                );
            }

            // ── COGS lines for inventory items ───────────────
            $totalCogs = 0;
            foreach ($invoice->lines as $line) {
                if ($line->product && $line->product->track_inventory) {
                    $unitCost = (float) ($line->product->cost ?? 0);
                    $lineCogs = $unitCost * $line->quantity;

                    if ($lineCogs > 0) {
                        $totalCogs += $lineCogs;
                    }
                }
            }

            if ($totalCogs > 0) {
                // DR Cost of Goods Sold
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'cogs',
                    accountId: null,
                    debit: $totalCogs,
                    credit: 0,
                    description: "COGS - Invoice {$invoice->invoice_number}",
                );

                // CR Inventory
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'inventory',
                    accountId: null,
                    debit: 0,
                    credit: $totalCogs,
                    description: "Inventory - Invoice {$invoice->invoice_number}",
                );
            }

            // ── Post via PostingEngine ───────────────────────
            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'invoice',
                documentId: $invoice->id,
                postingDate: $invoice->invoice_date->toDateString(),
                description: "Invoice #{$invoice->invoice_number} - {$invoice->customer->name}",
                lines: $lines,
                reference: $invoice->invoice_number,
                userId: auth()->id(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            $invoice->update([
                'status' => 'sent',
                'sent_at' => now(),
                'journal_entry_id' => $result->journalEntryId,
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

            // Post to accounting ledger via PostingEngine (replaces AccountingService::postPayment)
            $lines = [];

            // DR Cash/Bank
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'cash',
                accountId: null,
                debit: (float) $payment->amount,
                credit: 0,
                description: "Payment {$payment->payment_number}",
            );

            // CR Accounts Receivable
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_receivable',
                accountId: null,
                debit: 0,
                credit: (float) $payment->amount,
                description: "Payment received - {$payment->payment_number}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment',
                documentId: $payment->id,
                postingDate: $payment->payment_date instanceof \DateTimeInterface
                    ? $payment->payment_date->format('Y-m-d')
                    : (string) $payment->payment_date,
                description: "Payment #{$payment->payment_number} - {$invoice->customer->name}",
                lines: $lines,
                reference: $payment->payment_number,
                userId: auth()->id(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            $payment->update([
                'journal_entry_id' => $result->journalEntryId,
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
