<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class EntityManagementController extends Controller
{
    // ── Groups ──────────────────────────────────────────

    public function indexGroups(): JsonResponse
    {
        $groups = DB::table('groups')->orderBy('name')->get();
        return response()->json($groups);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:groups,code',
            'base_currency' => 'required|string|size:3',
            'fiscal_year_start' => 'sometimes|string|max:5',
        ]);

        $id = DB::table('groups')->insertGetId(array_merge($data, [
            'fiscal_year_start' => $data['fiscal_year_start'] ?? '01-01',
            'created_by' => TenantUserHelper::resolveTenantUserId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(DB::table('groups')->find($id), 201);
    }

    public function showGroup(int $id): JsonResponse
    {
        $group = DB::table('groups')->find($id);
        if (!$group) {
            return response()->json(['message' => 'Group not found'], 404);
        }
        return response()->json($group);
    }

    public function updateGroup(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50',
            'base_currency' => 'sometimes|string|size:3',
            'fiscal_year_start' => 'sometimes|string|max:5',
        ]);

        // Ensure code uniqueness if changing
        if (isset($data['code'])) {
            $existing = DB::table('groups')
                ->where('code', $data['code'])
                ->where('id', '!=', $id)
                ->exists();
            if ($existing) {
                return response()->json(['message' => 'Group code already taken'], 422);
            }
        }

        DB::table('groups')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(DB::table('groups')->find($id));
    }

    // ── Legal Entities ──────────────────────────────────

    public function indexEntities(Request $request): JsonResponse
    {
        $query = DB::table('legal_entities')->orderBy('name');

        if ($request->has('group_id')) {
            $query->where('group_id', (int) $request->input('group_id'));
        }

        return response()->json($query->get());
    }

    public function storeEntity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => 'required|integer|exists:groups,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:legal_entities,code',
            'country_code' => 'sometimes|string|size:2',
            'base_currency' => 'required|string|size:3',
            'fiscal_year_start' => 'sometimes|string|max:5',
        ]);

        $id = DB::table('legal_entities')->insertGetId(array_merge($data, [
            'fiscal_year_start' => $data['fiscal_year_start'] ?? '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(DB::table('legal_entities')->find($id), 201);
    }

    public function showEntity(int $id): JsonResponse
    {
        $entity = DB::table('legal_entities')->find($id);
        if (!$entity) {
            return response()->json(['message' => 'Entity not found'], 404);
        }
        return response()->json($entity);
    }

    public function updateEntity(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50',
            'country_code' => 'sometimes|string|size:2',
            'base_currency' => 'sometimes|string|size:3',
            'fiscal_year_start' => 'sometimes|string|max:5',
            'status' => 'sometimes|in:active,inactive',
        ]);

        // Ensure code uniqueness if changing
        if (isset($data['code'])) {
            $existing = DB::table('legal_entities')
                ->where('code', $data['code'])
                ->where('id', '!=', $id)
                ->exists();
            if ($existing) {
                return response()->json(['message' => 'Entity code already taken'], 422);
            }
        }

        DB::table('legal_entities')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(DB::table('legal_entities')->find($id));
    }

    // ── Branches ────────────────────────────────────────

    public function indexBranches(Request $request): JsonResponse
    {
        $query = DB::table('branches')->orderBy('name');

        if ($request->has('legal_entity_id')) {
            $query->where('legal_entity_id', (int) $request->input('legal_entity_id'));
        }

        return response()->json($query->get());
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => 'required|integer|exists:legal_entities,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:20',
        ]);

        $id = DB::table('branches')->insertGetId(array_merge($data, [
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(DB::table('branches')->find($id), 201);
    }

    // ── Entity Books ────────────────────────────────────

    public function indexBooks(Request $request): JsonResponse
    {
        $query = DB::table('entity_books')->orderBy('name');

        if ($request->has('legal_entity_id')) {
            $query->where('legal_entity_id', (int) $request->input('legal_entity_id'));
        }

        return response()->json($query->get());
    }

    public function storeBook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => 'required|integer|exists:legal_entities,id',
            'name' => 'required|string|max:255',
            'book_type' => 'required|in:general,statutory,management,tax',
            'currency_code' => 'required|string|size:3',
            'is_primary' => 'sometimes|boolean',
        ]);

        $id = DB::table('entity_books')->insertGetId(array_merge($data, [
            'is_primary' => $data['is_primary'] ?? false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(DB::table('entity_books')->find($id), 201);
    }

    // ── Entity Modules ──────────────────────────────────

    public function indexModules(int $entityId): JsonResponse
    {
        $service = app(\App\Services\EntityModuleService::class);
        return response()->json($service->listModules($entityId));
    }

    public function activateModule(Request $request, int $entityId): JsonResponse
    {
        $data = $request->validate(['module_code' => 'required|string']);

        $service = app(\App\Services\EntityModuleService::class);
        $service->activate($entityId, $data['module_code'], $request->user()?->id);

        return response()->json(['message' => 'Module activated']);
    }

    public function deactivateModule(Request $request, int $entityId): JsonResponse
    {
        $data = $request->validate(['module_code' => 'required|string']);

        $service = app(\App\Services\EntityModuleService::class);
        $service->deactivate($entityId, $data['module_code']);

        return response()->json(['message' => 'Module deactivated']);
    }

    public function applyIndustryPack(Request $request, int $entityId): JsonResponse
    {
        $data = $request->validate(['pack_code' => 'required|string']);

        $service = app(\App\Services\EntityModuleService::class);
        $count = $service->applyIndustryPack($entityId, $data['pack_code'], $request->user()?->id);

        return response()->json(['message' => "Applied {$count} modules from industry pack"]);
    }
}
