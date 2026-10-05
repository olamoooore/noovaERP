<?php

namespace Modules\Sales\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\Notifications\NotificationService;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\AutomationExecution;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\AutomationCatalog;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * AutomationActionExecutor — runs the whitelisted #913 rule actions.
 *
 * Every action returns a result array `{type, status, error?, detail?}` that
 * is persisted verbatim on the automation_executions row. A failing action is
 * never swallowed: WhatsApp/webhook failures, missing phone numbers, missing
 * recipients and blocked webhook hosts are all visible on the execution log.
 */
class AutomationActionExecutor
{
    /** Notification preference event used by the `notify` action. */
    public const NOTIFY_EVENT = 'sales.automation';

    public const WEBHOOK_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly WhatsAppService $whatsApp,
        private readonly \App\Services\Chatter\ChatterService $chatter,
        private readonly SystemLogServiceContract $systemLog,
        private readonly AutomationWebhookGuard $webhookGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function execute(array $action, AutomationRule $rule, DomainEventContract $event, AutomationExecution $execution): array
    {
        $type = (string) ($action['type'] ?? 'unknown');

        try {
            return match ($type) {
                AutomationCatalog::ACTION_NOTIFY => $this->notify($action, $rule, $event, $execution),
                AutomationCatalog::ACTION_WHATSAPP_CUSTOMER => $this->whatsappCustomer($action, $rule, $event),
                AutomationCatalog::ACTION_CREATE_TASK => $this->createTask($action, $rule, $event),
                AutomationCatalog::ACTION_HOLD_CUSTOMER => $this->holdCustomer($action, $rule, $event, $execution),
                AutomationCatalog::ACTION_WEBHOOK => $this->webhook($action, $rule, $event, $execution),
                default => $this->result($type, 'failed', 'Unsupported action type.'),
            };
        } catch (\Throwable $e) {
            Log::error('Sales automation action threw', [
                'rule_id' => $rule->id,
                'execution_id' => $execution->id,
                'action_type' => $type,
                'error' => $e->getMessage(),
            ]);

            return $this->result($type, 'failed', $e->getMessage());
        }
    }

    // ── Actions ───────────────────────────────────────────────────────────

