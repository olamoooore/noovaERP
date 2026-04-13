import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import {
  getCatalogTree,
  getCatalogFlat,
  getCatalogTypes,
  createCatalogItem,
  deactivateCatalogItem,
  addCatalogDependency,
  removeCatalogDependency,
  addCatalogIncompatibility,
  removeCatalogIncompatibility,
  addCatalogVisibilityRule,
  removeCatalogVisibilityRule,
  syncCatalogFromLegacy,
  createCatalogType,
} from '@/lib/admin-api/catalog'
import type {
  CatalogItem,
  CatalogItemType,
  CatalogDependency,
  CatalogIncompatibility,
  CatalogVisibilityRule,
} from '@/types/admin'

// =============================================================================
// Shared helpers
// =============================================================================

function SourceBadge({ source }: { source: string }) {
  return source === 'legacy_module' ? (
    <span className="ml-1.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-700">
      legacy
    </span>
  ) : null
}

function TypeBadge({ type }: { type?: CatalogItemType }) {
  if (!type) return null
  return (
    <span className="ml-1.5 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-blue-50 text-blue-700">
      {type.name}
    </span>
  )
}

function StatusDot({ active }: { active: boolean }) {
  return (
    <span className={cn('inline-block h-1.5 w-1.5 rounded-full', active ? 'bg-green-500' : 'bg-gray-300')} />
  )
}

// =============================================================================
// Create Item Modal
// =============================================================================

