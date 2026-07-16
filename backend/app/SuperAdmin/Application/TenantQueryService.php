<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\AdminNote;
use App\SuperAdmin\Domain\TenantHealthSnapshot;
use App\SuperAdmin\Domain\TenantOperation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TenantQueryService
{
    public function listTenants(
        ?string $search = null,
        ?string $status = null,
        ?string $plan = null,
        int $page = 1,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = DB::table('tenants as t')
            ->leftJoin('domains as d', 'd.tenant_id', '=', 't.id')
            ->leftJoinLateral(
                DB::table('subscriptions')
                    ->select('plan_version_id')
                    ->whereColumn('tenant_id', 't.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'latest_sub'
            )
            ->leftJoin('plan_versions as pv', 'pv.id', '=', 'latest_sub.plan_version_id')
            ->leftJoin('plan_families as pf', 'pf.id', '=', 'pv.plan_family_id')
            ->select([
                't.id',
                't.name',
                't.email',
                't.plan',                                          // legacy — kept for backward compat
                'pf.name as subscription_plan_name',               // authoritative plan name (from subscription)
                'pf.key as subscription_plan_key',
                'pv.version_label as subscription_plan_version',
                't.trial_ends_at',
                't.subscription_ends_at',
                DB::raw('t.data::text as data'),
                't.created_at',
                't.updated_at',
                DB::raw('MIN(d.domain) as domain'),
            ])
            ->groupBy(
                't.id', 't.name', 't.email', 't.plan',
                'pf.name', 'pf.key', 'pv.version_label',
                't.trial_ends_at', 't.subscription_ends_at',
                DB::raw('t.data::text'), 't.created_at', 't.updated_at'
            );

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('t.name', 'ILIKE', "%{$search}%")
                    ->orWhere('t.email', 'ILIKE', "%{$search}%")
                    ->orWhere('d.domain', 'ILIKE', "%{$search}%");
            });
        }

        if ($plan) {
            // Filter by subscription plan family key; fall back to legacy tenants.plan for unsubscribed tenants
            $query->where(function ($q) use ($plan) {
                $q->where('pf.key', $plan)
                    ->orWhere(function ($q2) use ($plan) {
                        $q2->whereNull('pf.key')->where('t.plan', $plan);
                    });
            });
        }

        if ($status === 'suspended') {
            $query->whereRaw("(t.data->>'suspended')::boolean = true");
        } elseif ($status === 'trial') {
            $query->whereRaw("(t.data->>'suspended')::boolean IS NOT TRUE")
                ->whereNotNull('t.trial_ends_at')
                ->whereRaw('t.trial_ends_at > NOW()');
        } elseif ($status === 'active') {
            $query->whereRaw("(t.data->>'suspended')::boolean IS NOT TRUE")
                ->where(function ($q) {
                    $q->whereNull('t.trial_ends_at')
                        ->orWhereRaw('t.trial_ends_at <= NOW()');
                });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($row) {
            $data = is_string($row->data) ? json_decode($row->data, true) : (array) ($row->data ?? []);
            $suspended = ! empty($data['suspended']);
            $isTrial = $row->trial_ends_at && strtotime($row->trial_ends_at) > time();

            $status = match (true) {
                $suspended => 'suspended',
                $isTrial => 'trial',
                default => 'active',
            };

            $latestHealth = TenantHealthSnapshot::where('tenant_id', $row->id)
                ->orderByDesc('created_at')
                ->first(['created_at', 'last_login_at']);

            return [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                // subscription_plan_name is authoritative; tenants.plan is the legacy fallback
                'plan' => $row->subscription_plan_name ?? $row->plan,
                'plan_key' => $row->subscription_plan_key ?? $row->plan,
                'plan_version' => $row->subscription_plan_version,
                'status' => $status,
                'trial_ends_at' => $row->trial_ends_at,
                'subscription_ends_at' => $row->subscription_ends_at,
                'domain' => $row->domain,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'last_health_check' => $latestHealth?->created_at,
                'last_login_at' => $latestHealth?->last_login_at,
            ];
        });

        return new LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    public function getTenantDetail(string $tenantId): array
    {
        $tenant = DB::table('tenants as t')
            ->leftJoin('domains as d', 'd.tenant_id', '=', 't.id')
            ->leftJoinLateral(
                DB::table('subscriptions')
                    ->select('plan_version_id')
                    ->whereColumn('tenant_id', 't.id')
                    ->orderByDesc('created_at')
                    ->limit(1),
                'latest_sub'
            )
            ->leftJoin('plan_versions as pv', 'pv.id', '=', 'latest_sub.plan_version_id')
            ->leftJoin('plan_families as pf', 'pf.id', '=', 'pv.plan_family_id')
            ->where('t.id', $tenantId)
            ->select([
                't.id',
                't.name',
                't.email',
                't.plan',
                'pf.name as subscription_plan_name',
                'pf.key as subscription_plan_key',
                'pv.version_label as subscription_plan_version',
                't.trial_ends_at',
                't.subscription_ends_at',
                DB::raw('t.data::text as data'),
                't.created_at',
                DB::raw('MIN(d.domain) as domain'),
            ])
            ->groupBy(
                't.id', 't.name', 't.email', 't.plan',
                'pf.name', 'pf.key', 'pv.version_label',
                't.trial_ends_at', 't.subscription_ends_at',
                DB::raw('t.data::text'), 't.created_at'
            )
            ->first();

        abort_if(is_null($tenant), 404, 'Tenant not found.');

        $data = is_string($tenant->data) ? json_decode($tenant->data, true) : (array) ($tenant->data ?? []);
        $suspended = ! empty($data['suspended']);
        $isTrial = $tenant->trial_ends_at && strtotime($tenant->trial_ends_at) > time();

        $status = match (true) {
            $suspended => 'suspended',
            $isTrial => 'trial',
            default => 'active',
        };

        $recentOperations = TenantOperation::where('tenant_id', $tenantId)
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $latestHealth = TenantHealthSnapshot::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->first();

        $notes = AdminNote::where('tenant_id', $tenantId)
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => $tenant->email,
            'plan' => $tenant->subscription_plan_name ?? $tenant->plan,
            'plan_key' => $tenant->subscription_plan_key ?? $tenant->plan,
            'plan_version' => $tenant->subscription_plan_version,
            'status' => $status,
            'trial_ends_at' => $tenant->trial_ends_at,
            'subscription_ends_at' => $tenant->subscription_ends_at,
            'domain' => $tenant->domain,
            'created_at' => $tenant->created_at,
            'modules_enabled' => $data['modules_enabled'] ?? [],
            'recent_operations' => $recentOperations,
            'latest_health' => $latestHealth,
            'notes' => $notes,
        ];
    }

    public function getLatestHealth(string $tenantId): ?TenantHealthSnapshot
    {
        return TenantHealthSnapshot::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->first();
    }
}
