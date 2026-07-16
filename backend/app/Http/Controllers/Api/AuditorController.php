<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditExport;
use App\Models\Auditor;
use App\Models\AuditorActivityLog;
use App\Models\AuditorScope;
use App\Models\AuditQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditorController extends Controller
{
    public function index(Request $request)
    {
        $auditors = Auditor::with(['scopes.entity', 'invitedBy'])
            ->where('tenant_id', tenant()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($auditors);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
            'access_starts_at' => 'nullable|date',
            'access_ends_at' => 'nullable|date|after:access_starts_at',
            'notes' => 'nullable|string|max:1000',
            'scopes' => 'required|array|min:1',
            'scopes.*.entity_id' => 'required|exists:legal_entities,id',
            'scopes.*.accounting_period' => 'nullable|string',
            'scopes.*.modules' => 'nullable|array',
            'scopes.*.reports' => 'nullable|array',
            'scopes.*.transactions' => 'nullable|array',
            'scopes.*.can_export' => 'boolean',
            'scopes.*.can_raise_queries' => 'boolean',
            'scopes.*.can_view_documents' => 'boolean',
        ]);

        $invitationToken = Str::random(64);

        $auditor = DB::transaction(function () use ($validated, $request, $invitationToken) {
            $auditor = Auditor::create([
                'tenant_id' => tenant()->id,
                'email' => $validated['email'],
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'organization' => $validated['organization'] ?? null,
                'invitation_token' => $invitationToken,
                'status' => 'pending',
                'invited_at' => now(),
                'invited_by' => $request->user()->id,
                'access_starts_at' => $validated['access_starts_at'] ?? null,
                'access_ends_at' => $validated['access_ends_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['scopes'] as $scopeData) {
                AuditorScope::create([
                    'auditor_id' => $auditor->id,
                    'entity_id' => $scopeData['entity_id'],
                    'accounting_period' => $scopeData['accounting_period'] ?? null,
                    'modules' => $scopeData['modules'] ?? ['accounting', 'reports'],
                    'reports' => $scopeData['reports'] ?? ['profit_loss', 'balance_sheet', 'trial_balance'],
                    'transactions' => $scopeData['transactions'] ?? ['invoices', 'bills', 'journals'],
                    'can_export' => $scopeData['can_export'] ?? false,
                    'can_raise_queries' => $scopeData['can_raise_queries'] ?? true,
                    'can_view_documents' => $scopeData['can_view_documents'] ?? true,
                ]);
            }

            return $auditor;
        });

        $auditor->load(['scopes.entity', 'invitedBy']);

        return response()->json([
            'message' => 'Auditor invited successfully',
            'data' => $auditor,
            'invitation_link' => url('/auditor/accept/'.$invitationToken),
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $auditor = Auditor::with(['scopes.entity', 'invitedBy', 'queries', 'exports', 'activityLogs'])
            ->where('tenant_id', tenant()->id)
            ->findOrFail($id);

        return response()->json($auditor);
    }

    public function update(Request $request, string $id)
    {
        $auditor = Auditor::where('tenant_id', tenant()->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
            'access_starts_at' => 'nullable|date',
            'access_ends_at' => 'nullable|date|after:access_starts_at',
            'notes' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:active,expired,revoked',
        ]);

        $auditor->update($validated);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'active' && ! $auditor->accepted_at) {
                $auditor->update(['accepted_at' => now()]);
            }
            if (in_array($validated['status'], ['expired', 'revoked'])) {
                AuditQuery::where('auditor_id', $auditor->id)
                    ->whereIn('status', ['open', 'responded'])
                    ->update(['status' => 'closed', 'closure_notes' => 'Auditor access '.$validated['status']]);
            }
        }

        return response()->json([
            'message' => 'Auditor updated successfully',
            'data' => $auditor->fresh(['scopes.entity']),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $auditor = Auditor::where('tenant_id', tenant()->id)->findOrFail($id);

        AuditQuery::where('auditor_id', $auditor->id)
            ->whereIn('status', ['open', 'responded'])
            ->update(['status' => 'closed', 'closure_notes' => 'Auditor revoked']);

        $auditor->update(['status' => 'revoked']);

        return response()->json(['message' => 'Auditor access revoked successfully']);
    }

    public function scopes(Request $request, string $id)
    {
        $auditor = Auditor::where('tenant_id', tenant()->id)->findOrFail($id);
        $scopes = $auditor->scopes()->with('entity')->get();

        return response()->json($scopes);
    }

    public function addScope(Request $request, string $id)
    {
        $auditor = Auditor::where('tenant_id', tenant()->id)->findOrFail($id);

        $validated = $request->validate([
            'entity_id' => 'required|exists:legal_entities,id',
            'accounting_period' => 'nullable|string',
            'modules' => 'nullable|array',
            'reports' => 'nullable|array',
            'transactions' => 'nullable|array',
            'can_export' => 'boolean',
            'can_raise_queries' => 'boolean',
            'can_view_documents' => 'boolean',
        ]);

        $scope = AuditorScope::create([
            'auditor_id' => $auditor->id,
            'entity_id' => $validated['entity_id'],
            'accounting_period' => $validated['accounting_period'] ?? null,
            'modules' => $validated['modules'] ?? ['accounting', 'reports'],
            'reports' => $validated['reports'] ?? ['profit_loss', 'balance_sheet', 'trial_balance'],
            'transactions' => $validated['transactions'] ?? ['invoices', 'bills', 'journals'],
            'can_export' => $validated['can_export'] ?? false,
            'can_raise_queries' => $validated['can_raise_queries'] ?? true,
            'can_view_documents' => $validated['can_view_documents'] ?? true,
        ]);

        return response()->json([
            'message' => 'Scope added successfully',
            'data' => $scope->load('entity'),
        ], 201);
    }

    public function updateScope(Request $request, string $id, string $scopeId)
    {
        $scope = AuditorScope::where('auditor_id', $id)
            ->where('id', $scopeId)
            ->firstOrFail();

        $validated = $request->validate([
            'accounting_period' => 'nullable|string',
            'modules' => 'nullable|array',
            'reports' => 'nullable|array',
            'transactions' => 'nullable|array',
            'can_export' => 'boolean',
            'can_raise_queries' => 'boolean',
            'can_view_documents' => 'boolean',
        ]);

        $scope->update($validated);

        return response()->json([
            'message' => 'Scope updated successfully',
            'data' => $scope->fresh('entity'),
        ]);
    }

    public function removeScope(Request $request, string $id, string $scopeId)
    {
        AuditorScope::where('auditor_id', $id)
            ->where('id', $scopeId)
            ->firstOrFail()
            ->delete();

        return response()->json(['message' => 'Scope removed successfully']);
    }

    public function dashboard(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $tenantId = tenant()->id;

        $activeQueries = AuditQuery::where('auditor_id', $auditor->id)
            ->where('status', '!=', 'closed')
            ->count();

        $totalQueries = AuditQuery::where('auditor_id', $auditor->id)->count();

        $totalExports = AuditExport::where('auditor_id', $auditor->id)->count();

        $recentActivity = AuditorActivityLog::where('auditor_id', $auditor->id)
            ->latest()
            ->take(10)
            ->get();

        $queriesByStatus = AuditQuery::where('auditor_id', $auditor->id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $queriesBySeverity = AuditQuery::where('auditor_id', $auditor->id)
            ->selectRaw('severity, count(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity');

        $upcomingDeadlines = AuditQuery::where('auditor_id', $auditor->id)
            ->where('status', '!=', 'closed')
            ->where('due_date', '>=', now())
            ->orderBy('due_date', 'asc')
            ->take(5)
            ->get();

        return response()->json([
            'active_queries' => $activeQueries,
            'total_queries' => $totalQueries,
            'total_exports' => $totalExports,
            'recent_activity' => $recentActivity,
            'queries_by_status' => $queriesByStatus,
            'queries_by_severity' => $queriesBySeverity,
            'upcoming_deadlines' => $upcomingDeadlines,
        ]);
    }

    public function acceptInvitation(Request $request, string $token)
    {
        $auditor = Auditor::where('invitation_token', $token)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($auditor->hasExpired()) {
            return response()->json(['message' => 'Invitation has expired'], 410);
        }

        $auditor->update([
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        AuditorActivityLog::create([
            'auditor_id' => $auditor->id,
            'tenant_id' => $auditor->tenant_id,
            'action' => 'invitation_accepted',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Invitation accepted successfully',
            'data' => $auditor->load('scopes.entity'),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $validated = $request->validate([
            'phone' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $auditor->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => $auditor->fresh(),
        ]);
    }
}
