<?php

namespace App\Platform\EventBus\Handlers;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\AccountingCustomerProfile;
use Modules\Contact\src\Models\AccountingVendorProfile;
use Modules\Contact\src\Models\CoreContact;

class AccountingContactSyncHandler implements EventHandlerContract
{
    public function __construct(private SystemLogServiceContract $systemLog) {}

    public function handle(DomainEventContract $event): void
    {
        $payload = $event->getPayload();
        $eventName = $event->getEventName();
        $contactId = $payload['contact_id'] ?? null;

        if (!$contactId) {
            return;
        }

        match ($eventName) {
            'contact.role.changed' => $this->handleRoleChange($payload),
            'contact.created' => $this->handleContactCreated($contactId),
            'contact.updated' => $this->handleContactUpdated($contactId, $payload),
            'contact.deleted' => $this->handleContactDeleted($contactId),
            default => null,
        };

        $this->systemLog->log(
            'info',
            'accounting.contact_sync',
            "Contact event {$eventName} processed by Accounting",
            [
                'contact_id' => $contactId,
                'event_name' => $eventName,
                'correlation_id' => $event->getCorrelationId(),
            ],
            $event->getCorrelationId()
        );
    }

    private function handleRoleChange(array $payload): void
    {
        $contactId = $payload['contact_id'];
        $role = $payload['role'] ?? '';
        $action = $payload['action'] ?? '';

        if ($action === 'added') {
            if (in_array($role, ['customer', 'accounting_customer'])) {
                $this->provisionCustomerProfile($contactId);
            }
            if (in_array($role, ['vendor', 'supplier', 'accounting_vendor'])) {
                $this->provisionVendorProfile($contactId);
            }
        }

        if ($action === 'removed') {
            if (in_array($role, ['customer', 'accounting_customer'])) {
                $this->deactivateProfile(AccountingCustomerProfile::class, $contactId);
            }
            if (in_array($role, ['vendor', 'supplier', 'accounting_vendor'])) {
                $this->deactivateProfile(AccountingVendorProfile::class, $contactId);
            }
        }
    }

    private function handleContactCreated(string $contactId): void
    {
        $contact = CoreContact::find($contactId);
        if (!$contact) {
            return;
        }

        $roles = $contact->roles()->pluck('role')->toArray();

        if (in_array('customer', $roles) || in_array('accounting_customer', $roles)) {
            $this->provisionCustomerProfile($contactId);
        }
        if (in_array('vendor', $roles) || in_array('supplier', $roles) || in_array('accounting_vendor', $roles)) {
            $this->provisionVendorProfile($contactId);
        }
    }

    private function handleContactUpdated(string $contactId, array $payload): void
    {
        $newValue = $payload['new_value'] ?? [];

        AccountingCustomerProfile::where('contact_id', $contactId)
            ->where('account_status', 'active')
            ->update(['metadata' => array_merge(
                AccountingCustomerProfile::where('contact_id', $contactId)->value('metadata') ?? [],
                ['last_synced_at' => now()->toIso8601String(), 'contact_display_name' => $newValue['display_name'] ?? null]
            )]);

        AccountingVendorProfile::where('contact_id', $contactId)
            ->where('account_status', 'active')
            ->update(['metadata' => array_merge(
                AccountingVendorProfile::where('contact_id', $contactId)->value('metadata') ?? [],
                ['last_synced_at' => now()->toIso8601String(), 'contact_display_name' => $newValue['display_name'] ?? null]
            )]);
    }

    private function handleContactDeleted(string $contactId): void
    {
        $this->deactivateProfile(AccountingCustomerProfile::class, $contactId);
        $this->deactivateProfile(AccountingVendorProfile::class, $contactId);
    }

    private function provisionCustomerProfile(string $contactId): void
    {
        $existing = AccountingCustomerProfile::where('contact_id', $contactId)->first();
        if ($existing) {
            return;
        }

        AccountingCustomerProfile::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'contact_id' => $contactId,
            'customer_code' => 'CUST-' . strtoupper(substr($contactId, 0, 8)),
            'payment_terms' => 30,
            'credit_limit' => 0,
            'current_balance' => 0,
            'currency' => 'MYR',
            'account_opened_date' => now(),
            'account_status' => 'active',
        ]);

        Log::info("Accounting: Customer profile provisioned for contact {$contactId}");
    }

    private function provisionVendorProfile(string $contactId): void
    {
        $existing = AccountingVendorProfile::where('contact_id', $contactId)->first();
        if ($existing) {
            return;
        }

        AccountingVendorProfile::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'contact_id' => $contactId,
            'vendor_code' => 'VEND-' . strtoupper(substr($contactId, 0, 8)),
            'payment_terms' => 30,
            'credit_limit' => 0,
            'current_balance' => 0,
            'currency' => 'MYR',
            'account_opened_date' => now(),
            'account_status' => 'active',
        ]);

        Log::info("Accounting: Vendor profile provisioned for contact {$contactId}");
    }

    private function deactivateProfile(string $modelClass, string $contactId): void
    {
        $modelClass::where('contact_id', $contactId)
            ->where('account_status', 'active')
            ->update(['account_status' => 'inactive']);
    }
}
