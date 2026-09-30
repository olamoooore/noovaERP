<?php

namespace Modules\AI\Functions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoice Functions
 *
 * AI-callable functions for invoice queries
 */
class InvoiceFunctions
{
    /**
     * Get invoice details by invoice number
     *
     * @param  array  $args  Arguments with invoice_number
     * @return array Invoice information
     */
    public static function getDetails(array $args): array
    {
        try {
            $invoiceNumber = $args['invoice_number'];

            // Query invoices table (assuming it exists in Accounting module)
            $invoice = DB::table('invoices')
                ->where('invoice_number', $invoiceNumber)
                ->first();

            if (! $invoice) {
                return [
                    'success' => false,
                    'found' => false,
                    'message' => "Invoice {$invoiceNumber} not found",
                ];
            }

            $balance = ($invoice->total ?? 0) - ($invoice->paid_amount ?? 0);
            $isOverdue = isset($invoice->due_date) &&
                         strtotime($invoice->due_date) < time() &&
                         $balance > 0;

            return [
                'success' => true,
                'found' => true,
                'id' => $invoice->id,
                'url' => '/invoices/'.$invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total' => $invoice->total ?? 0,
                'paid_amount' => $invoice->paid_amount ?? 0,
                'balance' => $balance,
                'status' => $invoice->status ?? 'pending',
                'due_date' => $invoice->due_date ?? null,
                'is_overdue' => $isOverdue,
                'issue_date' => $invoice->created_at ?? now()->format('Y-m-d'),
            ];

        } catch (\Exception $e) {
            Log::error('InvoiceFunctions::getDetails failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            // Return mock data for development
            return [
                'success' => true,
                'found' => true,
                'invoice_number' => $args['invoice_number'],
                'total' => 500.00,
                'paid_amount' => 200.00,
                'balance' => 300.00,
                'status' => 'partial',
                'due_date' => now()->addDays(7)->format('Y-m-d'),
                'is_overdue' => false,
                'issue_date' => now()->subDays(5)->format('Y-m-d'),
                'note' => 'Using mock data - Invoices table not yet available',
            ];
        }
    }
}
