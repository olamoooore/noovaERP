<?php

namespace Modules\FieldService\Services;

use Illuminate\Support\Facades\Log;
use Modules\FieldService\Models\WorkOrder;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * Sends WhatsApp notifications to the customer at key Work Order lifecycle events.
 *
 * The service is intentionally lenient — a missing API key or send failure
 * will be logged but never throws, so it never disrupts the core WO flow.
 */
class FieldServiceNotificationService
{
    public function __construct(private WhatsAppService $whatsApp) {}

    /**
     * WO status → 'scheduled': notify customer of appointment.
     */
    public function notifyScheduled(WorkOrder $workOrder): void
    {
        $phone = $this->resolvePhone($workOrder);
        if (! $phone) {
            return;
        }

        $date = $workOrder->scheduled_start
            ? $workOrder->scheduled_start->format('d M Y \a\t H:i')
            : 'TBD';
        $techName = $workOrder->leadTechnician?->user?->name ?? 'our technician';

        $message = "Hello {$this->customerName($workOrder)},\n\n"
            ."Your field service appointment has been scheduled for *{$date}*.\n"
            ."Technician: *{$techName}*\n"
            ."Reference: {$workOrder->reference}\n\n"
            ."We'll send you an update when our technician is on the way.";

        $this->send($phone, $message, $workOrder);
    }

    /**
     * WO status → 'in_progress': technician is on the way.
     */
    public function notifyDispatched(WorkOrder $workOrder): void
    {
        $phone = $this->resolvePhone($workOrder);
        if (! $phone) {
            return;
        }

        $techName = $workOrder->leadTechnician?->user?->name ?? 'Our technician';

        $message = "Hello {$this->customerName($workOrder)},\n\n"
            ."*{$techName}* is on the way to your location!\n"
            ."Reference: {$workOrder->reference}\n\n"
            .'Please ensure access is available at the site.';

        $this->send($phone, $message, $workOrder);
    }

    /**
     * WO status → 'completed': job done, report available.
     */
    public function notifyCompleted(WorkOrder $workOrder): void
    {
        $phone = $this->resolvePhone($workOrder);
        if (! $phone) {
            return;
        }

        $message = "Hello {$this->customerName($workOrder)},\n\n"
            ."Your service job (*{$workOrder->reference}*) has been *completed*. ✅\n"
            ."A signed service report has been sent to your email.\n\n"
            .'Thank you for choosing us!';

        $this->send($phone, $message, $workOrder);
    }

    // ──────────────── Private helpers ─────────────────────────────────────────

    private function resolvePhone(WorkOrder $workOrder): ?string
    {
        $workOrder->loadMissing(['customer', 'leadTechnician.user']);

        return $workOrder->customer?->phone ?? null;
    }

    private function customerName(WorkOrder $workOrder): string
    {
        return $workOrder->customer?->name ?? 'Valued Customer';
    }

    private function send(string $phone, string $message, WorkOrder $workOrder): void
    {
        try {
            // Use the first active API key available
            $apiKey = WhatsAppApiKey::where('is_active', true)
                ->latest()
                ->first();

            if (! $apiKey) {
                Log::info('FieldServiceNotification: no active WhatsApp API key configured — skip.', [
                    'work_order' => $workOrder->reference,
                ]);

                return;
            }

            $result = $this->whatsApp->sendMessage($apiKey, $phone, $message);

            if (! ($result['success'] ?? false)) {
                Log::warning('FieldServiceNotification: WhatsApp send failed', [
                    'work_order' => $workOrder->reference,
                    'phone' => $phone,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('FieldServiceNotification: exception during WhatsApp send', [
                'work_order' => $workOrder->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
