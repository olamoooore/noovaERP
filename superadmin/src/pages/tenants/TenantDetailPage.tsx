import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import TenantOperationModal from '@/components/tenants/TenantOperationModal'
import {
  createTenantNote,
  deleteTenantNote,
  getTenantDetail,
  pingTenantHealth,
} from '@/lib/admin-api/tenants'
import { getTenantSubscription } from '@/lib/admin-api/subscriptions'
import { getTenantTimeline } from '@/lib/admin-api/audit'
import { useAuthStore } from '@/lib/store/auth'
import { ActivityFeed } from '@/components/audit/ActivityFeed'
import { SeverityBadge } from '@/components/audit/SeverityBadge'
import type {
  AdminNote, AuditSeverity, Subscription,
  TenantDetail, TenantHealthSnapshot, TenantOperation, TenantTimelineEvent,
} from '@/types/admin'

type Tab = 'overview' | 'operations' | 'health' | 'notes' | 'subscription' | 'activity' | 'timeline'
type ModalOp = 'suspend' | 'reinstate' | 'ping_health' | 'migrate_plan'

// ─── Shared helpers ───────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    active:    'bg-green-100 text-green-800',
    suspended: 'bg-red-100 text-red-800',
    trial:     'bg-yellow-100 text-yellow-800',
  }
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize ${styles[status] ?? 'bg-gray-100 text-gray-700'}`}>
      {status}
    </span>
  )
}

function OpStatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    success: 'bg-green-100 text-green-700',
    failure: 'bg-red-100 text-red-700',
    pending: 'bg-yellow-100 text-yellow-700',
  }
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium capitalize ${styles[status] ?? 'bg-gray-100 text-gray-600'}`}>
      {status}
    </span>
  )
}

function formatDate(d: string | null | undefined) {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function UserInitials({ name }: { name?: string | null }) {
  const initials = name ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '??'
  return (
    <div className="w-8 h-8 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-semibold flex-shrink-0">
      {initials}
    </div>
  )
}

// ─── Tab: Overview ────────────────────────────────────────────────────────────

function OverviewTab({ tenant, onAction }: { tenant: TenantDetail; onAction: (op: ModalOp) => void }) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4">
        {[
          { label: 'Domain',     value: tenant.domain ?? '—' },
          { label: 'Email',      value: tenant.email ?? '—' },
          { label: 'Plan',       value: <span className="capitalize">{tenant.plan ?? '—'}</span> },
          { label: 'Status',     value: <StatusBadge status={tenant.status} /> },
          { label: 'Created',    value: formatDate(tenant.created_at) },
          { label: 'Trial Ends', value: formatDate(tenant.trial_ends_at) },
        ].map(({ label, value }) => (
          <div key={label} className="bg-gray-50 rounded-lg px-4 py-3">
            <p className="text-xs text-gray-500 mb-1">{label}</p>
            <p className="text-sm font-medium text-gray-800">{value}</p>
          </div>
        ))}
      </div>

      {tenant.modules_enabled.length > 0 && (
        <div>
          <p className="text-sm font-medium text-gray-700 mb-2">Modules Enabled</p>
          <div className="flex flex-wrap gap-2">
            {tenant.modules_enabled.map(mod => (
              <span key={mod} className="bg-blue-50 text-blue-700 text-xs px-2.5 py-1 rounded-full">{mod}</span>
            ))}
          </div>
        </div>
      )}

      <div className="flex gap-3 flex-wrap">
        {tenant.status !== 'suspended' && (
          <button onClick={() => onAction('suspend')}
            className="px-4 py-2 text-sm bg-red-600 text-white rounded-lg font-medium hover:bg-red-700">
            Suspend Tenant
          </button>
        )}
        {tenant.status === 'suspended' && (
          <button onClick={() => onAction('reinstate')}
            className="px-4 py-2 text-sm bg-green-600 text-white rounded-lg font-medium hover:bg-green-700">
            Reinstate Tenant
          </button>
        )}
        <button onClick={() => onAction('migrate_plan')}
          className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700">
          Change Plan
        </button>
        <button onClick={() => onAction('ping_health')}
          className="px-4 py-2 text-sm bg-white border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50">
          Ping Health
        </button>
      </div>
    </div>
  )
}

// ─── Tab: Operations ──────────────────────────────────────────────────────────