function CreateItemModal({
  types,
  flatItems,
  onClose,
}: {
  types: CatalogItemType[]
  flatItems: CatalogItem[]
  onClose: () => void
}) {
  const qc = useQueryClient()
  const [key, setKey]         = useState('')
  const [name, setName]       = useState('')
  const [desc, setDesc]       = useState('')
  const [typeId, setTypeId]   = useState(types[0]?.id ?? 0)
  const [parentId, setParentId] = useState<number | ''>('')
  const [error, setError]     = useState<string | null>(null)

  const { mutate, isPending } = useMutation({
    mutationFn: () => createCatalogItem({
      key,
      name,
      description: desc || undefined,
      type_id: typeId,
      parent_id: parentId !== '' ? Number(parentId) : null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'catalog', 'tree'] })
      qc.invalidateQueries({ queryKey: ['admin', 'catalog', 'flat'] })
      onClose()
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Failed to create item.')
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">New Catalog Item</h2>

        <div className="space-y-3">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Key <span className="text-red-500">*</span>
              <span className="ml-1 text-xs text-gray-400 font-normal">module[.submodule[.feature]]</span>
            </label>
            <input
              value={key} onChange={e => setKey(e.target.value)}
              placeholder="e.g. accounting.payables.ocr"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Name <span className="text-red-500">*</span></label>
            <input
              value={name} onChange={e => setName(e.target.value)}
              placeholder="e.g. OCR Receipt Capture"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Type <span className="text-red-500">*</span></label>
            <select
              value={typeId} onChange={e => setTypeId(Number(e.target.value))}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              {types.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Parent <span className="text-gray-400 font-normal text-xs">(UI grouping only)</span></label>
            <select
              value={parentId} onChange={e => setParentId(e.target.value === '' ? '' : Number(e.target.value))}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            >
              <option value="">— None (top-level) —</option>
              {flatItems.map(i => <option key={i.id} value={i.id}>{i.key} — {i.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea
              value={desc} onChange={e => setDesc(e.target.value)} rows={2}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100"
            />
          </div>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm text-gray-600 hover:bg-gray-100">Cancel</button>
          <button
            onClick={() => mutate()}
            disabled={!key || !name || !typeId || isPending}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {isPending ? 'Creating…' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  )
}

// =============================================================================
// Item Detail Panel
// =============================================================================

function ItemDetailPanel({
  item,
  flatItems,
  onClose,
}: {
  item: CatalogItem
  flatItems: CatalogItem[]
  types?: CatalogItemType[]
  onClose: () => void
}) {
  const qc = useQueryClient()
  const [tab, setTab] = useState<'info' | 'deps' | 'incompat' | 'visibility'>('info')
  const [depItemId, setDepItemId] = useState<number | ''>('')
  const [depType, setDepType] = useState<'required' | 'optional'>('required')
  const [incompatId, setIncompatId] = useState<number | ''>('')
  const [incompatReason, setIncompatReason] = useState('')
  const [visRuleType, setVisRuleType] = useState('')
  const [visCondition, setVisCondition] = useState('')

  const { data: detail } = useQuery({
    queryKey: ['admin', 'catalog', 'item', item.id],
    queryFn: () => import('@/lib/admin-api/catalog').then(m => m.getCatalogItem(item.id)),
  })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['admin', 'catalog', 'item', item.id] })
    qc.invalidateQueries({ queryKey: ['admin', 'catalog', 'tree'] })
  }

  const deactivateMut = useMutation({
    mutationFn: () => deactivateCatalogItem(item.id),
    onSuccess: () => { invalidate(); onClose() },
  })

  const addDepMut = useMutation({
    mutationFn: () => addCatalogDependency(item.id, { depends_on_item_id: Number(depItemId), type: depType }),
    onSuccess: () => { invalidate(); setDepItemId('') },
  })

  const removeDepMut = useMutation({
    mutationFn: (depId: number) => removeCatalogDependency(depId),
    onSuccess: invalidate,
  })

  const addIncompatMut = useMutation({
    mutationFn: () => addCatalogIncompatibility(item.id, { incompatible_item_id: Number(incompatId), reason: incompatReason || undefined }),
    onSuccess: () => { invalidate(); setIncompatId(''); setIncompatReason('') },
  })

  const removeIncompatMut = useMutation({
    mutationFn: (id: number) => removeCatalogIncompatibility(id),
    onSuccess: invalidate,
  })

  const addVisMut = useMutation({
    mutationFn: () => {
      let parsed: Record<string, unknown> = {}
      try { parsed = JSON.parse(visCondition) } catch { parsed = {} }
      return addCatalogVisibilityRule(item.id, { rule_type: visRuleType, condition: parsed })
    },
    onSuccess: () => { invalidate(); setVisRuleType(''); setVisCondition('') },
  })

  const removeVisMut = useMutation({
    mutationFn: (id: number) => removeCatalogVisibilityRule(id),
    onSuccess: invalidate,
  })

  const current = detail ?? item
  const deps = current.dependencies ?? []
  const incompats = current.incompatibilities ?? []
  const visRules = current.visibility_rules ?? []

  const otherItems = flatItems.filter(i => i.id !== item.id)

  const TABS = [
    { key: 'info',       label: 'Info' },
    { key: 'deps',       label: `Dependencies (${deps.length})` },
    { key: 'incompat',   label: `Incompatibilities (${incompats.length})` },
    { key: 'visibility', label: `Visibility Rules (${visRules.length})` },
  ] as const

  return (
    <div className="fixed inset-0 z-40 flex">
      {/* Backdrop */}
      <div className="flex-1" onClick={onClose} />
      {/* Panel */}
      <div className="w-full max-w-lg bg-white border-l border-gray-200 shadow-2xl flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-start justify-between px-5 pt-5 pb-3 border-b border-gray-100">
          <div>
            <div className="flex items-center gap-2">
              <StatusDot active={current.is_active} />
              <span className="font-mono text-sm font-semibold text-gray-900">{current.key}</span>
              <TypeBadge type={current.type} />
              <SourceBadge source={current.source} />
            </div>
            <p className="mt-0.5 text-sm text-gray-500">{current.name}</p>
            {current.effective_to && (
              <p className="text-xs text-red-500 mt-0.5">Expires: {new Date(current.effective_to).toLocaleDateString()}</p>
            )}
          </div>
          <div className="flex gap-2 mt-0.5">
            {current.is_active && (
              <button
                onClick={() => { if (confirm(`Deactivate "${current.key}"?`)) deactivateMut.mutate() }}
                className="text-xs text-red-600 hover:text-red-800 border border-red-200 rounded px-2 py-1"
              >
                Deactivate
              </button>
            )}
            <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
              <Icon icon="solar:close-circle-bold-duotone" width={20} />
            </button>
          </div>
        </div>

        {/* Tab Bar */}
        <div className="flex border-b border-gray-100 px-5 gap-4">
          {TABS.map(t => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={cn(
                'py-2.5 text-xs font-medium border-b-2 -mb-px transition-colors',
                tab === t.key
                  ? 'border-brand-600 text-brand-700'
                  : 'border-transparent text-gray-500 hover:text-gray-700',
              )}
            >
              {t.label}
            </button>
          ))}
        </div>

        <div className="flex-1 overflow-y-auto p-5 space-y-4">

          {/* INFO TAB */}
          {tab === 'info' && (
            <div className="space-y-3 text-sm">
              <Row label="Key">{current.key} <span className="ml-1 text-xs text-gray-400">(immutable)</span></Row>
              <Row label="Type">{current.type?.name ?? current.type_id}</Row>
              <Row label="Status">{current.is_active ? 'Active' : 'Inactive'}</Row>
              <Row label="Source">{current.source}</Row>
              <Row label="Effective From">{current.effective_from}</Row>
              <Row label="Effective To">{current.effective_to ?? '—'}</Row>
              {current.description && (
                <div>
                  <p className="text-xs font-medium text-gray-500 mb-1">Description</p>
                  <p className="text-gray-700">{current.description}</p>
                </div>
              )}
            </div>
          )}

          {/* DEPENDENCIES TAB */}
          {tab === 'deps' && (
            <div className="space-y-4">
              {deps.length === 0 ? (
                <p className="text-sm text-gray-400">No dependencies defined.</p>
              ) : (
                <div className="space-y-2">
                  {deps.map((d: CatalogDependency) => (
                    <div key={d.id} className="flex items-center justify-between text-sm bg-gray-50 rounded-lg px-3 py-2">
                      <div>
                        <span className="font-mono text-gray-800">{d.depends_on?.key ?? `#${d.depends_on_item_id}`}</span>
                        <span className={cn('ml-2 text-xs font-medium', d.type === 'required' ? 'text-red-600' : 'text-gray-500')}>
                          {d.type}
                        </span>
                      </div>
                      <button onClick={() => removeDepMut.mutate(d.id)} className="text-xs text-red-500 hover:text-red-700">Remove</button>
                    </div>
                  ))}
                </div>
              )}
              <div className="border-t border-gray-100 pt-3 space-y-2">
                <p className="text-xs font-semibold text-gray-600">Add Dependency</p>
                <select
                  value={depItemId} onChange={e => setDepItemId(e.target.value === '' ? '' : Number(e.target.value))}
                  className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none focus:border-brand-500"
                >
                  <option value="">— Select item —</option>
                  {otherItems.map(i => <option key={i.id} value={i.id}>{i.key}</option>)}
                </select>
                <div className="flex gap-2">
                  <select
                    value={depType} onChange={e => setDepType(e.target.value as 'required' | 'optional')}
                    className="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none focus:border-brand-500"
                  >
                    <option value="required">Required</option>
                    <option value="optional">Optional</option>
                  </select>
                  <button
                    onClick={() => addDepMut.mutate()}
                    disabled={depItemId === '' || addDepMut.isPending}
                    className="px-3 py-2 text-sm bg-brand-600 text-white rounded-lg hover:bg-brand-700 disabled:opacity-40"
                  >
                    Add
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* INCOMPATIBILITIES TAB */}
          {tab === 'incompat' && (
            <div className="space-y-4">
              {incompats.length === 0 ? (
                <p className="text-sm text-gray-400">No incompatibilities defined.</p>
              ) : (
                <div className="space-y-2">
                  {incompats.map((ic: CatalogIncompatibility) => (
                    <div key={ic.id} className="flex items-center justify-between text-sm bg-red-50 rounded-lg px-3 py-2">
                      <div>
                        <span className="font-mono text-gray-800">{ic.incompatible_item?.key ?? `#${ic.incompatible_item_id}`}</span>
                        {ic.reason && <p className="text-xs text-gray-500 mt-0.5">{ic.reason}</p>}
                      </div>
                      <button onClick={() => removeIncompatMut.mutate(ic.id)} className="text-xs text-red-500 hover:text-red-700">Remove</button>
                    </div>
                  ))}
                </div>
              )}
              <div className="border-t border-gray-100 pt-3 space-y-2">
                <p className="text-xs font-semibold text-gray-600">Add Incompatibility</p>
                <select
                  value={incompatId} onChange={e => setIncompatId(e.target.value === '' ? '' : Number(e.target.value))}
                  className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none focus:border-brand-500"
                >
                  <option value="">— Select conflicting item —</option>
                  {otherItems.map(i => <option key={i.id} value={i.id}>{i.key}</option>)}
                </select>
                <input
                  value={incompatReason} onChange={e => setIncompatReason(e.target.value)}
                  placeholder="Reason (optional)"
                  className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 outline-none focus:border-brand-500"
                />
                <button
                  onClick={() => addIncompatMut.mutate()}
                  disabled={incompatId === '' || addIncompatMut.isPending}
                  className="w-full px-3 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40"
                >
                  {addIncompatMut.isPending ? 'Adding…' : 'Add Incompatibility'}
                </button>
              </div>
            </div>
          )}

          {/* VISIBILITY RULES TAB */}
          {tab === 'visibility' && (
            <div className="space-y-4">
              {visRules.length === 0 ? (
                <p className="text-sm text-gray-400">No visibility rules. Item is visible to all tiers.</p>
              ) : (
                <div className="space-y-2">
                  {visRules.map((r: CatalogVisibilityRule) => (
                    <div key={r.id} className="flex items-start justify-between text-sm bg-gray-50 rounded-lg px-3 py-2">
                      <div>
                        <span className="font-medium text-gray-800">{r.rule_type}</span>
                        <pre className="text-xs text-gray-500 mt-0.5 whitespace-pre-wrap">{JSON.stringify(r.condition, null, 2)}</pre>
                      </div>
                      <button onClick={() => removeVisMut.mutate(r.id)} className="ml-3 text-xs text-red-500 hover:text-red-700 shrink-0">Remove</button>
                    </div>
                  ))}
                </div>
              )}
              <div className="border-t border-gray-100 pt-3 space-y-2">
                <p className="text-xs font-semibold text-gray-600">Add Visibility Rule</p>
                <input
                  value={visRuleType} onChange={e => setVisRuleType(e.target.value)}
                  placeholder='rule_type (e.g. tier_whitelist)'
                  className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 font-mono outline-none focus:border-brand-500"
                />
                <textarea
                  value={visCondition} onChange={e => setVisCondition(e.target.value)}
                  rows={3}
                  placeholder='condition JSON, e.g. {"tiers": ["enterprise"]}'
                  className="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 font-mono outline-none focus:border-brand-500"
                />
                <button
                  onClick={() => addVisMut.mutate()}
                  disabled={!visRuleType || !visCondition || addVisMut.isPending}
                  className="w-full px-3 py-2 text-sm bg-brand-600 text-white rounded-lg hover:bg-brand-700 disabled:opacity-40"
                >
                  {addVisMut.isPending ? 'Adding…' : 'Add Rule'}
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-2">
      <span className="w-32 shrink-0 text-xs font-medium text-gray-500">{label}</span>
      <span className="text-gray-800">{children}</span>
    </div>
  )
}

// =============================================================================
// Tab 1: Catalog Tree
// =============================================================================

function CatalogTreeTab({
  types,
  flatItems,
  onSelectItem,
}: {
  types: CatalogItemType[]
  flatItems: CatalogItem[]
  onSelectItem: (item: CatalogItem) => void
}) {
  const qc = useQueryClient()
  const [showCreateModal, setShowCreateModal] = useState(false)
  const [syncResult, setSyncResult] = useState<string | null>(null)

  const { data: tree = [], isLoading, isError } = useQuery({
    queryKey: ['admin', 'catalog', 'tree'],
    queryFn: getCatalogTree,
  })

  const syncMut = useMutation({
    mutationFn: syncCatalogFromLegacy,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'catalog'] })
      setSyncResult(`✓ ${res.synced} synced, ${res.skipped} skipped`)
      setTimeout(() => setSyncResult(null), 5000)
    },
  })

  if (isLoading) return <p className="text-sm text-gray-400 py-8 text-center">Loading catalog…</p>
  if (isError)   return <p className="text-sm text-red-500 py-8 text-center">Failed to load catalog.</p>

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 flex-wrap">
        <button
          onClick={() => setShowCreateModal(true)}
          className="flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"
        >
          <Icon icon="solar:add-circle-bold-duotone" width={16} />
          New Item
        </button>
        <button
          onClick={() => syncMut.mutate()}
          disabled={syncMut.isPending}
          className="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-40"
        >
          <Icon icon="solar:refresh-bold-duotone" width={16} />
          {syncMut.isPending ? 'Syncing…' : 'Sync from Legacy Modules'}
        </button>
        {syncResult && (
          <span className="text-sm text-green-700 font-medium">{syncResult}</span>
        )}
      </div>

      {tree.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 py-16 text-center">
          <p className="text-gray-400 text-sm">No catalog items yet.</p>
          <p className="text-gray-400 text-xs mt-1">Click "Sync from Legacy Modules" to import existing modules.</p>
        </div>
      ) : (
        <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
          {tree.map((module, idx) => (
            <CatalogTreeNode
              key={module.id}
              item={module}
              depth={0}
              isLast={idx === tree.length - 1}
              onSelect={onSelectItem}
            />
          ))}
        </div>
      )}

      {showCreateModal && (
        <CreateItemModal
          types={types}
          flatItems={flatItems}
          onClose={() => setShowCreateModal(false)}
        />
      )}
    </div>
  )
}

