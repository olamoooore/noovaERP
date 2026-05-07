<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    private const ADMIN_ROLES = [
        'platform_super_admin',
        'platform_ops',
        'sales_ops',
        'billing_ops',
        'support_readonly',
    ];

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // search_path is already set to 'public' by EnsurePublicSchemaForAuth middleware
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            Log::warning('Admin login failed: invalid credentials', [
                'email' => $request->email,
                'ip'    => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! in_array($user->role, self::ADMIN_ROLES, true)) {
            Log::warning('Admin login failed: insufficient role', [
                'email' => $request->email,
                'role'  => $user->role,
                'ip'    => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['This account does not have platform admin access.'],
            ]);
        }

        // Revoke previous admin tokens (optional: keep multi-session by removing this)
        $user->tokens()->where('name', 'admin-token')->delete();

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