function OperationsTab({ operations }: { operations: TenantOperation[] }) {
  if (operations.length === 0) {
    return <p className="text-sm text-gray-400 py-8 text-center">No operations recorded yet.</p>
  }
  return (
    <div className="space-y-3">
      {operations.map(op => (
        <div key={op.id} className="flex items-start gap-3 bg-gray-50 rounded-lg px-4 py-3">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm font-medium text-gray-800 capitalize">{op.operation.replace('_', ' ')}</span>
              <OpStatusBadge status={op.status} />
            </div>
            <p className="text-xs text-gray-500 mt-0.5">
              By {op.admin_user?.name ?? 'Unknown'} · {formatDate(op.created_at)}
            </p>
          </div>
        </div>
      ))}
    </div>
  )
}

// ─── Tab: Health ─────────────────────────────────────────────────────────────

function HealthTab({ health, tenantId, onPinged }: {
  health: TenantHealthSnapshot | null; tenantId: string; onPinged: () => void
}) {
  const pingMutation = useMutation({
    mutationFn: () => pingTenantHealth(tenantId),
    onSuccess: onPinged,
  })

  if (!health) {
    return (
      <div className="text-center py-8 space-y-3">
        <p className="text-sm text-gray-400">No health snapshots yet.</p>
        <button onClick={() => pingMutation.mutate()} disabled={pingMutation.isPending}
          className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40">
          {pingMutation.isPending ? 'Pinging…' : 'Run First Health Check'}
        </button>
      </div>
    )
  }

  const metrics = [
    { label: 'DB Connected',  value: health.db_connected ? '✓ Yes' : '✗ No', ok: health.db_connected },
    { label: 'Storage Usage', value: health.storage_usage_mb != null ? `${health.storage_usage_mb} MB` : '—', ok: true },
    { label: 'Last Login',    value: formatDate(health.last_login_at), ok: true },
    { label: 'Error Rate',    value: health.error_rate_percent != null ? `${health.error_rate_percent}%` : '—', ok: true },
    { label: 'Response Time', value: health.response_time_ms != null ? `${health.response_time_ms} ms` : '—', ok: true },
    { label: 'Snapshot At',   value: formatDate(health.created_at), ok: true },
  ]

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        {metrics.map(m => (
          <div key={m.label} className={`rounded-lg px-4 py-3 ${m.ok ? 'bg-gray-50' : 'bg-red-50'}`}>
            <p className="text-xs text-gray-500 mb-1">{m.label}</p>
            <p className={`text-sm font-medium ${m.ok ? 'text-gray-800' : 'text-red-700'}`}>{m.value}</p>
          </div>
        ))}
      </div>
      <button onClick={() => pingMutation.mutate()} disabled={pingMutation.isPending}
        className="px-4 py-2 text-sm bg-white border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 disabled:opacity-40">
        {pingMutation.isPending ? 'Pinging…' : 'Ping Now'}
      </button>
    </div>
  )
}

// ─── Tab: Notes ───────────────────────────────────────────────────────────────

