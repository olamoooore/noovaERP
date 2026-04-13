import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import {
  getTenantConfiguration,
  addTenantSelection,
  removeTenantSelection,
  resetTenantConfiguration,
  rebuildTenantEntitlements,
} from '@/lib/admin-api/entitlements'
import type {
  CatalogItem,
  SelectionType,
  TenantSelectedItem,
  TenantEntitlement,
  PlanItemDefault,
  EntitlementRebuildJob,
  ItemAvailability,
} from '@/types/admin'

// =============================================================================
// Helpers
// =============================================================================

const SELECTION_CONFIG: Record<SelectionType, { label: string; color: string; icon: string }> = {
  add_on_purchased: {
    label: 'Add-on',
    color: 'text-blue-700 bg-blue-50 border-blue-200',
    icon: 'solar:cart-plus-bold-duotone',
  },
  override_include: {
    label: 'Override ✓',
    color: 'text-green-700 bg-green-50 border-green-200',
    icon: 'solar:shield-check-bold-duotone',
  },
  override_exclude: {
    label: 'Override ✗',
    color: 'text-red-700 bg-red-50 border-red-200',
    icon: 'solar:shield-cross-bold-duotone',
  },
}

const AVAILABILITY_COLOR: Record<ItemAvailability, string> = {
  included:    'text-green-700 bg-green-50',
  add_on:      'text-blue-700 bg-blue-50',
  hidden:      'text-gray-400 bg-gray-50',
  custom_only: 'text-purple-700 bg-purple-50',
}

function JobStatusBadge({ status }: { status: EntitlementRebuildJob['status'] }) {
  const cfg = {
    pending:   'bg-yellow-100 text-yellow-700',
    running:   'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700',
    failed:    'bg-red-100 text-red-700',
  }[status]
  return (
    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', cfg)}>
      {status}
    </span>
  )
}

// =============================================================================
// Source explanation badge shown in entitlement list
// =============================================================================

