<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Look up user in public schema (central user management)
        // Ensure we're querying the public schema
        \DB::statement('SET search_path TO public');
        
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Get the user's default tenant from company access
        $defaultCompany = \DB::table('user_company_access')
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->first();

        if (!$defaultCompany) {
            throw ValidationException::withMessages([
                'email' => ['No tenant configured for this user. Please contact your administrator.'],
            ]);
        }

        $tenant = \App\Models\Tenant::find($defaultCompany->tenant_id);
        
        if (!$tenant) {
            throw ValidationException::withMessages([
                'email' => ['No tenant configured for this user.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles.permissions'));
    }
}
