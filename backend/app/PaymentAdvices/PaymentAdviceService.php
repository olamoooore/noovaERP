<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use App\Models\Invoice;
use App\Models\PaymentAdviceImport;
use App\Services\InvoiceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * #1001: upload → extract → parse → match, then confirm the reviewed
 * allocations. Confirmation only creates draft payments through
 * InvoiceService::recordPayment (never a direct ledger write); posting stays
 * with the operator on the Payments page.
 */
class PaymentAdviceService
{
    public function __construct(
        private readonly PaymentAdviceTextExtractor $extractor,
        private readonly PaymentAdviceParserRegistry $registry,
        private readonly PaymentAdviceMatchingService $matcher,
        private readonly InvoiceService $invoices,
    ) {}

    public function createFromUpload(UploadedFile $file, int $userId): PaymentAdviceImport
    {
        $path = $file->store('payment-advices/'.date('Y/m'), 'local');

        if ($path === false) {
            throw new \RuntimeException('Could not store the payment advice file.');
        }

        $extracted = $this->extractor->extract(
            Storage::disk('local')->path($path),
            $file->getMimeType(),
            $file->getClientOriginalName(),
        );

        $advice = $this->registry->parse($extracted['text']);
        $match = $this->matcher->match($advice);

        return PaymentAdviceImport::create([
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'bank_code' => $advice->bankCode,
            'extractor' => $extracted['extractor'],
            'status' => 'draft',
            'customer_id' => $match['customer_id'],
            'payer_name' => $advice->payerName,
            'value_date' => $advice->valueDate,
            'currency_code' => $advice->currency,
            'total_amount' => $advice->totalAmount,
            'extracted_total' => $match['extracted_total'],
            'variance' => $match['variance'],
            'bank_reference' => $advice->bankReference,
            'lines' => $match['lines'],
            'suggestions' => $match['suggestions'],
            'warnings' => $match['warnings'],
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  array<int, array{line_index: int, invoice_id: int, amount: float}>  $allocations
     */
    public function confirm(PaymentAdviceImport $import, array $allocations, int $userId): PaymentAdviceImport
    {
        if ($import->status === 'confirmed') {
            throw ValidationException::withMessages([
                'status' => 'This payment advice is already confirmed.',
            ]);
        }

        return DB::transaction(function () use ($import, $allocations, $userId) {
            $lines = $import->lines ?? [];
            $paymentIds = $import->payment_ids ?? [];

            foreach ($allocations as $allocation) {
                $index = (int) $allocation['line_index'];

                if (! array_key_exists($index, $lines)) {
                    throw ValidationException::withMessages([
                        'allocations' => "Unknown advice line {$index}.",
                    ]);
                }

                $invoice = Invoice::query()->findOrFail((int) $allocation['invoice_id']);
                $amount = round((float) $allocation['amount'], 2);

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Allocation amounts must be positive.',
                    ]);
                }

                if ($amount > (float) $invoice->balance + 0.01) {
                    throw ValidationException::withMessages([
                        'allocations' => "Allocation for invoice {$invoice->invoice_number} exceeds its outstanding balance.",
                    ]);
                }

                // Draft payment through the one payment path — the operator
                // posts it deliberately from the Payments page.
                $payment = $this->invoices->recordPayment($invoice, [
                    'amount' => $amount,
                    'payment_date' => $import->value_date?->toDateString() ?? now()->toDateString(),
                    'method' => 'bank_transfer',
                    'reference' => $import->bank_reference,
                    'notes' => "Payment advice #{$import->id} ({$import->bank_code})",
                    'payment_advice_import_id' => $import->id,
                ], postImmediately: false);

                $paymentIds[] = (int) $payment->id;

                $lines[$index]['status'] = 'confirmed';
                $lines[$index]['confirmed_invoice_id'] = (int) $invoice->id;
                $lines[$index]['confirmed_amount'] = $amount;
                $lines[$index]['payment_id'] = (int) $payment->id;
            }

            $import->update([
                'lines' => $lines,
                'payment_ids' => $paymentIds,
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            return $import->fresh();
        });
    }
}
