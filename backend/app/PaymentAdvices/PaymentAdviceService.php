<?php

declare(strict_types=1);

namespace App\PaymentAdvices;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAdviceImport;
use App\Services\InvoiceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /**
     * Invoice statuses that are open receivables a payment may settle (the
     * posted family in Invoice's settlement sync); the balance check decides
     * whether anything is still outstanding.
     */
    public const OPEN_INVOICE_STATUSES = ['posted', 'sent', 'partial', 'overdue'];

    public function createFromUpload(UploadedFile $file, int $userId): PaymentAdviceImport
    {
        $path = $file->store('payment-advices/'.date('Y/m'), 'local');

        if ($path === false) {
            throw new \RuntimeException('Could not store the payment advice file.');
        }

        try {
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
        } catch (RequestException|ConnectionException $e) {
            // The OCR hop failed: nothing was parsed, so don't keep the file
            // as an orphan with no import row. The failure is reported (not
            // swallowed) and the caller gets an explicit 503.
            Storage::disk('local')->delete($path);
            report($e);

            throw new HttpException(
                503,
                'OCR service unavailable — the advice was not processed. Try again shortly, or upload the PDF version if you have it.',
                $e,
            );
        } catch (\Throwable $e) {
            // Any other failure before the import row exists: remove the
            // stored file and let the original error propagate.
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * @param  array<int, array{line_index: int, invoice_id: int, amount: float}>  $allocations
     */
    public function confirm(PaymentAdviceImport $import, array $allocations, int $userId): PaymentAdviceImport
    {
        return DB::transaction(function () use ($import, $allocations, $userId) {
            // Lock the advice row and re-read its state inside the transaction
            // so two concurrent confirms can't both pass the status check.
            /** @var PaymentAdviceImport $import */
            $import = PaymentAdviceImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();

            if ($import->status === 'confirmed') {
                throw ValidationException::withMessages([
                    'status' => 'This payment advice is already confirmed.',
                ]);
            }

            $lines = $import->lines ?? [];
            $paymentIds = $import->payment_ids ?? [];

            $resolved = $this->validateAllocations($import, $lines, $allocations);

            foreach ($resolved as ['index' => $index, 'invoice' => $invoice, 'amount' => $amount]) {
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

                if (array_key_exists($index, $lines)) {
                    $lines[$index]['status'] = 'confirmed';
                    $lines[$index]['confirmed_invoice_id'] = (int) $invoice->id;
                    $lines[$index]['confirmed_amount'] = $amount;
                    $lines[$index]['payment_id'] = (int) $payment->id;
                }
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

    /**
     * Validate the whole allocation set before any payment is written:
     * known/unique lines, the advice's customer, open invoices, per-invoice
     * totals (including unposted draft payments) within the balance, and the
     * grand total within the remitted amount.
     *
     * A no-reference advice (no extracted lines) allocates against its
     * suggestions; those use line_index 0.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array{line_index: int, invoice_id: int, amount: float}>  $allocations
     * @return array<int, array{index: int, invoice: Invoice, amount: float}>
     */
    private function validateAllocations(PaymentAdviceImport $import, array $lines, array $allocations): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['allocations' => $message]);

        $seenLines = [];
        $perInvoice = [];
        $invoices = [];
        $resolved = [];
        $total = 0.0;
        $noReferenceAdvice = $lines === [];

        foreach ($allocations as $allocation) {
            $index = (int) $allocation['line_index'];

            if ($noReferenceAdvice ? $index !== 0 : ! array_key_exists($index, $lines)) {
                $fail("Unknown advice line {$index}.");
            }

            if (! $noReferenceAdvice && isset($seenLines[$index])) {
                $fail('Advice line '.($index + 1).' is allocated more than once.');
            }
            $seenLines[$index] = true;

            $amount = round((float) $allocation['amount'], 2);
            if ($amount <= 0) {
                $fail('Allocation amounts must be positive.');
            }

            $invoiceId = (int) $allocation['invoice_id'];
            if (! isset($invoices[$invoiceId])) {
                // Lock the invoice so a parallel confirm/draft can't race the
                // balance check below.
                $invoices[$invoiceId] = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first()
                    ?? $fail("Invoice {$invoiceId} was not found.");
            }
            $invoice = $invoices[$invoiceId];

            if ($import->customer_id !== null && (int) $invoice->customer_id !== (int) $import->customer_id) {
                $fail("Invoice {$invoice->invoice_number} belongs to a different customer than this payment advice.");
            }

            if (! in_array($invoice->status, self::OPEN_INVOICE_STATUSES, true)) {
                $fail("Invoice {$invoice->invoice_number} is {$invoice->status}, not an open receivable.");
            }

            $perInvoice[$invoiceId] = round(($perInvoice[$invoiceId] ?? 0) + $amount, 2);
            $total = round($total + $amount, 2);
            $resolved[] = ['index' => $index, 'invoice' => $invoice, 'amount' => $amount];
        }

        foreach ($perInvoice as $invoiceId => $allocated) {
            $invoice = $invoices[$invoiceId];
            // #477: draft payments don't move the invoice balance until they
            // are posted, so earmarked drafts are counted here explicitly.
            $drafts = round((float) Payment::query()->draft()->where('invoice_id', $invoiceId)->sum('amount'), 2);
            $available = round((float) $invoice->balance - $drafts, 2);

            if ($allocated > $available + 0.01) {
                $fail(sprintf(
                    'Allocations for invoice %s total %s, more than its open balance of %s%s.',
                    $invoice->invoice_number,
                    number_format($allocated, 2),
                    number_format(max($available, 0), 2),
                    $drafts > 0 ? ' (after '.number_format($drafts, 2).' in unposted draft payments)' : '',
                ));
            }
        }

        if ($import->total_amount !== null && $total > (float) $import->total_amount + 0.01) {
            $fail(sprintf(
                'Allocations total %s, more than the %s remitted on this advice.',
                number_format($total, 2),
                number_format((float) $import->total_amount, 2),
            ));
        }

        return $resolved;
    }
}
