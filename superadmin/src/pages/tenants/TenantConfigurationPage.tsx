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
import {
  getPlanChangeHistory,
  requestPlanChange,
  previewProration,
  applyPlanChange,
  cancelPlanChange,
} from '@/lib/admin-api/plan-changes'
import { listApiKeys, createApiKey, updateApiKey, revokeApiKey } from '@/lib/admin-api/api-keys'
import type {
  CatalogItem,
  SelectionType,
  TenantSelectedItem,
  TenantEntitlement,
  PlanItemDefault,
  EntitlementRebuildJob,
  ItemAvailability,
  TenantPlanChange,
  ProrationPreview,
  TenantApiKey,
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
// Request Plan Change Modal
// =============================================================================

function RequestPlanChangeModal({
  tenantId,
  onClose,
}: {
  tenantId: string
  onClose: () => void
}) {
  const qc = useQueryClient()

  // Fetch plan families for the selector
  const { data: plansData } = useQuery({
    queryKey: ['admin', 'plans'],
    queryFn: async () => {
      const { adminApi } = await import('@/lib/admin-api/client')
      const r = await adminApi.get('/plans')
      return r.data.data as Array<{ id: number; name: string; key: string; is_active: boolean }>
    },
  })

  const today = new Date().toISOString().slice(0, 10)
  const [toFamilyId, setToFamilyId] = useState<number | ''>('')
  const [effectiveDate, setEffectiveDate] = useState(today)
  const [reason, setReason] = useState('')
  const [preview, setPreview] = useState<ProrationPreview | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const families = (plansData ?? []).filter(f => f.is_active)

  async function loadPreview() {
    if (!toFamilyId || !effectiveDate) return
    setPreviewLoading(true)
    setError(null)
    try {
      const p = await previewProration(tenantId, { to_family_id: Number(toFamilyId), effective_date: effectiveDate })
      setPreview(p)
    } catch {
      setError('Could not load proration preview.')
    } finally {
      setPreviewLoading(false)
    }
  }

  const submit = useMutation({
    mutationFn: () =>
      requestPlanChange(tenantId, {
        to_family_id:   Number(toFamilyId),
        effective_date: effectiveDate,
        reason:         reason || undefined,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'plan-changes', tenantId] })
      qc.invalidateQueries({ queryKey: ['plan-changes', 'pending'] })
      onClose()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Failed to request plan change.')
    },
  })

  function cents(n: number) {
    return '$' + (n / 100).toFixed(2)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">Request Plan Change</h2>

        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">New Plan Family <span className="text-red-500">*</span></label>
            <select
              value={toFamilyId}
              onChange={e => { setToFamilyId(e.target.value === '' ? '' : Number(e.target.value)); setPreview(null) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            >
              <option value="">— Select target plan —</option>
              {families.map(f => (
                <option key={f.id} value={f.id}>{f.name} ({f.key})</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Effective Date <span className="text-red-500">*</span></label>
            <input
              type="date"
              min={today}
              value={effectiveDate}
              onChange={e => { setEffectiveDate(e.target.value); setPreview(null) }}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Reason</label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={2}
              placeholder="Why is the plan being changed?"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
            />
          </div>

          {/* Preview proration */}
          <div>
            <button
              type="button"
              onClick={loadPreview}
              disabled={!toFamilyId || previewLoading}
              className="text-sm text-brand-600 hover:underline disabled:opacity-40"
            >
              {previewLoading ? 'Loading preview…' : 'Preview proration amounts →'}
            </button>

            {preview && (
              <div className="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm space-y-1">
                <div className="flex justify-between">
                  <span className="text-gray-500">From</span>
                  <span className="font-medium">{preview.from_family?.name ?? '—'}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">To</span>
                  <span className="font-medium">{preview.to_family?.name}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Current period ends</span>
                  <span>{preview.current_period_end ?? '—'}</span>
                </div>
                <hr className="my-1" />
                <div className="flex justify-between text-green-700">
                  <span>Credit (unused days)</span>
                  <span className="font-semibold">{cents(preview.credit_cents)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-gray-500">Charge (new plan)</span>
                  <span className="font-semibold">{cents(preview.charge_cents)}</span>
                </div>
                <div className="flex justify-between font-bold border-t pt-1 mt-1">
                  <span>Net amount due</span>
                  <span className={preview.net_cents <= 0 ? 'text-green-700' : 'text-gray-900'}>
                    {preview.net_cents < 0 ? `−${cents(Math.abs(preview.net_cents))}` : cents(preview.net_cents)}
                  </span>
                </div>
              </div>
            )}
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">
            Cancel
          </button>
          <button
            onClick={() => submit.mutate()}
            disabled={!toFamilyId || !effectiveDate || submit.isPending}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {submit.isPending ? 'Requesting…' : 'Request Change'}
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
  const [tab, setTab] = useState<'builder' | 'entitlements' | 'jobs' | 'plan-changes' | 'api-keys'>('builder')
  const [showAddModal, setShowAddModal] = useState(false)
  const [showPlanChangeModal, setShowPlanChangeModal] = useState(false)
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

  const { data: planChanges = [] } = useQuery({
    queryKey: ['admin', 'plan-changes', tenantId],
    queryFn: () => getPlanChangeHistory(tenantId!),
    enabled: !!tenantId,
  })

  // API Keys state
  const [newKeyName, setNewKeyName]       = useState('')
  const [newKeyExpiry, setNewKeyExpiry]   = useState('')
  const [revealedKey, setRevealedKey]     = useState<string | null>(null)
  const [apiKeyError, setApiKeyError]     = useState<string | null>(null)

  const { data: apiKeys = [], refetch: refetchKeys } = useQuery({
    queryKey: ['admin', 'api-keys', tenantId],
    queryFn: () => listApiKeys(tenantId!),
    enabled: !!tenantId,
  })

  const createKeyMut = useMutation({
    mutationFn: () =>
      createApiKey(tenantId!, {
        name:       newKeyName.trim(),
        expires_at: newKeyExpiry || null,
      }),
    onSuccess: (result) => {
      setRevealedKey(result.raw_key)
      setNewKeyName('')
      setNewKeyExpiry('')
      setApiKeyError(null)
      refetchKeys()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setApiKeyError(e.response?.data?.message ?? 'Failed to create API key.')
    },
  })

  const toggleKeyMut = useMutation({
    mutationFn: ({ keyId, is_active }: { keyId: number; is_active: boolean }) =>
      updateApiKey(tenantId!, keyId, { is_active }),
    onSuccess: () => refetchKeys(),
  })

  const revokeKeyMut = useMutation({
    mutationFn: (keyId: number) => revokeApiKey(tenantId!, keyId),
    onSuccess: () => refetchKeys(),
  })

  const applyChangeMut = useMutation({
    mutationFn: (id: number) => applyPlanChange(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'plan-changes', tenantId] })
      qc.invalidateQueries({ queryKey: ['admin', 'tenant-config', tenantId] })
    },
  })

  const cancelChangeMut = useMutation({
    mutationFn: (id: number) => cancelPlanChange(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'plan-changes', tenantId] }),
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

  const pendingCount = (planChanges as TenantPlanChange[]).filter(c => c.status === 'pending').length

  const TABS = [
    { key: 'builder',      label: 'Configuration Builder' },
    { key: 'entitlements', label: `Resolved Entitlements (${entitled.length})` },
    { key: 'jobs',         label: `Rebuild History (${recent_jobs.length})` },
    { key: 'plan-changes', label: `Plan Changes${pendingCount > 0 ? ` (${pendingCount} pending)` : ''}` },
    { key: 'api-keys',     label: `API Keys (${apiKeys.length})` },
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

      {/* ===== PLAN CHANGES TAB ===== */}
      {tab === 'plan-changes' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-sm text-gray-500">
              Plan changes update the tenant's subscription tier, rebuild entitlements, and generate proration invoices.
            </p>
            <button
              onClick={() => setShowPlanChangeModal(true)}
              className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
            >
              <Icon icon="solar:arrow-right-up-bold-duotone" width={16} />
              Request Plan Change
            </button>
          </div>

          {(planChanges as TenantPlanChange[]).length === 0 ? (
            <div className="rounded-xl border border-dashed border-gray-200 py-14 text-center">
              <p className="text-sm text-gray-400">No plan changes recorded for this tenant.</p>
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Change</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {(planChanges as TenantPlanChange[]).map(change => {
                    const statusColors: Record<string, string> = {
                      pending:   'bg-yellow-100 text-yellow-800',
                      applied:   'bg-green-100 text-green-800',
                      cancelled: 'bg-gray-100 text-gray-500',
                    }
                    return (
                      <tr key={change.id} className="hover:bg-gray-50/50">
                        <td className="px-4 py-3 text-sm">
                          <span className="text-gray-400">{change.from_family?.name ?? '—'}</span>
                          <span className="mx-1.5 text-gray-300">→</span>
                          <span className="font-medium text-gray-900">{change.to_family?.name}</span>
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-600">{change.effective_date}</td>
                        <td className="px-4 py-3">
                          <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', statusColors[change.status])}>
                            {change.status}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-right text-sm text-green-600">
                          {change.proration_credit_cents > 0
                            ? '$' + (change.proration_credit_cents / 100).toFixed(2)
                            : '—'}
                        </td>
                        <td className="px-4 py-3 text-right text-sm text-gray-700">
                          {change.proration_charge_cents > 0
                            ? '$' + (change.proration_charge_cents / 100).toFixed(2)
                            : '—'}
                        </td>
                        <td className="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate">
                          {change.reason ?? '—'}
                        </td>
                        <td className="px-4 py-3 text-right">
                          {change.status === 'pending' && (
                            <div className="flex items-center justify-end gap-2">
                              <button
                                onClick={() => applyChangeMut.mutate(change.id)}
                                disabled={applyChangeMut.isPending}
                                className="rounded-lg bg-brand-600 px-3 py-1 text-xs font-medium text-white hover:bg-brand-700 disabled:opacity-50"
                              >
                                Apply
                              </button>
                              <button
                                onClick={() => cancelChangeMut.mutate(change.id)}
                                disabled={cancelChangeMut.isPending}
                                className="text-xs text-red-500 hover:text-red-700"
                              >
                                Cancel
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ===== API KEYS TAB ===== */}
      {tab === 'api-keys' && (
        <div className="space-y-5">
          {/* Generate new key form */}
          <div className="rounded-xl border border-gray-200 bg-white p-5 space-y-4">
            <h3 className="text-sm font-semibold text-gray-900">Generate New API Key</h3>
            <p className="text-xs text-gray-500">
              API keys allow ERP modules to call the feature gate endpoint
              (<code className="font-mono bg-gray-100 px-1 rounded">GET /v1/features</code>) to check runtime entitlements.
              The raw key is shown <strong>only once</strong> — store it securely.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  Key Name <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  value={newKeyName}
                  onChange={e => setNewKeyName(e.target.value)}
                  placeholder="e.g. Production ERP Server"
                  maxLength={100}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
                />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  Expires At <span className="text-gray-400 font-normal">(optional)</span>
                </label>
                <input
                  type="date"
                  value={newKeyExpiry}
                  onChange={e => setNewKeyExpiry(e.target.value)}
                  min={new Date(Date.now() + 86400000).toISOString().slice(0, 10)}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
                />
              </div>
            </div>

            {apiKeyError && <p className="text-sm text-red-600">{apiKeyError}</p>}

            <div className="flex justify-end">
              <button
                onClick={() => createKeyMut.mutate()}
                disabled={!newKeyName.trim() || createKeyMut.isPending}
                className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
              >
                <Icon icon="solar:key-bold-duotone" width={15} />
                {createKeyMut.isPending ? 'Generating…' : 'Generate Key'}
              </button>
            </div>
          </div>

          {/* Revealed raw key (shown only once) */}
          {revealedKey && (
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 space-y-2">
              <div className="flex items-center gap-2 text-amber-800">
                <Icon icon="solar:danger-triangle-bold-duotone" width={18} />
                <span className="text-sm font-semibold">Save this key now — it will not be shown again</span>
              </div>
              <div className="flex items-center gap-2">
                <code className="flex-1 rounded-lg bg-white border border-amber-200 px-3 py-2 text-sm font-mono text-gray-900 break-all select-all">
                  {revealedKey}
                </code>
                <button
                  onClick={() => { navigator.clipboard.writeText(revealedKey); }}
                  className="shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-medium text-amber-700 hover:bg-amber-50"
                >
                  Copy
                </button>
              </div>
              <button
                onClick={() => setRevealedKey(null)}
                className="text-xs text-amber-600 hover:underline"
              >
                I've saved it — dismiss
              </button>
            </div>
          )}

          {/* Existing keys table */}
          {apiKeys.length === 0 ? (
            <div className="rounded-xl border border-dashed border-gray-200 py-14 text-center">
              <Icon icon="solar:key-bold-duotone" width={32} className="mx-auto text-gray-300 mb-2" />
              <p className="text-sm text-gray-400">No API keys yet. Generate one above.</p>
            </div>
          ) : (
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prefix</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Used</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {(apiKeys as TenantApiKey[]).map(key => (
                    <tr key={key.id} className={cn('hover:bg-gray-50/50', !key.is_active && 'opacity-50')}>
                      <td className="px-4 py-3">
                        <code className="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">
                          {key.key_prefix}…
                        </code>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-800 font-medium">{key.name}</td>
                      <td className="px-4 py-3">
                        <span className={cn(
                          'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                          key.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-500',
                        )}>
                          {key.is_active ? 'Active' : 'Revoked'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500">
                        {key.last_used_at ? new Date(key.last_used_at).toLocaleString() : 'Never'}
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500">
                        {key.expires_at
                          ? new Date(key.expires_at) < new Date()
                            ? <span className="text-red-500 font-medium">Expired {new Date(key.expires_at).toLocaleDateString()}</span>
                            : new Date(key.expires_at).toLocaleDateString()
                          : <span className="text-gray-300">Never</span>
                        }
                      </td>
                      <td className="px-4 py-3 text-xs text-gray-500">
                        {new Date(key.created_at).toLocaleDateString()}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          {key.is_active ? (
                            <>
                              <button
                                onClick={() => toggleKeyMut.mutate({ keyId: key.id, is_active: false })}
                                disabled={toggleKeyMut.isPending}
                                className="text-xs text-gray-400 hover:text-gray-600 disabled:opacity-40"
                              >
                                Disable
                              </button>
                              <button
                                onClick={() => {
                                  if (confirm(`Permanently revoke "${key.name}"? This cannot be undone.`)) {
                                    revokeKeyMut.mutate(key.id)
                                  }
                                }}
                                disabled={revokeKeyMut.isPending}
                                className="text-xs text-red-500 hover:text-red-700 disabled:opacity-40"
                              >
                                Revoke
                              </button>
                            </>
                          ) : (
                            <button
                              onClick={() => toggleKeyMut.mutate({ keyId: key.id, is_active: true })}
                              disabled={toggleKeyMut.isPending}
                              className="text-xs text-brand-600 hover:text-brand-700 disabled:opacity-40"
                            >
                              Re-enable
                            </button>
                          )}
                        </div>
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

      {/* Request plan change modal */}
      {showPlanChangeModal && (
        <RequestPlanChangeModal
          tenantId={tenantId!}
          onClose={() => setShowPlanChangeModal(false)}
        />
      )}
    </div>
  )
}
