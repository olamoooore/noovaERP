<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Models\Customer;
use App\Models\User;
use App\Platform\Notifications\NotificationPreference;
use App\Services\AgedReceivablesService;
use App\Services\EntityAccessService;
use App\Services\MedicalCaseBonusAccrualService;
use App\Services\TenantRoleService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\Sales\Mail\WeeklySalesDigestMail;
use Modules\Sales\Models\SalesDigestSendLog;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * Issue #606 — weekly per-salesperson sales digest.
 *
 * Every line is built from an existing shared read model:
 *
 *   - sales:     SalesAnalyticsService (the same net-revenue, base-currency
 *                read model the sales reports use), narrowed with the #593
 *                salesperson + legal-entity filters; the week-over-week
 *                change compares the same query for the prior week;
 *   - overdue:   AgedReceivablesService (the canonical aging report), counting
 *                customers carrying any balance more than 30 days overdue;
 *   - FOC:       MedicalCaseBonusAccrualService::nextFocDueForCustomers() —
 *                the shared bonus-accrual service owns the calculation, since
 *                the #559 bonus counter does not exist on main.
 *
 * Opt-in is per user: a `platform_notification_preferences` row for the
 * `sales.weekly_digest` event (channel `mail`) with `mail_enabled = true`.
 * The event reuses the platform notification-preference table/model rather
 * than forking a new preference store.
 *
 * The digest is entity-scoped to the recipient's accessible legal entities
 * (empty grants = no scope, matching DashboardController / EnsureEntityAccess)
 * and skips any section the recipient's role cannot view (sales.view,
 * invoices.view, bonus_schemes.view).
 *
 * Channel: WhatsApp when the tenant has an active WhatsApp API key and the
 * recipient has a phone number, else email. A failed WhatsApp send falls back
 * to email. One send-log row is written per user/channel/period, and a `sent`
 * row makes a repeat weekly run idempotent.
 */
class WeeklySalesDigestService
{
    public const EVENT = 'sales.weekly_digest';

    public function __construct(
        private readonly SalesAnalyticsService $salesAnalytics,
        private readonly AgedReceivablesService $agedReceivables,
        private readonly EntityAccessService $entityAccess,
        private readonly TenantRoleService $roles,
        private readonly MedicalCaseBonusAccrualService $bonusAccrual,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * Users of the current tenant that explicitly opted in to the digest.
     *
     * @return Collection<int, User>
     */
    public function optedInUsers(string $tenantId): Collection
    {
        $optedInIds = NotificationPreference::query()
            ->where('event', self::EVENT)
            ->where('channel', 'mail')
            ->where('mail_enabled', true)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($optedInIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $optedInIds->all())
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $this->isTenantMember($user, $tenantId))
            ->values();
    }

