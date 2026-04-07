<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Domain\AdminActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'        => ['nullable', 'string', 'max:200'],
            'action'        => ['nullable', 'string', 'max:100'],
            'admin_user_id' => ['nullable', 'integer', 'min:1'],
            'target_type'   => ['nullable', 'string', 'max:100'],
            'target_id'     => ['nullable', 'string', 'max:100'],
            'date_from'     => ['nullable', 'date'],
            'date_to'       => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'        => ['nullable', 'string', 'in:success,failure'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = min(100, max(5, (int) ($validated['per_page'] ?? 25)));

        $query = AdminActionLog::with(['adminUser:id,name,email'])
            ->orderByDesc('created_at');

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('action', 'ILIKE', "%{$search}%")
                  ->orWhere('target_type', 'ILIKE', "%{$search}%")
                  ->orWhere('target_id', 'ILIKE', "%{$search}%")
                  ->orWhere('ip_address', 'ILIKE', "%{$search}%");
            });
        }

        if (!empty($validated['action'])) {
            $query->where('action', 'ILIKE', "%{$validated['action']}%");
        }

        if (isset($validated['admin_user_id'])) {
            $query->where('admin_user_id', $validated['admin_user_id']);
        }

        if (!empty($validated['target_type'])) {
            $query->where('target_type', $validated['target_type']);
        }

        if (!empty($validated['target_id'])) {
            $query->where('target_id', $validated['target_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate(
            $perPage,
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(AdminActionLog $log): JsonResponse
    {
        $log->load(['adminUser:id,name,email']);

        return response()->json(['data' => $log]);
    }
}