function CatalogTreeNode({
  item,
  depth,
  isLast,
  onSelect,
}: {
  item: CatalogItem
  depth: number
  isLast: boolean
  onSelect: (item: CatalogItem) => void
}) {
  const [expanded, setExpanded] = useState(depth === 0)
  const children = item.children ?? []
  const hasChildren = children.length > 0

  return (
    <div className={cn('border-b border-gray-100', isLast && 'border-b-0')}>
      <div
        className="flex items-center gap-2 px-4 py-2.5 hover:bg-gray-50 cursor-pointer"
        style={{ paddingLeft: `${16 + depth * 20}px` }}
      >
        {hasChildren ? (
          <button
            onClick={e => { e.stopPropagation(); setExpanded(v => !v) }}
            className="text-gray-400 hover:text-gray-600 shrink-0"
          >
            <Icon icon={expanded ? 'solar:alt-arrow-down-bold' : 'solar:alt-arrow-right-bold'} width={12} />
          </button>
        ) : (
          <span className="w-3 shrink-0" />
        )}

        <StatusDot active={item.is_active} />

        <button
          onClick={() => onSelect(item)}
          className="flex-1 flex items-center gap-2 text-left min-w-0"
        >
          <span className="font-mono text-sm text-gray-800 truncate">{item.key}</span>
          <span className="text-sm text-gray-500 truncate hidden sm:block">{item.name}</span>
          <TypeBadge type={item.type} />
          <SourceBadge source={item.source} />
        </button>

        <Icon icon="solar:pen-2-bold-duotone" width={14} className="text-gray-300 shrink-0" />
      </div>

      {expanded && hasChildren && (
        <div>
          {children.map((child, idx) => (
            <CatalogTreeNode
              key={child.id}
              item={child}
              depth={depth + 1}
              isLast={idx === children.length - 1}
              onSelect={onSelect}
            />
          ))}
        </div>
      )}
    </div>
  )
}

