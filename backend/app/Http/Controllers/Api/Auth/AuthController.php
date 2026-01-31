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

        // For development: automatically switch to demo tenant
        $tenant = \App\Models\Tenant::where('email', $request->email)->first();
        
        if (!$tenant) {
            // Try to find tenant by user email in tenant database
            $tenant = \App\Models\Tenant::first(); // Use first tenant for development
            
            if (!$tenant) {
                throw ValidationException::withMessages([
                    'email' => ['No tenant configured. Please run seeders.'],
                ]);
            }
        }

        // Run the login logic in tenant context
        return $tenant->run(function () use ($request) {
            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'user' => $user,
                'token' => $token,
                'tenant' => [
                    'id' => tenant('id'),
                    'name' => tenant('name'),
                ],
            ]);
        });
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
