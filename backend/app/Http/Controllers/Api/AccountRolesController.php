<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Accounting\Services\AccountRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class AccountRolesController extends Controller
{
    public function __construct(
        private readonly AccountRoleResolver $roleResolver,
    ) {}

    /**
     * List all account roles with their current assignments for the resolved entity.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $entityId = $request->attributes->get('legal_entity_id');

            $query = DB::table('account_roles')
                ->leftJoin('entity_account_roles', function ($join) use ($entityId) {
                    $join->on('account_roles.id', '=', 'entity_account_roles.account_role_id')
                        ->where('entity_account_roles.is_active', true);
                    if ($entityId) {
                        $join->where('entity_account_roles.legal_entity_id', $entityId);
                    }
                })
                ->leftJoin('chart_of_accounts', 'chart_of_accounts.id', '=', 'entity_account_roles.chart_of_account_id')
                ->select(
                    'account_roles.id',
                    'account_roles.code',
                    'account_roles.name',
                    'account_roles.description',
                    'account_roles.is_mandatory',
                    'account_roles.category',
                    'entity_account_roles.legal_entity_id',
                    'chart_of_accounts.id as assigned_account_id',
                    'chart_of_accounts.code as assigned_account_code',
                    'chart_of_accounts.name as assigned_account_name',
                )
                ->orderBy('account_roles.category')
                ->orderBy('account_roles.name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $query,
                'unassigned_mandatory' => $this->roleResolver->getUnassignedMandatoryRoles($entityId),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch account roles'], 500);
        }
    }

    /**
     * Assign a chart of account to a role for a specific legal entity.
     */
    public function assign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_role_id' => 'required|integer|exists:account_roles,id',
            'chart_of_account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $entityId = $request->attributes->get('legal_entity_id');
        if (!$entityId) {
            return response()->json(['success' => false, 'message' => 'No legal entity context resolved'], 422);
        }

        try {
            // Deactivate existing assignment for this role within this entity
            DB::table('entity_account_roles')
                ->where('account_role_id', $request->account_role_id)
                ->where('legal_entity_id', $entityId)
                ->update(['is_active' => false]);

            DB::table('entity_account_roles')->updateOrInsert(
                [
                    'legal_entity_id' => $entityId,
                    'account_role_id' => $request->account_role_id,
                    'chart_of_account_id' => $request->chart_of_account_id,
                ],
                [
                    'is_active' => true,
                    'assigned_by' => TenantUserHelper::requireTenantUserId(),
                    'assigned_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return response()->json(['success' => true, 'message' => 'Account role assigned successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to assign account role'], 500);
        }
    }

    /**
     * Remove a role assignment for the resolved entity.
     */
    public function unassign(Request $request, int $roleId): JsonResponse
    {
        try {
            $entityId = $request->attributes->get('legal_entity_id');

            $query = DB::table('entity_account_roles')
                ->where('account_role_id', $roleId);

            if ($entityId) {
                $query->where('legal_entity_id', $entityId);
            }

            $query->update(['is_active' => false, 'updated_at' => now()]);

            return response()->json(['success' => true, 'message' => 'Account role unassigned']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to unassign account role'], 500);
        }
    }
}
