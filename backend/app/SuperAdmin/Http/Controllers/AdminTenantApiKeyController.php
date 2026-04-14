<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Domain\TenantApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AdminTenantApiKeyController extends Controller
{
    /**
     * GET /tenants/{tenantId}/api-keys
     * List all API keys for a tenant (hashes never exposed).
     */
    public function index(string $tenantId): JsonResponse
    {
        $keys = TenantApiKey::forTenant($tenantId)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'key_prefix', 'is_active', 'last_used_at', 'expires_at', 'created_at']);

        return response()->json(['data' => $keys]);
    }

    /**
     * POST /tenants/{tenantId}/api-keys
     * Generate a new API key. Raw key is returned ONCE — store it securely.
     */
    public function store(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $expiresAt = isset($data['expires_at'])
            ? \Carbon\Carbon::parse($data['expires_at'])
            : null;

        ['model' => $model, 'raw_key' => $rawKey] = TenantApiKey::generate(
            tenantId:  $tenantId,
            name:      $data['name'],
            adminId:   Auth::id(),
            expiresAt: $expiresAt,
        );

        return response()->json([
            'data'    => $model->only(['id', 'name', 'key_prefix', 'is_active', 'expires_at', 'created_at']),
            // Raw key shown ONCE — the client must store it
            'raw_key' => $rawKey,
            'warning' => 'Store this key securely. It will not be shown again.',
        ], 201);
    }

    /**
     * PATCH /tenants/{tenantId}/api-keys/{key}
     * Rename or toggle active state.
     */
    public function update(Request $request, string $tenantId, TenantApiKey $key): JsonResponse
    {
        abort_if($key->tenant_id !== $tenantId, 404);

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $key->update($data);

        return response()->json(['data' => $key->only(['id', 'name', 'key_prefix', 'is_active', 'expires_at', 'last_used_at'])]);
    }

    /**
     * DELETE /tenants/{tenantId}/api-keys/{key}
     * Permanently revoke an API key.
     */
    public function destroy(string $tenantId, TenantApiKey $key): JsonResponse
    {
        abort_if($key->tenant_id !== $tenantId, 404);

        $key->delete();

        return response()->json(['message' => 'API key revoked.']);
    }
}