function SourceBadge({ source }: { source: TenantEntitlement['source'] }) {
  const map = {
    tier_included:    { label: 'Tier default', color: 'bg-green-50 text-green-700' },
    add_on_purchased: { label: 'Add-on',       color: 'bg-blue-50 text-blue-700' },
    override_include: { label: 'Admin grant',  color: 'bg-amber-50 text-amber-700' },
    override_exclude: { label: 'Admin block',  color: 'bg-red-50 text-red-700' },
  }
  const cfg = map[source]
  return (
    <span className={cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', cfg.color)}>
      {cfg.label}
    </span>
  )
}

// =============================================================================
// Add selection modal
// =============================================================================

function AddSelectionModal({
  tenantId,
  items,
  onClose,
}: {
  tenantId: string
  items: CatalogItem[]
  onClose: () => void
}) {
  const qc = useQueryClient()
  const [itemId, setItemId]     = useState<number | ''>('')
  const [type, setType]         = useState<SelectionType>('add_on_purchased')
  const [notes, setNotes]       = useState('')
  const [error, setError]       = useState<string | null>(null)

  const { mutate, isPending } = useMutation({
    mutationFn: () => addTenantSelection(tenantId, {
      catalog_item_id: Number(itemId),
      selection_type: type,
      notes: notes || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'tenant-config', tenantId] })
      onClose()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Failed to add selection.')
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">Add Item Selection</h2>

        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Catalog Item <span className="text-red-500">*</span>
            </label>
            <select
              value={itemId}
              onChange={e => setItemId(e.target.value === '' ? '' : Number(e.target.value))}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            >
              <option value="">— Select item —</option>
              {items.map(i => (
                <option key={i.id} value={i.id}>{i.key} — {i.name}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Selection Type <span className="text-red-500">*</span>
            </label>
            <select
              value={type}
              onChange={e => setType(e.target.value as SelectionType)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            >
              <option value="add_on_purchased">Add-on Purchased — tenant bought this add-on</option>
              <option value="override_include">Override Include — grant access regardless of tier</option>
              <option value="override_exclude">Override Exclude — block access regardless of tier</option>
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea
              value={notes}
              onChange={e => setNotes(e.target.value)}
              rows={2}
              placeholder="Reason for this selection…"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            />
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">
            Cancel
          </button>
          <button
            onClick={() => mutate()}
            disabled={itemId === '' || isPending}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {isPending ? 'Adding…' : 'Add Selection'}
          </button>
        </div>
      </div>
    </div>
  )
}

// =============================================================================
// Main page
// =============================================================================

export default function TenantConfigurationPage() {
  const { tenantId } = useParams<{ tenantId: string }>()
  const qc = useQueryClient()
  const [tab, setTab] = useState<'builder' | 'entitlements' | 'jobs'>('builder')
  const [showAddModal, setShowAddModal] = useState(false)
  const [rebuildReason, setRebuildReason] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'tenant-config', tenantId],
    queryFn: () => getTenantConfiguration(tenantId!),
    enabled: !!tenantId,
  })

  const removeMut = useMutation({
    mutationFn: (catalogItemId: number) => removeTenantSelection(tenantId!, catalogItemId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'tenant-config', tenantId] }),
  })

  const resetMut = useMutation({
    mutationFn: () => resetTenantConfiguration(tenantId!),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'tenant-config', tenantId] }),
  })

  const rebuildMut = useMutation({
    mutationFn: () => rebuildTenantEntitlements(tenantId!, rebuildReason || undefined),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'tenant-config', tenantId] })
      setRebuildReason('')
    },
  })

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
      </div>
    )
  }

  if (isError || !data) {
    return <p className="py-12 text-center text-sm text-red-500">Failed to load tenant configuration.</p>
  }

  const {
    active_tier,
    items,
    tier_defaults,
    selections,
    entitlements,
    recent_jobs,
  } = data

  const selectionsList  = Object.values(selections) as TenantSelectedItem[]
  const entitlementList = Object.values(entitlements) as TenantEntitlement[]
  const entitled        = entitlementList.filter(e => e.is_entitled)
  const blocked         = entitlementList.filter(e => !e.is_entitled)

  const TABS = [
    { key: 'builder',      label: 'Configuration Builder' },
    { key: 'entitlements', label: `Resolved Entitlements (${entitled.length})` },
    { key: 'jobs',         label: `Rebuild History (${recent_jobs.length})` },
  ] as const

  return (
    <div className="space-y-5">
      {/* Breadcrumb */}
      <div className="flex items-center gap-2 text-sm text-gray-500">
        <Link to="/tenants" className="hover:text-brand-600">Tenants</Link>
        <Icon icon="solar:alt-arrow-right-bold" width={12} />
        <Link to={`/tenants/${tenantId}`} className="hover:text-brand-600 font-mono text-xs">{tenantId}</Link>
        <Icon icon="solar:alt-arrow-right-bold" width={12} />
        <span className="text-gray-800 font-medium">Configuration</span>
      </div>

      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Tenant Configuration</h1>
          <p className="mt-1 text-sm text-gray-500 font-mono">{tenantId}</p>
        </div>

        {/* Active tier badge */}
        <div className="flex items-center gap-2">
          {active_tier ? (
            <div className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm">
              <span className="text-blue-500 text-xs font-medium">Base Tier</span>
              <p className="font-semibold text-blue-800">{active_tier.name}</p>
              <p className="text-xs font-mono text-blue-500">{active_tier.key}</p>
            </div>
          ) : (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-700">
              No active subscription
            </div>
          )}
        </div>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {[
          { label: 'Entitled Items',   value: entitled.length,        color: 'text-green-700' },
          { label: 'Blocked Items',    value: blocked.length,         color: 'text-red-600' },
          { label: 'Explicit Selections', value: selectionsList.length, color: 'text-blue-700' },
          { label: 'Last Rebuild',     value: recent_jobs[0] ? new Date(recent_jobs[0].created_at).toLocaleTimeString() : '—', color: 'text-gray-700' },
        ].map(stat => (
          <div key={stat.label} className="rounded-xl border border-gray-200 bg-white px-4 py-3">
            <p className="text-xs text-gray-500">{stat.label}</p>
            <p className={cn('text-xl font-bold mt-0.5', stat.color)}>{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Rebuild bar */}
      <div className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 flex-wrap">
        <Icon icon="solar:restart-bold-duotone" width={18} className="text-gray-400 shrink-0" />
        <input
          value={rebuildReason}
          onChange={e => setRebuildReason(e.target.value)}
          placeholder="Rebuild reason (optional)…"
          className="flex-1 min-w-[180px] text-sm outline-none text-gray-700 placeholder-gray-400"
        />
        <button
          onClick={() => rebuildMut.mutate()}
          disabled={rebuildMut.isPending}
          className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
        >
          {rebuildMut.isPending ? 'Rebuilding…' : 'Rebuild Entitlements'}
        </button>
      </div>

      {/* Tab bar */}
      <div className="flex border-b border-gray-200 gap-6">
        {TABS.map(t => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={cn(
              'pb-3 text-sm font-medium border-b-2 -mb-px transition-colors',
              tab === t.key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-gray-500 hover:text-gray-700',
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* ===== BUILDER TAB ===== */}
      {tab === 'builder' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between flex-wrap gap-2">
            <p className="text-sm text-gray-500">
              Explicit selections override the tier matrix. Add-ons grant access to purchasable items.
              Override includes/excludes bypass the tier entirely.
            </p>
            <div className="flex gap-2">
              <button
                onClick={() => { if (confirm('Reset all selections to tier defaults?')) resetMut.mutate() }}
                disabled={resetMut.isPending || selectionsList.length === 0}
                className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-40"
              >
                Reset to Defaults
              </button>
              <button
                onClick={() => setShowAddModal(true)}
                className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
              >
                <Icon icon="solar:add-circle-bold-duotone" width={16} />
                Add Selection
              </button>
            </div>
          </div>

          {/* All catalog items — full view */}
          <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tier Default</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Selection</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective Access</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {items.map((item: CatalogItem) => {
                  const tierDefault = tier_defaults[item.id] as PlanItemDefault | undefined
                  const selection   = selections[item.id] as TenantSelectedItem | undefined
                  const entitlement = entitlements[item.id] as TenantEntitlement | undefined

                  const tierAvail  = tierDefault?.availability ?? 'hidden'
                  const selCfg     = selection ? SELECTION_CONFIG[selection.selection_type] : null
                  const isEntitled = entitlement?.is_entitled ?? false

                  return (
                    <tr key={item.id} className={cn('hover:bg-gray-50/50', selection && 'bg-blue-50/30')}>
                      {/* Item */}
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          {item.type && (
                            <span className="shrink-0 text-[10px] font-medium text-blue-600 bg-blue-50 rounded-full px-1.5 py-0.5">
                              {item.type.name}
                            </span>
                          )}
                          <div>
                            <p className="font-mono text-xs text-gray-800">{item.key}</p>
                            <p className="text-xs text-gray-500">{item.name}</p>
                          </div>
                        </div>
                      </td>

                      {/* Tier default */}
                      <td className="px-4 py-3">
                        <span className={cn('text-xs font-medium px-2 py-0.5 rounded-full', AVAILABILITY_COLOR[tierAvail])}>
                          {tierAvail}
                        </span>
                      </td>

                      {/* Explicit selection */}
                      <td className="px-4 py-3">
                        {selCfg ? (
                          <span className={cn('inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full border', selCfg.color)}>
                            <Icon icon={selCfg.icon} width={11} />
                            {selCfg.label}
                          </span>
                        ) : (
                          <span className="text-xs text-gray-300">—</span>
                        )}
                      </td>

                      {/* Effective access */}
                      <td className="px-4 py-3">
                        {entitlement ? (
                          <span className={cn(
                            'inline-flex items-center gap-1 text-xs font-semibold',
                            isEntitled ? 'text-green-700' : 'text-red-600',
                          )}>
                            <Icon
                              icon={isEntitled ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'}
                              width={14}
                            />
                            {isEntitled ? 'Entitled' : 'Blocked'}
                          </span>
                        ) : (
                          <span className="text-xs text-gray-300">Not resolved</span>
                        )}
                      </td>

                      {/* Remove selection */}
                      <td className="px-4 py-3 text-right">
                        {selection && (
                          <button
                            onClick={() => removeMut.mutate(item.id)}
                            disabled={removeMut.isPending}
                            className="text-xs text-red-500 hover:text-red-700 disabled:opacity-40"
                          >
                            Remove
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ===== ENTITLEMENTS TAB ===== */}
      {tab === 'entitlements' && (
        <div className="space-y-3">
          {entitlementList.length === 0 ? (
            <div className="rounded-xl border border-dashed border-gray-200 py-14 text-center">
              <p className="text-sm text-gray-400">No entitlements resolved yet.</p>
              <p className="text-xs text-gray-400 mt-1">Click "Rebuild Entitlements" above to resolve.</p>
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Access</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Source</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Resolved At</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {entitlementList
                    .sort((a, b) => Number(b.is_entitled) - Number(a.is_entitled))
                    .map((ent: TenantEntitlement) => (
                      <tr key={ent.id} className="hover:bg-gray-50/50">
                        <td className="px-4 py-2.5">
                          <p className="font-mono text-xs text-gray-800">
                            {ent.catalog_item?.key ?? `#${ent.catalog_item_id}`}
                          </p>
                          <p className="text-xs text-gray-500">{ent.catalog_item?.name}</p>
                        </td>
                        <td className="px-4 py-2.5">
                          <span className={cn(
                            'inline-flex items-center gap-1 text-xs font-semibold',
                            ent.is_entitled ? 'text-green-700' : 'text-red-600',
                          )}>
                            <Icon
                              icon={ent.is_entitled ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'}
                              width={14}
                            />
                            {ent.is_entitled ? 'Entitled' : 'Blocked'}
                          </span>
                        </td>
                        <td className="px-4 py-2.5">
                          <SourceBadge source={ent.source} />
                        </td>
                        <td className="px-4 py-2.5 text-xs text-gray-500">
                          {new Date(ent.resolved_at).toLocaleString()}
                        </td>
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ===== REBUILD JOBS TAB ===== */}
      {tab === 'jobs' && (
        <div className="space-y-2">
          {recent_jobs.length === 0 ? (
            <div className="rounded-xl border border-dashed border-gray-200 py-14 text-center">
              <p className="text-sm text-gray-400">No rebuild jobs yet.</p>
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Triggered</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Completed</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {recent_jobs.map((job: EntitlementRebuildJob) => (
                    <tr key={job.id} className="hover:bg-gray-50/50">
                      <td className="px-4 py-2.5"><JobStatusBadge status={job.status} /></td>
                      <td className="px-4 py-2.5 text-xs text-gray-600">{job.reason ?? '—'}</td>
                      <td className="px-4 py-2.5 text-xs text-gray-700 font-medium">{job.items_processed}</td>
                      <td className="px-4 py-2.5 text-xs text-gray-500">
                        {new Date(job.created_at).toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-xs text-gray-500">
                        {job.completed_at ? new Date(job.completed_at).toLocaleString() : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* Add selection modal */}
      {showAddModal && (
        <AddSelectionModal
          tenantId={tenantId!}
          items={items}
          onClose={() => setShowAddModal(false)}
        />
      )}
    </div>
  )
}
