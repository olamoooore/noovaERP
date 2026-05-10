<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function index()
    {
        return Warehouse::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $this->enforceTierWarehouseLimit($validated);

        $warehouse = Warehouse::create($validated);

        return response()->json($warehouse, 201);
    }

    public function show($warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        return $warehouse;
    }

    public function update(Request $request, $warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $this->enforceTierWarehouseLimit($validated, $warehouse);

        $warehouse->update($validated);

        return $warehouse;
    }

    public function destroy($warehouse)
    {
        $warehouse = Warehouse::findOrFail($warehouse);
        $warehouse->delete();

        return response()->json(null, 204);
    }

    private function enforceTierWarehouseLimit(array $validated, ?Warehouse $updating = null): void
    {
        if (!array_key_exists('is_active', $validated) || $validated['is_active'] !== true) {
            return;
        }

        $tenantId = $this->currentTenantId();
        if ($tenantId === null) {
            return;
        }

        $tierKey = $this->resolveActiveTierKey($tenantId);
        if ($tierKey === null) {
            return;
        }

        $maxActiveWarehouses = $this->maxActiveWarehousesForTierKey($tierKey);
        if ($maxActiveWarehouses === null) {
            return;
        }

        $activeCount = Warehouse::query()
            ->where('is_active', true)
            ->when($updating, fn ($query) => $query->whereKeyNot($updating->id))
            ->count();

        if ($activeCount >= $maxActiveWarehouses) {
            throw ValidationException::withMessages([
                'is_active' => "Current subscription tier allows only {$maxActiveWarehouses} active warehouse.",
            ]);
        }
    }

    private function currentTenantId(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        return (string) tenant('id');
    }

    private function maxActiveWarehousesForTierKey(string $tierKey): ?int
    {
        return match ($tierKey) {
            'essential' => 1,
            'growth', 'enterprise-standard', 'enterprise-custom' => null,
            default => null,
        };
    }

    private function resolveActiveTierKey(string $tenantId): ?string
    {
        $status = ['active', 'trialing', 'past_due'];

        try {
            return DB::table('public.subscriptions as subscriptions')
                ->join('public.plan_versions as versions', 'versions.id', '=', 'subscriptions.plan_version_id')
                ->join('public.plan_families as families', 'families.id', '=', 'versions.plan_family_id')
                ->where('subscriptions.tenant_id', $tenantId)
                ->whereIn('subscriptions.status', $status)
                ->orderByDesc('subscriptions.id')
                ->value('families.key');
        } catch (\Throwable $e) {
            // Some deployments do not have the central billing tables yet.
            // In that case, skip tier-based warehouse limits instead of failing writes.
            Log::warning('WarehouseController: tier lookup unavailable, skipping warehouse-tier enforcement', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
