<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditorActivityLog;
use App\Models\AuditQuery;
use App\Models\AuditQueryResponse;
use Illuminate\Http\Request;

class AuditQueryController extends Controller
{
    public function adminIndex(Request $request)
    {
        $tenantId = tenant()->id;
        $query = AuditQuery::with(['responses.responder', 'assignedTo', 'closedBy', 'auditor'])
            ->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->get('severity'));
        }

        if ($request->has('auditor_id')) {
            $query->where('auditor_id', $request->get('auditor_id'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $queries = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($queries);
    }

    public function index(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $query = AuditQuery::with(['responses.responder', 'assignedTo', 'closedBy'])
            ->where('auditor_id', $auditor->id);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->get('severity'));
        }

        if ($request->has('source_type')) {
            $query->where('source_type', $request->get('source_type'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $queries = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($queries);
    }

    public function store(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        $isAuditor = $auditor !== null;
        $isAdmin = ! $isAuditor && $request->user() !== null;

        $validated = $request->validate([
            'source_type' => 'required|in:report_line,transaction,document,account',
            'source_id' => 'required|string',
            'source_context' => 'nullable|array',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'severity' => 'required|in:low,medium,high,critical',
            'due_date' => 'nullable|date|after:today',
            'assigned_to' => 'nullable|exists:users,id',
            'auditor_id' => 'nullable|integer|exists:auditors,id',
        ]);

        $auditorId = $auditor?->id ?? $validated['auditor_id'] ?? null;
        if (! $auditorId) {
            return response()->json(['message' => 'auditor_id is required'], 422);
        }

        if ($isAuditor && ! $auditor->scopes()->where('can_raise_queries', true)->exists()) {
            return response()->json(['message' => 'No permission to raise queries'], 403);
        }

        $query = AuditQuery::create([
            'auditor_id' => $auditorId,
            'tenant_id' => tenant()->id,
            'source_type' => $validated['source_type'],
            'source_id' => $validated['source_id'],
            'source_context' => $validated['source_context'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'severity' => $validated['severity'],
            'status' => 'open',
            'due_date' => $validated['due_date'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
        ]);

        AuditorActivityLog::create([
            'auditor_id' => $auditorId,
            'tenant_id' => tenant()->id,
            'action' => 'query_created',
            'resource_type' => 'query',
            'resource_id' => $query->id,
            'metadata' => ['title' => $query->title, 'severity' => $query->severity],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Query created successfully',
            'data' => $query,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $auditor = $request->attributes->get('auditor');
        $isAuditor = $auditor !== null;

        $query = AuditQuery::with(['responses.responder', 'assignedTo', 'closedBy'])
            ->where('tenant_id', tenant()->id)
            ->findOrFail($id);

        if ($isAuditor && $query->auditor_id !== $auditor->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($query);
    }

    public function update(Request $request, string $id)
    {
        $auditor = $request->attributes->get('auditor');
        $isAuditor = $auditor !== null;

        $query = AuditQuery::where('tenant_id', tenant()->id)->findOrFail($id);

        if ($isAuditor && $query->auditor_id !== $auditor->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'severity' => 'sometimes|in:low,medium,high,critical',
            'due_date' => 'nullable|date',
            'assigned_to' => 'nullable|exists:users,id',
            'closure_notes' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:closed',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'closed') {
            $validated['closed_at'] = now();
            $validated['closed_by'] = $request->user()->id;
            $validated['status'] = 'closed';
        }

        $query->update($validated);

        AuditorActivityLog::create([
            'auditor_id' => $auditor->id,
            'tenant_id' => tenant()->id,
            'action' => 'query_updated',
            'resource_type' => 'query',
            'resource_id' => $query->id,
            'metadata' => array_keys($validated),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Query updated successfully',
            'data' => $query,
        ]);
    }

    public function respond(Request $request, string $id)
    {
        $user = $request->user();
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $query = AuditQuery::where('auditor_id', $auditor->id)->findOrFail($id);

        if (in_array($query->status, ['closed', 'rejected'])) {
            return response()->json(['message' => 'Cannot respond to closed query'], 422);
        }

        $validated = $request->validate([
            'response' => 'required|string|max:5000',
            'evidence_urls' => 'nullable|array',
        ]);

        $response = AuditQueryResponse::create([
            'query_id' => $query->id,
            'responder_id' => $user->id,
            'responder_role' => $user->roles->first()->name ?? 'User',
            'response' => $validated['response'],
            'evidence_urls' => $validated['evidence_urls'] ?? null,
        ]);

        $query->update(['status' => 'responded', 'responded_at' => now()]);

        AuditorActivityLog::create([
            'auditor_id' => $auditor->id,
            'tenant_id' => tenant()->id,
            'action' => 'query_responded',
            'resource_type' => 'query',
            'resource_id' => $query->id,
            'metadata' => ['responder' => $user->name, 'response_length' => strlen($validated['response'])],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Response added successfully',
            'data' => $response,
        ], 201);
    }

    public function close(Request $request, string $id)
    {
        $auditor = $request->attributes->get('auditor');
        $isAuditor = $auditor !== null;

        $query = AuditQuery::where('tenant_id', tenant()->id)->findOrFail($id);

        if ($isAuditor && $query->auditor_id !== $auditor->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($query->status === 'closed') {
            return response()->json(['message' => 'Query already closed'], 422);
        }

        $validated = $request->validate([
            'closure_notes' => 'nullable|string|max:1000',
        ]);

        $query->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
            'closure_notes' => $validated['closure_notes'] ?? null,
        ]);

        AuditorActivityLog::create([
            'auditor_id' => $auditor?->id ?? $query->auditor_id,
            'tenant_id' => tenant()->id,
            'action' => 'query_closed',
            'resource_type' => 'query',
            'resource_id' => $query->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Query closed successfully']);
    }
}