// =============================================================================
// Tab 3: Item Types Registry
// =============================================================================

function ItemTypesTab() {
  const qc = useQueryClient()
  const [key, setKey]   = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const { data: types = [], isLoading } = useQuery({
    queryKey: ['admin', 'catalog', 'types'],
    queryFn: getCatalogTypes,
  })

  const createMut = useMutation({
    mutationFn: () => createCatalogType({ key, name }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'catalog', 'types'] })
      setKey(''); setName(''); setError(null)
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message ?? 'Failed to create type.')
    },
  })

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <p className="px-5 py-6 text-sm text-gray-400">Loading…</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-100">
              <tr>
                <th className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Key</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th className="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {types.map((t: CatalogItemType) => (
                <tr key={t.id} className="hover:bg-gray-50">
                  <td className="px-5 py-3 font-mono text-xs text-gray-700">{t.key}</td>
                  <td className="px-5 py-3 font-medium text-gray-900">{t.name}</td>
                  <td className="px-5 py-3 text-gray-500 text-xs">{t.description ?? '—'}</td>
                  <td className="px-5 py-3 text-gray-500">{t.sort_order}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="rounded-xl border border-gray-200 bg-white p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-700">Add New Type</h3>
        <p className="text-xs text-gray-500">
          Add product types without schema changes — e.g. <code>ai_pack</code>, <code>locale_pack</code>, <code>compliance_bundle</code>.
        </p>
        <div className="flex gap-3 flex-wrap">
          <input
            value={key} onChange={e => setKey(e.target.value)}
            placeholder="type_key (snake_case)"
            className="flex-1 min-w-[140px] rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono outline-none focus:border-brand-500"
          />
          <input
            value={name} onChange={e => setName(e.target.value)}
            placeholder="Display Name"
            className="flex-1 min-w-[140px] rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-brand-500"
          />
          <button
            onClick={() => createMut.mutate()}
            disabled={!key || !name || createMut.isPending}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {createMut.isPending ? 'Adding…' : 'Add Type'}
          </button>
        </div>
        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </div>
  )
}

