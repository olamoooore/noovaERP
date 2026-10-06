<?php

declare(strict_types=1);

namespace Modules\Sales\Notifications;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\TenantRoleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * SalesNotificationRecipientResolver — turns a notification event + event
 * payload into the tenant users who should receive it (issue #903, PC9).
 *
 * The audiences are declared once in SalesNotificationCatalogue; this class
 * owns how each is resolved:
 *   - creator / salesperson / customer_salesperson: from the document and its
 *     customer;
 *   - accounts: members of the tenant whose central role is Owner, Admin or
 *     Accountant (the same finance audience ComplianceService uses);
 *   - fulfilment: members holding the seeded `fulfillment.write` permission.
 *     There is no warehouse-membership table in the platform, so "the
 *     warehouse" is approximated by that permission — disclosed in #903.
 *
 * The actor who triggered the transition is excluded by the dispatcher, never
 * here, so each audience method stays a pure lookup.
 */
class SalesNotificationRecipientResolver
{
    public function __construct(private readonly TenantRoleService $roles) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    public function resolve(string $notificationEvent, array $payload): Collection
    {
        $document = $this->document($payload);
        $users = collect();

        foreach (SalesNotificationCatalogue::audiences($notificationEvent) as $audience) {
            $users = $users->merge(match ($audience) {
                SalesNotificationCatalogue::AUDIENCE_CREATOR => $this->creator($document),
                SalesNotificationCatalogue::AUDIENCE_SALESPERSON => $this->documentSalesperson($document),
                SalesNotificationCatalogue::AUDIENCE_CUSTOMER_SALESPERSON => $this->customerSalesperson($document, $payload),
                SalesNotificationCatalogue::AUDIENCE_ACCOUNTS => $this->accounts(),
                SalesNotificationCatalogue::AUDIENCE_FULFILMENT => $this->fulfilment(),
                default => collect(),
            });
        }

        return $users
            ->filter(fn ($user) => $user instanceof User)
            ->unique(fn (User $user) => (int) $user->id)
            ->values();
    }

    /**
     * Load the document the payload refers to, when it still exists.
     *
     * @param  array<string, mixed>  $payload
     */
    public function document(array $payload): ?Model
    {
        $id = $payload['document_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        return match ($payload['document_type'] ?? null) {
            'invoice' => Invoice::find($id),
            'quotation' => Quotation::find($id),
            'sales_order' => SalesOrder::find($id),
            'delivery_order' => DeliveryOrder::find($id),
            'credit_note' => CustomerCreditNote::find($id),
            'payment' => Payment::find($id),
            default => null,
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function creator(?Model $document): Collection
    {
        if ($document === null) {
            return collect();
        }

        // Payments record the user under `received_by`, not `created_by`.
        $userId = $document->getAttribute('created_by')
            ?? $document->getAttribute('received_by');

        if (! is_numeric($userId)) {
            return collect();
        }

        return User::query()->whereKey((int) $userId)->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function documentSalesperson(?Model $document): Collection
    {
        if ($document === null) {
            return collect();
        }

        // `salesperson` is free text on quotations, sales orders and delivery
        // orders (no FK) — match it to a tenant user by exact, case-insensitive
        // name, exactly like DocumentFollowerService does.
        $name = trim((string) ($document->getAttribute('salesperson') ?? ''));

        if ($name === '') {
            return collect();
        }

        return User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    private function customerSalesperson(?Model $document, array $payload): Collection
    {
        $customerId = $document?->getAttribute('customer_id') ?? ($payload['customer_id'] ?? null);

        if (! is_numeric($customerId)) {
            return collect();
        }

        $salespersonId = Customer::query()
            ->whereKey((int) $customerId)
            ->value('salesperson_id');

        if (! is_numeric($salespersonId)) {
            return collect();
        }

        return User::query()->whereKey((int) $salespersonId)->get();
    }

    /**
     * Tenant members whose central role is a finance role.
     *
     * @return Collection<int, User>
     */
    private function accounts(): Collection
    {
        $emails = $this->tenantMemberRows()
            ->whereIn('role', [
                UserRole::OWNER->value,
                UserRole::ADMIN->value,
                UserRole::ACCOUNTANT->value,
            ])
            ->pluck('email')
            ->filter()
            ->values();

        return $this->usersByEmail($emails);
    }

    /**
     * Tenant members who can act on fulfilment.
     *
     * @return Collection<int, User>
     */
    private function fulfilment(): Collection
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return collect();
        }

        $emails = collect();

        foreach ($this->tenantMemberRows() as $member) {
            $email = (string) ($member->email ?? '');
            if ($email === '') {
                continue;
            }

            try {
                if ($this->roles->hasPermission((int) $member->central_id, $tenantId, 'fulfillment.write')) {
                    $emails->push($email);
                }
            } catch (\Throwable $e) {
                Log::warning('SalesNotificationRecipientResolver: fulfilment permission check failed', [
                    'user_id' => $member->central_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->usersByEmail($emails);
    }

    /**
     * Central membership rows of the current tenant, with the central user
     * email (central and tenant user ids are separate id spaces).
     *
     * @return Collection<int, object>
     */
    private function tenantMemberRows(): Collection
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return collect();
        }

        return DB::table('public.user_company_access')
            ->join('public.users', 'public.users.id', '=', 'public.user_company_access.user_id')
            ->where('public.user_company_access.tenant_id', $tenantId)
            ->get([
                'public.user_company_access.user_id as central_id',
                'public.user_company_access.role as role',
                'public.users.email as email',
            ]);
    }

    /**
     * @param  Collection<int, mixed>  $emails
     * @return Collection<int, User>
     */
    private function usersByEmail(Collection $emails): Collection
    {
        $emails = $emails
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('email', $emails->all())->get();
    }

    private function tenantId(): ?string
    {
        try {
            if (function_exists('tenant') && tenant()) {
                $id = (string) (tenant('id') ?? '');

                return $id !== '' ? $id : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