    /**
     * Notify explicit users / users holding roles / the customer's account
     * owner. Delivery goes through the platform NotificationService, so each
     * recipient's tenant notification preferences are respected.
     */
    private function notify(array $action, AutomationRule $rule, DomainEventContract $event, AutomationExecution $execution): array
    {
        $payload = $event->getPayload();
        $recipients = $this->resolveRecipients($action, $payload);

        if ($recipients->isEmpty()) {
            return $this->result(AutomationCatalog::ACTION_NOTIFY, 'failed', 'No deliverable recipients could be resolved.');
        }

        $subject = $this->render($action['subject'] ?? null, $payload, $rule)
            ?? "Automation: {$rule->name}";
        $body = $this->render($action['message'] ?? null, $payload, $rule)
            ?? "Sales automation rule \"{$rule->name}\" matched {$event->getEventName()}.";

        $delivered = [];

        foreach ($recipients as $user) {
            $this->notifications->dispatch(self::NOTIFY_EVENT, $user, [
                'subject' => $subject,
                'body' => $body,
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'execution_id' => $execution->id,
                'event_name' => $event->getEventName(),
                'document_number' => $payload['document_number'] ?? null,
                'total' => $payload['total'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
            ]);

            $delivered[] = (int) $user->id;
        }

        return $this->result(AutomationCatalog::ACTION_NOTIFY, 'success', null, [
            'recipients' => $delivered,
            'event' => self::NOTIFY_EVENT,
        ]);
    }

    /**
     * WhatsApp the customer. Requires a customer with a phone number, an
     * active tenant WhatsApp API key, and a preferred-contact channel that is
     * not explicitly email/sms. Every miss is a visible failure/skip.
     */
    private function whatsappCustomer(array $action, AutomationRule $rule, DomainEventContract $event): array
    {
        $type = AutomationCatalog::ACTION_WHATSAPP_CUSTOMER;
        $payload = $event->getPayload();
        $customer = $this->customer($payload);

        if (! $customer) {
            return $this->result($type, 'failed', 'The event has no customer to message.');
        }

        $preferred = strtolower(trim((string) ($customer->preferred_contact ?? '')));
        $respectPreferences = ! array_key_exists('respect_preferences', $action)
            || (bool) $action['respect_preferences'];

        if ($respectPreferences && in_array($preferred, ['email', 'sms'], true)) {
            return $this->result($type, 'skipped', null, [
                'reason' => "Customer prefers {$preferred}; WhatsApp not sent (disable respect_preferences on the action to override).",
                'customer_id' => (int) $customer->id,
            ]);
        }

        $phone = trim((string) ($customer->phone ?: ($customer->mobile ?? '')));

        if ($phone === '') {
            return $this->result($type, 'failed', "Customer #{$customer->id} has no phone number for WhatsApp.");
        }

        $apiKey = $this->activeWhatsAppKey();

        if (! $apiKey) {
            return $this->result($type, 'failed', 'No active WhatsApp API key is configured for this tenant.');
        }

        $message = $this->render($action['message'] ?? null, $payload, $rule)
            ?? "Hello {$customer->name}, this is a reminder about {$event->getEventName()}.";
        $message = trim($message);

        if ($message === '') {
            return $this->result($type, 'failed', 'The WhatsApp message resolved to an empty body.');
        }

        $response = $this->whatsApp->sendMessage($apiKey, $phone, $message);
        $ok = (bool) ($response['success'] ?? false);

        if (! $ok) {
            return $this->result($type, 'failed', (string) ($response['error'] ?? 'WhatsApp send failed'), [
                'customer_id' => (int) $customer->id,
                'phone' => $phone,
            ]);
        }

        return $this->result($type, 'success', null, [
            'customer_id' => (int) $customer->id,
            'phone' => $phone,
            'message_id' => $response['message_id'] ?? null,
        ]);
    }

    /**
     * Create a chatter task on the event's document (or on the customer when
     * the event has no document subject). Reuses ChatterService::addActivity —
     * no parallel task store.
     */
    private function createTask(array $action, AutomationRule $rule, DomainEventContract $event): array
    {
        $type = AutomationCatalog::ACTION_CREATE_TASK;
        $payload = $event->getPayload();
        [$subjectType, $subjectId] = $this->resolveTaskSubject($payload);

        if ($subjectType === null || $subjectId === null) {
            return $this->result($type, 'failed', 'The event has no document or customer to attach the task to.');
        }

        $summary = trim((string) $this->render($action['summary'] ?? null, $payload, $rule));

        if ($summary === '') {
            $summary = "Follow up {$event->getEventName()}";
        }

        $dueInDays = $action['due_in_days'] ?? null;

        $activity = $this->chatter->addActivity($subjectType, $subjectId, [
            'activity_type' => 'todo',
            'summary' => $summary,
            'note' => $this->render($action['note'] ?? null, $payload, $rule),
            'due_date' => is_numeric($dueInDays) ? now()->addDays((int) $dueInDays)->toDateString() : null,
            'assigned_to_id' => isset($action['assignee_user_id']) && is_numeric($action['assignee_user_id'])
                ? (int) $action['assignee_user_id']
                : null,
        ]);

        return $this->result($type, 'success', null, [
            'activity_id' => (int) $activity->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'summary' => $summary,
        ]);
    }

    /**
     * Put the customer on hold. The flag is explicit on the customer row
     * (default off) and the change is audited twice: the shared
     * AuditTrailObserver records the model field change, and an explicit
     * system-log entry records which rule/execution caused it. A held
     * customer fails CreditCheckService, which blocks confirming new orders.
     */
    private function holdCustomer(array $action, AutomationRule $rule, DomainEventContract $event, AutomationExecution $execution): array
    {
        $type = AutomationCatalog::ACTION_HOLD_CUSTOMER;
        $payload = $event->getPayload();
        $customer = $this->customer($payload);

        if (! $customer) {
            return $this->result($type, 'failed', 'The event has no customer to put on hold.');
        }

        $reason = trim((string) ($action['reason'] ?? ''));
        $reason = $reason !== '' ? $reason : "Automation rule \"{$rule->name}\"";

        $customer->update([
            'on_hold' => true,
            'hold_reason' => $reason,
            'held_at' => $customer->held_at ?? now(),
        ]);

        $this->systemLog->log(
            'info',
            'automation.customer_held',
            "Customer #{$customer->id} placed on hold by automation rule #{$rule->id}",
            [
                'rule_id' => (int) $rule->id,
                'rule_name' => $rule->name,
                'execution_id' => (int) $execution->id,
                'event_name' => $event->getEventName(),
                'customer_id' => (int) $customer->id,
                'reason' => $reason,
            ]
        );

        return $this->result($type, 'success', null, [
            'customer_id' => (int) $customer->id,
            'reason' => $reason,
        ]);
    }

    /**
     * POST the event JSON to the configured webhook. The host must pass the
     * SSRF guard; the request has a 10s timeout and 3 attempts, and every
     * attempt is recorded on the action result.
     */
    private function webhook(array $action, AutomationRule $rule, DomainEventContract $event, AutomationExecution $execution): array
    {
        $type = AutomationCatalog::ACTION_WEBHOOK;
        $url = (string) ($action['url'] ?? '');

        try {
            $endpoint = $this->webhookGuard->resolve($url);
        } catch (\Throwable $e) {
            return $this->result($type, 'failed', 'Webhook blocked: '.$e->getMessage(), ['url' => $url]);
        }

        $body = [
            'event' => array_merge($event->getPayload(), [
                'event_name' => $event->getEventName(),
                'occurred_at' => $event->getOccurredAt(),
            ]),
            'rule' => [
                'id' => (int) $rule->id,
                'name' => $rule->name,
                'version' => (int) $rule->version,
                'legal_entity_id' => (int) $rule->legal_entity_id,
            ],
            'execution' => [
                'id' => (int) $execution->id,
                'event_id' => $execution->event_id,
            ],
        ];

        $attempts = [];
        $lastError = null;

        for ($attempt = 1; $attempt <= self::WEBHOOK_MAX_ATTEMPTS; $attempt++) {
            try {
                $options = [];

                // Pin the validated address for this connect so the DNS answer
                // cannot change between the guard and the request (rebinding).
                if (defined('CURLOPT_RESOLVE')) {
                    $options['curl'] = [
                        CURLOPT_RESOLVE => ["{$endpoint['host']}:{$endpoint['port']}:{$endpoint['ip']}"],
                    ];
                }

                $response = Http::timeout(10)
                    ->acceptJson()
                    ->withHeaders([
                        'X-NoovaERP-Event' => $event->getEventName(),
                        'X-NoovaERP-Delivery' => (string) $execution->id,
                    ])
                    ->withOptions($options)
                    ->post($url, $body);

                $attempts[] = ['attempt' => $attempt, 'status' => $response->status()];

                if ($response->successful()) {
                    return $this->result($type, 'success', null, [
                        'url' => $url,
                        'attempts' => $attempts,
                    ]);
                }

                $lastError = "HTTP {$response->status()}";
            } catch (\Throwable $e) {
                $attempts[] = ['attempt' => $attempt, 'error' => $e->getMessage()];
                $lastError = $e->getMessage();
            }

            if ($attempt < self::WEBHOOK_MAX_ATTEMPTS) {
                usleep(50_000);
            }
        }

        return $this->result($type, 'failed', 'Webhook delivery failed: '.($lastError ?? 'unknown error'), [
            'url' => $url,
            'attempts' => $attempts,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveRecipients(array $action, array $payload): \Illuminate\Support\Collection
    {
        $users = collect();

        if (! empty($action['user_ids']) && is_array($action['user_ids'])) {
            $users = $users->merge(User::query()->whereIn('id', array_map('intval', $action['user_ids']))->get());
        }

        if (! empty($action['roles']) && is_array($action['roles'])) {
            $spatieNames = collect($action['roles'])
                ->map(fn ($role) => \App\Enums\UserRole::tryFrom(strtolower((string) $role))?->spatieName())
                ->filter()
                ->values()
                ->all();

            if ($spatieNames !== []) {
                $users = $users->merge(User::role($spatieNames)->get());
            }
        }

        if (! empty($action['account_owner'])) {
            $customer = $this->customer($payload);

            if ($customer && $customer->salesperson_id) {
                $owner = User::query()->find($customer->salesperson_id);

                if ($owner) {
                    $users->push($owner);
                }
            }
        }

        return $users
            ->filter(fn (User $user) => $user->is_active !== false)
            ->unique('id')
            ->values();
    }

    private function customer(array $payload): ?Customer
    {
        $customerId = $payload['customer_id'] ?? null;

        if (! $customerId || ! Schema::hasTable('customers')) {
            return null;
        }

        return Customer::query()->find($customerId);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveTaskSubject(array $payload): array
    {
        $documentId = isset($payload['document_id']) && is_numeric($payload['document_id'])
            ? (int) $payload['document_id']
            : null;

        $map = [
            'invoice' => Invoice::class,
            'quotation' => Quotation::class,
            'sales_order' => SalesOrder::class,
            'delivery_order' => DeliveryOrder::class,
            'credit_note' => \App\Models\CustomerCreditNote::class,
            'payment' => Payment::class,
        ];

        $documentType = (string) ($payload['document_type'] ?? '');

        if ($documentId !== null && isset($map[$documentType])) {
            return [$map[$documentType], $documentId];
        }

        $customerId = isset($payload['customer_id']) && is_numeric($payload['customer_id'])
            ? (int) $payload['customer_id']
            : null;

        if ($customerId !== null) {
            return [Customer::class, $customerId];
        }

        return [null, null];
    }

    private function activeWhatsAppKey(): ?WhatsAppApiKey
    {
        if (! Schema::hasTable('whatsapp_api_keys')) {
            return null;
        }

        return WhatsAppApiKey::query()->valid()->latest('id')->first();
    }

    /**
     * Replace `:placeholder` tokens in a message/description. Unknown tokens
     * are left untouched; a null template stays null (caller supplies default).
     */
    private function render(?string $template, array $payload, AutomationRule $rule): ?string
    {
        if ($template === null || $template === '') {
            return $template;
        }

        $customer = $this->customer($payload);

        return strtr($template, [
            ':rule_name' => $rule->name,
            ':event_name' => $rule->event_name,
            ':document_number' => (string) ($payload['document_number'] ?? ''),
            ':total' => isset($payload['total']) && is_numeric($payload['total'])
                ? number_format((float) $payload['total'], 2, '.', '')
                : '',
            ':balance' => isset($payload['balance']) && is_numeric($payload['balance'])
                ? number_format((float) $payload['balance'], 2, '.', '')
                : '',
            ':currency' => (string) ($payload['currency_code'] ?? ''),
            ':customer_name' => (string) ($customer?->name ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $type, string $status, ?string $error = null, array $detail = []): array
    {
        return array_filter([
            'type' => $type,
            'status' => $status,
            'error' => $error,
            'detail' => $detail !== [] ? $detail : null,
        ], fn ($value) => $value !== null);
    }
}
