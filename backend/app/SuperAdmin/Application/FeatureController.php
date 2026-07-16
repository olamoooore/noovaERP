<?php

namespace App\SuperAdmin\Application;

use App\Models\Feature as FeatureModel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class FeatureController
{
    public function __construct(
        private readonly FeatureGateService $featureGateService,
    ) {}

    public function index(Request $request): LengthAwarePaginator
    {
        $query = FeatureModel::query()
            ->when($request->has('search'), function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('key', 'like', "%{$request->search}%");
            })
            ->when($request->has('key'), function ($q) use ($request) {
                $q->where('key', $request->key);
            });

        return $query->paginate($request->per_page ?? 20);
    }

    public function store(Request $request): FeatureModel
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'unique:features,key'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:module,option,maintenance,beta,system'],
            'tier' => ['required', 'string', 'in:free,standard,premium,enterprise'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        return FeatureModel::create($data);
    }

    public function show(string $id): FeatureModel
    {
        return FeatureModel::findOrFail($id);
    }

    public function update(Request $request, string $id): FeatureModel
    {
        $feature = FeatureModel::findOrFail($id);

        $data = $request->validate([
            'key' => ['sometimes', 'string', 'max:50', 'unique:features,key,'.$id],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:module,option,maintenance,beta,system'],
            'tier' => ['sometimes', 'string', 'in:free,standard,premium,enterprise'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $feature->update($data);

        return $feature;
    }

    public function destroy(string $id): array
    {
        $feature = FeatureModel::findOrFail($id);

        $feature->delete();

        return ['message' => 'Feature deleted successfully'];
    }

    public function entitlements(): array
    {
        return [
            'all_tiers' => ['free', 'standard', 'premium', 'enterprise'],
            'feature_types' => ['module', 'option', 'maintenance', 'beta', 'system'],
        ];
    }
}