function NotesTab({ notes, tenantId, onChanged, isReadonly }: {
  notes: AdminNote[]; tenantId: string; onChanged: () => void; isReadonly: boolean
}) {
  const [body, setBody] = useState('')

  const createMutation = useMutation({
    mutationFn: () => createTenantNote(tenantId, body),
    onSuccess: () => { setBody(''); onChanged() },
  })

  const deleteMutation = useMutation({
    mutationFn: (noteId: number) => deleteTenantNote(tenantId, noteId),
    onSuccess: onChanged,
  })

  return (
    <div className="space-y-4">
      {!isReadonly && (
        <div className="space-y-2">
          <textarea value={body} onChange={e => setBody(e.target.value)} rows={3}
            placeholder="Add a note about this tenant…"
            className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          <button onClick={() => createMutation.mutate()} disabled={!body.trim() || createMutation.isPending}
            className="px-4 py-2 text-sm bg-brand-600 text-white rounded-lg font-medium hover:bg-brand-700 disabled:opacity-40">
            {createMutation.isPending ? 'Saving…' : 'Add Note'}
          </button>
        </div>
      )}
      {notes.length === 0 ? (
        <p className="text-sm text-gray-400 py-4 text-center">No notes yet.</p>
      ) : (
        <div className="space-y-3">
          {notes.map(note => (
            <div key={note.id} className="flex gap-3 bg-gray-50 rounded-lg px-4 py-3">
              <UserInitials name={note.admin_user?.name} />
              <div className="flex-1 min-w-0">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-xs text-gray-500">
                    {note.admin_user?.name ?? 'Unknown'} · {formatDate(note.created_at)}
                  </p>
                  {!isReadonly && (
                    <button onClick={() => deleteMutation.mutate(note.id)} disabled={deleteMutation.isPending}
                      className="text-gray-400 hover:text-red-500 text-xs">✕</button>
                  )}
                </div>
                <p className="text-sm text-gray-700 mt-1 whitespace-pre-wrap">{note.body}</p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ─── Tab: Subscription ────────────────────────────────────────────────────────

const SUB_STATUS_STYLES: Record<string, string> = {
  active:   'bg-green-100 text-green-800',
  trialing: 'bg-blue-100 text-blue-700',
  past_due: 'bg-orange-100 text-orange-800',
  canceled: 'bg-gray-100 text-gray-600',
  expired:  'bg-red-100 text-red-700',
}

function SubscriptionTab({ tenantId }: { tenantId: string }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'tenants', tenantId, 'subscription'],
    queryFn: () => getTenantSubscription(tenantId),
  })

  const sub: Subscription | null = data?.data ?? null

  if (isLoading) return <p className="text-sm text-gray-400 py-6 text-center">Loading…</p>
  if (isError)   return <p className="text-sm text-red-500 py-6 text-center">Failed to load subscription.</p>
  if (!sub) return (
    <div className="py-12 text-center space-y-2">
      <p className="text-sm text-gray-500">No active subscription for this tenant.</p>
      <p className="text-xs text-gray-400">Create one from the Subscriptions page.</p>
    </div>
  )

  return (
    <div className="space-y-4">
      <div className="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-700">Current Plan</h3>
          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${SUB_STATUS_STYLES[sub.status] ?? 'bg-gray-100 text-gray-700'}`}>
            {sub.status.replace('_', ' ')}
          </span>
        </div>
        <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div>
            <p className="text-gray-400 text-xs">Plan</p>
            <p className="text-gray-900 font-medium">{sub.plan_version?.family?.name ?? '—'} {sub.plan_version?.version_label}</p>
          </div>
          <div>
            <p className="text-gray-400 text-xs">Period Ends</p>
            <p className="text-gray-900 font-medium">{formatDate(sub.current_period_end)}</p>
          </div>
          <div>
            <p className="text-gray-400 text-xs">Trial Ends</p>
            <p className="text-gray-900 font-medium">{formatDate(sub.trial_ends_at)}</p>
          </div>
          <div>
            <p className="text-gray-400 text-xs">Canceled At</p>
            <p className="text-gray-900 font-medium">{sub.canceled_at ? formatDate(sub.canceled_at) : '—'}</p>
          </div>
        </div>
        <div className="pt-2">
          <Link to={`/subscriptions/${sub.id}`} className="text-sm text-brand-600 hover:underline font-medium">
            View full subscription details →
          </Link>
        </div>
      </div>

      {sub.invoices && sub.invoices.length > 0 && (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <div className="px-5 py-3 border-b border-gray-100">
            <h3 className="text-sm font-semibold text-gray-700">Recent Invoices</h3>
          </div>
          <table className="w-full text-sm text-left">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-2.5 font-medium text-gray-600">Invoice #</th>
                <th className="px-4 py-2.5 font-medium text-gray-600">Total</th>
                <th className="px-4 py-2.5 font-medium text-gray-600">Status</th>
                <th className="px-4 py-2.5 font-medium text-gray-600">Due</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {sub.invoices.map(inv => (
                <tr key={inv.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 font-mono text-xs text-gray-700">{inv.invoice_number}</td>
                  <td className="px-4 py-2.5 font-medium text-gray-900">
                    {new Intl.NumberFormat('en-US', { style: 'currency', currency: inv.currency }).format(inv.total / 100)}
                  </td>
                  <td className="px-4 py-2.5">
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize bg-gray-100 text-gray-700">
                      {inv.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-gray-500">{formatDate(inv.due_date)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ─── Tab: Timeline ────────────────────────────────────────────────────────────

function timelineIcon(event: TenantTimelineEvent): string {
  if (event.type === 'health') return event.db_connected ? '💚' : '🔴'
  if (event.type === 'operation') return '⚙️'
  return '📋'
}

function TimelineTab({ tenantId }: { tenantId: string }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'tenants', tenantId, 'timeline'],
    queryFn: () => getTenantTimeline(tenantId, 50),
    staleTime: 30_000,
  })

  const events: TenantTimelineEvent[] = data ?? []

  if (isLoading) return (
    <div className="py-8 flex justify-center">
      <div className="h-5 w-5 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
    </div>
  )

  if (isError) return <p className="text-sm text-red-500 py-6 text-center">Failed to load timeline.</p>

  if (events.length === 0) return (
    <p className="text-sm text-gray-400 py-8 text-center">No events recorded for this tenant.</p>
  )

  return (
    <div className="relative">
      <div className="absolute left-6 top-0 bottom-0 w-px bg-gray-200" />
      <div className="space-y-0">
        {events.map(event => (
          <div key={event.id} className="relative flex items-start gap-4 pl-14 py-3">
            <div className="absolute left-3 flex h-6 w-6 items-center justify-center rounded-full bg-white border-2 border-gray-200 text-sm">
              {timelineIcon(event)}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <SeverityBadge severity={(event.severity ?? 'info') as AuditSeverity} showLabel={false} />
                <p className="text-sm text-gray-800">{event.human_description}</p>
              </div>
              <div className="flex items-center gap-2 mt-0.5 text-xs text-gray-400">
                <span>{formatDate(event.created_at)}</span>
                {event.admin_user && (
                  <>
                    <span>·</span>
                    <span>{event.admin_user.name}</span>
                  </>
                )}
                {event.status === 'failure' && (
                  <>
                    <span>·</span>
                    <span className="text-red-500 font-medium">failed</span>
                  </>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function TenantDetailPage() {
  const { tenantId } = useParams<{ tenantId: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const role = useAuthStore(s => s.user?.role)
  const isReadonly = role === 'support_readonly'

  const [activeTab, setActiveTab] = useState<Tab>('overview')
  const [modalOp, setModalOp] = useState<ModalOp | null>(null)

  const { data: tenant, isLoading, isError } = useQuery({
    queryKey: ['admin', 'tenants', tenantId],
    queryFn: () => getTenantDetail(tenantId!),
    enabled: !!tenantId,
  })

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['admin', 'tenants', tenantId] })
  }

  const TABS: { key: Tab; label: string }[] = [
    { key: 'overview',     label: 'Overview' },
    { key: 'operations',   label: 'Operations' },
    { key: 'health',       label: 'Health' },
    { key: 'notes',        label: 'Notes' },
    { key: 'subscription', label: 'Subscription' },
    { key: 'activity',     label: 'Activity' },
    { key: 'timeline',     label: 'Timeline' },
  ]

  if (isLoading) return <div className="p-8 text-sm text-gray-400">Loading tenant…</div>
  if (isError || !tenant) return <div className="p-8 text-sm text-red-500">Tenant not found.</div>

  return (
    <div className="p-6 space-y-6 max-w-4xl">
      <nav className="flex items-center gap-2 text-sm text-gray-500">
        <button onClick={() => navigate('/tenants')} className="hover:text-brand-600">Tenants</button>
        <span>/</span>
        <span className="text-gray-900 font-medium">{tenant.name ?? tenant.id}</span>
      </nav>

      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">{tenant.name ?? tenant.id}</h1>
          <p className="text-sm text-gray-500 mt-1">{tenant.domain ?? 'No domain'}</p>
        </div>
        <div className="flex items-center gap-2">
          <Link
            to={`/tenants/${tenant.id}/configuration`}
            className="flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100"
          >
            ⚙ Configure
          </Link>
          <StatusBadge status={tenant.status} />
        </div>
      </div>

      <div className="border-b border-gray-200">
        <nav className="flex gap-1 overflow-x-auto">
          {TABS.map(tab => (
            <button key={tab.key} onClick={() => setActiveTab(tab.key)}
              className={`px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors ${
                activeTab === tab.key
                  ? 'border-brand-600 text-brand-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}>
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      <div>
        {activeTab === 'overview' && (
          <OverviewTab tenant={tenant} onAction={op => !isReadonly && setModalOp(op)} />
        )}
        {activeTab === 'operations' && (
          <OperationsTab operations={tenant.recent_operations} />
        )}
        {activeTab === 'health' && (
          <HealthTab health={tenant.latest_health} tenantId={tenant.id} onPinged={invalidate} />
        )}
        {activeTab === 'notes' && (
          <NotesTab notes={tenant.notes} tenantId={tenant.id} onChanged={invalidate} isReadonly={isReadonly} />
        )}
        {activeTab === 'subscription' && (
          <SubscriptionTab tenantId={tenant.id} />
        )}
        {activeTab === 'activity' && (
          <div>
            <p className="text-sm font-medium text-gray-700 mb-4">Audit events for this tenant</p>
            <ActivityFeed targetType="Tenant" targetId={tenant.id} limit={20} />
          </div>
        )}
        {activeTab === 'timeline' && (
          <TimelineTab tenantId={tenant.id} />
        )}
      </div>

      {modalOp && (
        <TenantOperationModal
          tenantId={tenant.id}
          operation={modalOp}
          tenantName={tenant.name ?? tenant.id}
          onClose={() => setModalOp(null)}
          onSuccess={invalidate}
        />
      )}
    </div>
  )
}
