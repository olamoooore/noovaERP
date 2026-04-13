<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Models\User;
use App\SuperAdmin\Application\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $userService,
    ) {}

    /** GET /admin/users */
    public function index(): JsonResponse
    {
        Gate::authorize('manage-admin-users');

        return response()->json([
            'data' => [
                'admins'      => $this->userService->listAdmins(),
                'invitations' => $this->userService->listPendingInvitations(),
            ],
        ]);
    }

    /** POST /admin/users/invite */
    public function invite(Request $request): JsonResponse
    {
        Gate::authorize('manage-admin-users');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role'  => ['required', 'string'],
        ]);

        $result = $this->userService->invite($validated['email'], $validated['role']);

        return response()->json([
            'data' => [
                'invitation' => $result['invitation'],
                'accept_url' => $result['accept_url'],
            ],
            'message' => 'Invitation created. Share the accept_url with the new admin.',
        ], 201);
    }

    /** POST /admin/users/accept-invite (public — no auth required) */
    public function acceptInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->userService->acceptInvite($validated['token'], $validated['password']);

        return response()->json([
            'data'    => ['id' => $user->id, 'email' => $user->email, 'role' => $user->role],
            'message' => 'Account created. You can now log in.',
        ], 201);
    }

    /** PUT /admin/users/{id}/role */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage-admin-users');

        $validated = $request->validate([
            'role' => ['required', 'string'],
        ]);

        $updated = $this->userService->updateRole($user, $validated['role']);

        return response()->json(['data' => $updated]);
    }

    /** DELETE /admin/users/{id} */
    public function deactivate(User $user): JsonResponse
    {
        Gate::authorize('manage-admin-users');

        $this->userService->deactivate($user);

        return response()->json(['message' => 'Admin user deactivated.']);
    }
}
