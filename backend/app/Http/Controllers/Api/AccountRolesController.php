<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Accounting\Services\AccountRoleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AccountRolesController extends Controller
{
    public function __construct(
        private readonly AccountRoleResolver $roleResolver,
    ) {}

    /**
     * List all account roles with their current assignments.
     */
    public function index(): JsonResponse
    {
        try {
            $roles = DB::table('account_roles')
                ->leftJoin('entity_account_roles', function ($join) {
                    $join->on('account_roles.id', '=', 'entity_account_roles.account_role_id')
                        ->where('entity_account_roles.is_active', true);
                })
                ->leftJoin('chart_of_accounts', 'chart_of_accounts.id', '=', 'entity_account_roles.chart_of_account_id')
                ->select(
                    'account_roles.id',
                    'account_roles.code',
                    'account_roles.name',
                    'account_roles.description',
                    'account_roles.is_mandatory',
                    'account_roles.category',
                    'chart_of_accounts.id as assigned_account_id',
                    'chart_of_accounts.code as assigned_account_code',
                    'chart_of_accounts.name as assigned_account_name',
                )
                ->orderBy('account_roles.category')
                ->orderBy('account_roles.name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $roles,
                'unassigned_mandatory' => $this->roleResolver->getUnassignedMandatoryRoles(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to fetch account roles'], 500);
        }
    }

    /**
     * Assign a chart of account to a role.
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

        try {
            // Deactivate existing assignment for this role
            DB::table('entity_account_roles')
                ->where('account_role_id', $request->account_role_id)
                ->update(['is_active' => false]);

            DB::table('entity_account_roles')->updateOrInsert(
                [
                    'account_role_id' => $request->account_role_id,
                    'chart_of_account_id' => $request->chart_of_account_id,
                ],
                [
                    'is_active' => true,
                    'assigned_by' => auth()->id(),
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
     * Remove a role assignment.
     */
    public function unassign(int $roleId): JsonResponse
    {
        try {
            DB::table('entity_account_roles')
                ->where('account_role_id', $roleId)
                ->update(['is_active' => false, 'updated_at' => now()]);

            return response()->json(['success' => true, 'message' => 'Account role unassigned']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to unassign account role'], 500);
        }
    }
}
