<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\CustomerFollowupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #328: manual follow-up reminders — from the follow-up report (one
 * customer's overdue invoices) and from the invoice detail page.
 */
class CustomerFollowupController extends Controller
{
    public function __construct(private CustomerFollowupService $followups) {}

    public function sendForCustomer(Request $request, $customer): JsonResponse
    {
        $customer = Customer::find($customer);

        // A customer outside the caller's legal entity answers like a
        // missing one (no existence oracle).
        $entityId = $request->attributes->get('legal_entity_id');
        if (! $customer || ($entityId !== null && $customer->legal_entity_id !== null && (int) $customer->legal_entity_id !== (int) $entityId)) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        try {
            $results = $this->followups->sendForCustomer($customer, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sent = count(array_filter($results, fn (array $r) => $r['outcome'] === CustomerFollowupService::OUTCOME_SENT));
        $failed = count(array_filter($results, fn (array $r) => $r['outcome'] === CustomerFollowupService::OUTCOME_FAILED));

        // Per-invoice outcomes, like Bulk Send; when every attempt failed the
        // request itself failed.
        return response()->json([
            'message' => "{$sent} of ".count($results).' reminder(s) sent.',
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
                'not_sent' => count($results) - $sent,
                'results' => $results,
            ],
        ], $failed > 0 && $failed === count($results) ? 500 : 200);
    }

    public function sendForInvoice(Request $request, $invoice): JsonResponse
    {
        $invoice = Invoice::find($invoice);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        try {
            $result = $this->followups->sendForInvoice($invoice, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => match ($result['outcome']) {
                CustomerFollowupService::OUTCOME_SENT => 'Reminder sent.',
                CustomerFollowupService::OUTCOME_SKIPPED => 'Reminder not sent: '.$result['reason'],
                default => 'Reminder failed: '.($result['reason'] ?? 'unknown error'),
            },
            'data' => $result,
        ], $result['outcome'] === CustomerFollowupService::OUTCOME_FAILED ? 500 : 200);
    }
}