// =============================================================================
// Main Page
// =============================================================================

export default function CatalogManagerPage() {
  const [mainTab, setMainTab] = useState<'tree' | 'types'>('tree')
  const [selectedItem, setSelectedItem] = useState<CatalogItem | null>(null)

  const { data: types = [] } = useQuery({
    queryKey: ['admin', 'catalog', 'types'],
    queryFn: getCatalogTypes,
  })

  const { data: flatItems = [] } = useQuery({
    queryKey: ['admin', 'catalog', 'flat'],
    queryFn: getCatalogFlat,
  })

  const MAIN_TABS = [
    { key: 'tree',  label: 'Catalog Tree' },
    { key: 'types', label: 'Item Types' },
  ] as const

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Product Catalog</h1>
        <p className="mt-1 text-sm text-gray-500">
          Define modules, sub-modules, and features. Set dependencies, incompatibilities, and visibility rules.
        </p>
      </div>

      {/* Main tab bar */}
      <div className="flex border-b border-gray-200 gap-6">
        {MAIN_TABS.map(t => (
          <button
            key={t.key}
            onClick={() => setMainTab(t.key)}
            className={cn(
              'pb-3 text-sm font-medium border-b-2 -mb-px transition-colors',
              mainTab === t.key
                ? 'border-brand-600 text-brand-700'
                : 'border-transparent text-gray-500 hover:text-gray-700',
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {mainTab === 'tree' && (
        <CatalogTreeTab
          types={types}
          flatItems={flatItems}
          onSelectItem={setSelectedItem}
        />
      )}

      {mainTab === 'types' && <ItemTypesTab />}

      {/* Item Detail Side Panel */}
      {selectedItem && (
        <ItemDetailPanel
          item={selectedItem}
          flatItems={flatItems}
          types={types}
          onClose={() => setSelectedItem(null)}
        />
      )}
    </div>
  )
}