    /**
     * Build and send the digest for one user. Returns
     * sent | already_sent | skipped | failed.
     */
    public function processUser(User $user, string $tenantId, Carbon $periodStart, Carbon $periodEnd): string
    {
        if ($this->alreadySent((int) $user->id, $periodStart, $periodEnd)) {
            return 'already_sent';
        }

        try {
            $digest = $this->buildFor($user, $tenantId, $periodStart, $periodEnd);

            if ($digest === null) {
                $this->writeLog($user, $tenantId, $periodStart, $periodEnd, 'none', null, [], 'skipped', 'No digest sections available for this user.');

                return 'skipped';
            }

            return $this->sendForUser($user, $digest, $tenantId, $periodStart, $periodEnd);
        } catch (\Throwable $e) {
            $this->writeLog($user, $tenantId, $periodStart, $periodEnd, 'none', null, [], 'failed', $e->getMessage());

            Log::error('Weekly sales digest failed for user', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Build the digest for one user, or null when the user may view none of
     * the sections.
     *
     * @return array{subject: string, body: string, sections: array<string, array<string, mixed>>}|null
     */
    public function buildFor(User $user, string $tenantId, Carbon $periodStart, Carbon $periodEnd): ?array
    {
        $centralUserId = $this->centralUserId($user);
        $entityIds = $this->entityAccess->accessibleEntityIds((int) $user->id);

        $sections = [];

        if ($this->roles->hasPermission($centralUserId, $tenantId, 'sales.view')) {
            $sections['sales'] = $this->salesSection($user, $periodStart, $periodEnd, $entityIds);
        }

        if ($this->roles->hasPermission($centralUserId, $tenantId, 'invoices.view')) {
            $sections['overdue'] = $this->overdueSection($entityIds);
        }

        if ($this->roles->hasPermission($centralUserId, $tenantId, 'bonus_schemes.view')) {
            $foc = $this->focSection($user, $entityIds);

            if ($foc !== null) {
                $sections['foc'] = $foc;
            }
        }

        if ($sections === []) {
            return null;
        }

        return $this->render($periodStart, $periodEnd, $sections);
    }

    public function alreadySent(int $userId, Carbon $periodStart, Carbon $periodEnd): bool
    {
        return SalesDigestSendLog::query()
            ->where('user_id', $userId)
            ->where('period_start', $periodStart->toDateString())
            ->where('period_end', $periodEnd->toDateString())
            ->where('status', 'sent')
            ->exists();
    }

    // ── Sections ──────────────────────────────────────────────────────────

    /**
     * @param  int[]  $entityIds
     * @return array{amount: float, previous_amount: float, delta_percent: ?float, currency: string}
     */
    private function salesSection(User $user, Carbon $periodStart, Carbon $periodEnd, array $entityIds): array
    {
        $priorStart = $periodStart->copy()->subWeek();
        $priorEnd = $periodStart->copy()->subDay();

        // Empty grants mean "no entity scope" (fail-open), matching
        // DashboardController::resolveEntityScope().
        $entityFilters = $entityIds === [] ? [null] : $entityIds;

        $current = 0.0;
        $previous = 0.0;
        $currency = 'USD';

        foreach ($entityFilters as $entityId) {
            $report = $this->salesAnalytics->getSalesTrends(new ReportFilter(
                startDate: $periodStart->copy(),
                endDate: $periodEnd->copy(),
                salespersonId: (int) $user->id,
                legalEntityId: $entityId,
            ), 'week');

            $current += (float) ($report['summary']['total_revenue'] ?? 0);
            $currency = (string) ($report['summary']['base_currency'] ?? $currency);

            $prior = $this->salesAnalytics->getSalesTrends(new ReportFilter(
                startDate: $priorStart->copy(),
                endDate: $priorEnd->copy(),
                salespersonId: (int) $user->id,
                legalEntityId: $entityId,
            ), 'week');

            $previous += (float) ($prior['summary']['total_revenue'] ?? 0);
        }

        $deltaPercent = $previous > 0
            ? round((($current - $previous) / $previous) * 100, 1)
            : null;

        return [
            'amount' => round($current, 2),
            'previous_amount' => round($previous, 2),
            'delta_percent' => $deltaPercent,
            'currency' => $currency,
        ];
    }

    /**
     * Customers with any balance more than 30 days overdue, using the same
     * aging buckets as the aged-receivables report.
     *
     * @param  int[]  $entityIds
     * @return array{customers_over_30_days: int}
     */
    private function overdueSection(array $entityIds): array
    {
        $filters = $entityIds === [] ? [] : ['legal_entity_ids' => $entityIds];
        $report = $this->agedReceivables->getAgedReceivables($filters);

        $count = 0;

        foreach ($report['data'] as $customer) {
            $over30 = (float) ($customer['days_31_60'] ?? 0)
                + (float) ($customer['days_61_90'] ?? 0)
                + (float) ($customer['days_90_plus'] ?? 0);

            if ($over30 > 0) {
                $count++;
            }
        }

        return ['customers_over_30_days' => $count];
    }

    /**
     * The most imminent FOC entitlement among the salesperson's customers.
     * The rule/projection maths live in the shared bonus-accrual service.
     *
     * @param  int[]  $entityIds
     * @return array<string, mixed>|null
     */
    private function focSection(User $user, array $entityIds): ?array
    {
        $customerIds = Customer::query()
            ->where('salesperson_id', (int) $user->id)
            ->when($entityIds !== [], fn ($query) => $query->whereIn('legal_entity_id', $entityIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($customerIds === []) {
            return null;
        }

        return $this->bonusAccrual->nextFocDueForCustomers($customerIds);
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array{subject: string, body: string, sections: array<string, array<string, mixed>>}
     */
    private function render(Carbon $periodStart, Carbon $periodEnd, array $sections): array
    {
        $baseUrl = rtrim((string) config('frontend.url', 'http://localhost:5173'), '/');
        $subject = 'Weekly sales digest — '.$periodStart->format('j M Y').' to '.$periodEnd->format('j M Y');

        $lines = [$subject, ''];

        if (isset($sections['sales'])) {
            $sales = $sections['sales'];
            $amount = $this->formatMoney((float) $sales['amount'], (string) $sales['currency']);

            $comparison = '';
            if ($sales['delta_percent'] !== null) {
                $percent = (float) $sales['delta_percent'];
                $comparison = sprintf(
                    ' (%s%s%% vs last week)',
                    $percent >= 0 ? '+' : '',
                    number_format($percent, 1),
                );
            }

            $lines[] = "• Your sales {$amount}{$comparison} — {$baseUrl}/sales";
        }

        if (isset($sections['overdue'])) {
            $count = (int) $sections['overdue']['customers_over_30_days'];
            $noun = $count === 1 ? 'customer' : 'customers';
            $lines[] = "• {$count} {$noun} overdue > 30 days — {$baseUrl}/accounting/reporting/aged-receivables";
        }

        if (isset($sections['foc'])) {
            $foc = $sections['foc'];
            $qty = $this->formatQuantity((float) $foc['foc_qty']);
            $cases = (int) $foc['cases'];
            $noun = $cases === 1 ? 'case' : 'cases';

            $lines[] = "• {$foc['customer_name']} earns {$qty} × {$foc['product_name']} FOC in ~{$cases} {$noun}"
                ." — {$baseUrl}/sales/customer-bonus-schemes";
        }

        return [
            'subject' => $subject,
            'body' => implode("\n", $lines),
            'sections' => $sections,
        ];
    }

    // ── Delivery ──────────────────────────────────────────────────────────

    /**
     * @param  array{subject: string, body: string, sections: array<string, array<string, mixed>>}  $digest
     */
    private function sendForUser(User $user, array $digest, string $tenantId, Carbon $periodStart, Carbon $periodEnd): string
    {
        $apiKey = $this->activeWhatsAppKey();

        // The central profile is the authoritative contact record (the tenant
        // user row is not guaranteed to mirror phone), so prefer it when the
        // tenant user has no phone of its own.
        $centralPhone = DB::table('public.users')
            ->where('email', $user->email)
            ->value('phone');

        $phone = trim((string) ($user->phone ?: ($centralPhone ?? '')));

        if ($apiKey !== null && $phone !== '') {
            $result = $this->whatsApp->sendMessage($apiKey, $phone, $digest['body']);
            $ok = (bool) ($result['success'] ?? false);

            $this->writeLog(
                $user,
                $tenantId,
                $periodStart,
                $periodEnd,
                'whatsapp',
                $phone,
                $digest,
                $ok ? 'sent' : 'failed',
                $ok ? null : (string) ($result['error'] ?? 'WhatsApp send failed'),
            );

            if ($ok) {
                return 'sent';
            }

            // Fall through to email — a WhatsApp failure must not silently
            // drop the digest.
        }

        $email = trim((string) $user->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->writeLog(
                $user,
                $tenantId,
                $periodStart,
                $periodEnd,
                'mail',
                $email !== '' ? $email : null,
                $digest,
                'skipped',
                'No deliverable email address.',
            );

            return 'skipped';
        }

        try {
            Mail::to($email)->send(new WeeklySalesDigestMail($digest['subject'], $digest['body']));

            $this->writeLog($user, $tenantId, $periodStart, $periodEnd, 'mail', $email, $digest, 'sent', null);

            return 'sent';
        } catch (\Throwable $e) {
            $this->writeLog($user, $tenantId, $periodStart, $periodEnd, 'mail', $email, $digest, 'failed', $e->getMessage());

            Log::error('Weekly sales digest email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function activeWhatsAppKey(): ?WhatsAppApiKey
    {
        if (! Schema::hasTable('whatsapp_api_keys')) {
            return null;
        }

        return WhatsAppApiKey::query()->valid()->latest('id')->first();
    }

    // ── Logging & helpers ─────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $digest
     */
    private function writeLog(
        User $user,
        string $tenantId,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $channel,
        ?string $recipient,
        array $digest,
        string $status,
        ?string $error,
    ): SalesDigestSendLog {
        return SalesDigestSendLog::create([
            'tenant_id' => $tenantId,
            'user_id' => (int) $user->id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'channel' => $channel,
            'status' => $status,
            'recipient' => $recipient,
            'subject' => $digest['subject'] ?? null,
            'body' => $digest['body'] ?? null,
            'sections' => $digest['sections'] ?? null,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    /**
     * Central user id for a tenant-schema user (public.user_company_access is
     * keyed by central ids) — same resolution as RequirePermission.
     */
    private function centralUserId(User $user): int
    {
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');

        return (int) ($centralId ?? $user->id);
    }

    private function isTenantMember(User $user, string $tenantId): bool
    {
        return DB::table('public.user_company_access')
            ->where('user_id', $this->centralUserId($user))
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $currency = trim($currency) !== '' ? $currency : 'USD';
        $absolute = abs($amount);

        if ($absolute >= 1_000_000) {
            return $currency.' '.$this->trimNumber($amount / 1_000_000, 1).'M';
        }

        if ($absolute >= 1_000) {
            return $currency.' '.$this->trimNumber($amount / 1_000, 1).'k';
        }

        return $currency.' '.number_format($amount, 2);
    }

    private function formatQuantity(float $quantity): string
    {
        return $this->trimNumber($quantity, 4);
    }

    private function trimNumber(float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
