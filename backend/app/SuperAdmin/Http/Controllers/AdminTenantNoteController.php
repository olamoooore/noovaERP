<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\AdminAuditLogger;
use App\SuperAdmin\Domain\AdminNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AdminTenantNoteController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    public function index(string $tenantId): JsonResponse
    {
        $notes = AdminNote::where('tenant_id', $tenantId)
            ->with('adminUser:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $notes->items(),
            'meta' => [
                'total'     => $notes->total(),
                'page'      => $notes->currentPage(),
                'per_page'  => $notes->perPage(),
                'last_page' => $notes->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = AdminNote::create([
            'tenant_id'     => $tenantId,
            'admin_user_id' => Auth::id(),
            'body'          => $validated['body'],
        ]);

        $this->auditLogger->log(
            action: 'tenant_note.created',
            targetType: 'admin_note',
            targetId: (string) $note->id,
            after: $note->toAuditSnapshot(),
            status: 'success',
        );

        return response()->json([
            'data' => $note->load('adminUser:id,name,email'),
        ], 201);
    }

    public function destroy(string $tenantId, AdminNote $note): JsonResponse
    {
        abort_if(
            $note->tenant_id !== $tenantId,
            404,
            'Note not found for this tenant.'
        );

        $snapshot = $note->toAuditSnapshot();
        $note->delete();

        $this->auditLogger->log(
            action: 'tenant_note.deleted',
            targetType: 'admin_note',
            targetId: (string) $snapshot['id'],
            before: $snapshot,
            status: 'success',
        );

        return response()->json(['message' => 'Note deleted.']);
    }
}
