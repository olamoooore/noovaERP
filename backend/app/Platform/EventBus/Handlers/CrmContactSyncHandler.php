<?php

namespace App\Platform\EventBus\Handlers;

use App\Platform\EventBus\Contracts\DomainEventContract;
use App\Platform\EventBus\Contracts\EventHandlerContract;
use App\Platform\SystemLog\Contracts\SystemLogServiceContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Contact\src\Models\CoreContact;
use Modules\Contact\src\Models\CrmContactProfile;

class CrmContactSyncHandler implements EventHandlerContract
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
            'contact.profile.updated' => $this->handleProfileUpdated($contactId, $payload),
            'contact.deleted' => $this->handleContactDeleted($contactId),
            default => null,
        };

        $this->systemLog->log(
            'info',
            'crm.contact_sync',
            "Contact event {$eventName} processed by CRM",
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

        $crmRoles = ['lead', 'crm_lead', 'opportunity', 'account', 'crm_contact'];

        if ($action === 'added' && in_array($role, $crmRoles)) {
            $this->provisionCrmProfile($contactId, $role);
        }

        if ($action === 'removed' && in_array($role, $crmRoles)) {
            $this->deactivateCrmProfile($contactId);
        }
    }

    private function handleContactCreated(string $contactId): void
    {
        $contact = CoreContact::find($contactId);
        if (!$contact) {
            return;
        }

        $roles = $contact->roles()->pluck('role')->toArray();
        $crmRoles = ['lead', 'crm_lead', 'opportunity', 'account', 'crm_contact'];

        foreach ($roles as $role) {
            if (in_array($role, $crmRoles)) {
                $this->provisionCrmProfile($contactId, $role);
                return;
            }
        }
    }

    private function handleContactUpdated(string $contactId, array $payload): void
    {
        $newValue = $payload['new_value'] ?? [];

        CrmContactProfile::where('contact_id', $contactId)
            ->where('lead_status', '!=', 'inactive')
            ->update([
                'metadata' => array_merge(
                    CrmContactProfile::where('contact_id', $contactId)->value('metadata') ?? [],
                    ['last_synced_at' => now()->toIso8601String(), 'contact_display_name' => $newValue['display_name'] ?? null]
                ),
            ]);
    }

    private function handleProfileUpdated(string $contactId, array $payload): void
    {
        $profileType = $payload['profile_type'] ?? null;

        if ($profileType && str_starts_with($profileType, 'crm')) {
            $this->handleContactUpdated($contactId, $payload);
        }
    }

    private function handleContactDeleted(string $contactId): void
    {
        $this->deactivateCrmProfile($contactId);
    }

    private function provisionCrmProfile(string $contactId, string $role): void
    {
        $existing = CrmContactProfile::where('contact_id', $contactId)->first();
        if ($existing) {
            return;
        }

        $leadStage = match ($role) {
            'lead', 'crm_lead' => 'new',
            'opportunity' => 'qualified',
            'account' => 'customer',
            default => 'new',
        };

        CrmContactProfile::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'contact_id' => $contactId,
            'lead_source' => 'contact_master',
            'lead_status' => 'active',
            'lead_stage' => $leadStage,
            'contact_score' => 0,
            'estimated_value' => 0,
        ]);

        Log::info("CRM: Profile provisioned for contact {$contactId} with stage '{$leadStage}'");
    }

    private function deactivateCrmProfile(string $contactId): void
    {
        CrmContactProfile::where('contact_id', $contactId)
            ->where('lead_status', '!=', 'inactive')
            ->update(['lead_status' => 'inactive']);
    }
}
