import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { getTierMatrix, setMatrixCell, clearMatrixCell } from '@/lib/admin-api/packaging'
import type { ItemAvailability, CatalogItem, PlanFamily, MatrixCell } from '@/types/admin'

// =============================================================================
// Availability config — label, colour, icon, cycle order
// =============================================================================

interface AvailabilityConfig {
  label: string
  short: string
  bg: string
  text: string
  border: string
  icon: string
}

const AVAILABILITY: Record<ItemAvailability, AvailabilityConfig> = {
  included: {
    label: 'Included',
    short: 'INC',
    bg: 'bg-green-50',
    text: 'text-green-700',
    border: 'border-green-300',
    icon: 'solar:check-circle-bold-duotone',
  },
  add_on: {
    label: 'Add-on',
    short: 'ADD',
    bg: 'bg-blue-50',
    text: 'text-blue-700',
    border: 'border-blue-300',
    icon: 'solar:cart-plus-bold-duotone',
  },
  hidden: {
    label: 'Hidden',
    short: 'HID',
    bg: 'bg-gray-50',
    text: 'text-gray-400',
    border: 'border-gray-200',
    icon: 'solar:eye-closed-bold-duotone',
  },
  custom_only: {
    label: 'Custom',
    short: 'CST',
    bg: 'bg-purple-50',
    text: 'text-purple-700',
    border: 'border-purple-300',
    icon: 'solar:diploma-bold-duotone',
  },
}

// Cycle order when clicking a cell
const CYCLE: ItemAvailability[] = ['included', 'add_on', 'hidden', 'custom_only']

function nextAvailability(current: ItemAvailability | undefined): ItemAvailability {
  if (!current || current === 'hidden') return 'included'
  const idx = CYCLE.indexOf(current)
  return CYCLE[(idx + 1) % CYCLE.length]
}

// =============================================================================
// Legend
// =============================================================================

function MatrixLegend() {
  return (
    <div className="flex flex-wrap gap-3">
      {(Object.entries(AVAILABILITY) as [ItemAvailability, AvailabilityConfig][]).map(([key, cfg]) => (
        <div key={key} className={cn('flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium', cfg.bg, cfg.text, cfg.border)}>
          <Icon icon={cfg.icon} width={13} />
          {cfg.label}
        </div>
      ))}
      <div className="flex items-center gap-1.5 rounded-full border border-dashed border-gray-200 px-3 py-1 text-xs text-gray-400">
        — Not set (hidden)
      </div>
    </div>
  )
}

// =============================================================================
// Matrix Cell button
// =============================================================================

interface CellProps {
  availability: ItemAvailability | undefined
  saving: boolean
  onClick: () => void
  onRightClick: (e: React.MouseEvent) => void
}

function MatrixCellButton({ availability, saving, onClick, onRightClick }: CellProps) {
  const cfg = availability ? AVAILABILITY[availability] : null

  return (
    <button
      onClick={onClick}
      onContextMenu={onRightClick}
      disabled={saving}
      title={cfg ? cfg.label : 'Not set — click to set'}
      className={cn(
        'relative h-9 w-full min-w-[72px] rounded-lg border text-xs font-semibold transition-all',
        'focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-1',
        saving && 'opacity-40 cursor-wait',
        cfg
          ? cn(cfg.bg, cfg.text, cfg.border, 'hover:opacity-80')
          : 'bg-white border-dashed border-gray-200 text-gray-300 hover:border-gray-300 hover:text-gray-400',
      )}
    >
      {saving ? (
        <span className="absolute inset-0 flex items-center justify-center">
          <span className="h-3 w-3 animate-spin rounded-full border border-current border-t-transparent" />
        </span>
      ) : cfg ? (
        <span className="flex items-center justify-center gap-1">
          <Icon icon={cfg.icon} width={12} />
          {cfg.short}
        </span>
      ) : (
        <span>—</span>
      )}
    </button>
  )
}

// =============================================================================
// Context menu for clear / direct selection
// =============================================================================

interface ContextMenuProps {
  x: number
  y: number
  current: ItemAvailability | undefined
  onSelect: (a: ItemAvailability) => void
  onClear: () => void
  onClose: () => void
}

function CellContextMenu({ x, y, current, onSelect, onClear, onClose }: ContextMenuProps) {
  return (
    <>
      <div className="fixed inset-0 z-40" onClick={onClose} />
      <div
        className="fixed z-50 w-44 rounded-xl border border-gray-200 bg-white py-1 shadow-xl"
        style={{ top: y, left: x }}
      >
        {(Object.entries(AVAILABILITY) as [ItemAvailability, AvailabilityConfig][]).map(([key, cfg]) => (
          <button
            key={key}
            onClick={() => { onSelect(key); onClose() }}
            className={cn(
              'flex w-full items-center gap-2.5 px-3 py-2 text-sm transition-colors hover:bg-gray-50',
              current === key ? 'font-semibold' : 'font-normal',
              cfg.text,
            )}
          >
            <Icon icon={cfg.icon} width={15} />
            {cfg.label}
            {current === key && <Icon icon="solar:check-bold" width={12} className="ml-auto" />}
          </button>
        ))}
        <div className="my-1 border-t border-gray-100" />
        <button
          onClick={() => { onClear(); onClose() }}
          className="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-gray-500 hover:bg-gray-50"
        >
          <Icon icon="solar:close-circle-bold-duotone" width={15} />
          Clear (set to hidden)
        </button>
      </div>
    </>
  )
}

// =============================================================================
// Filter bar
// =============================================================================

function FilterBar({
  search,
  onSearch,
  filterAvailability,
  onFilterAvailability,
}: {
  search: string
  onSearch: (v: string) => void
  filterAvailability: ItemAvailability | 'all'
  onFilterAvailability: (v: ItemAvailability | 'all') => void
}) {
  return (
    <div className="flex items-center gap-3 flex-wrap">
      <div className="relative">
        <Icon icon="solar:magnifer-bold-duotone" width={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
          value={search}
          onChange={e => onSearch(e.target.value)}
          placeholder="Filter items…"
          className="h-9 rounded-lg border border-gray-300 pl-8 pr-3 text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100 w-48"
        />
      </div>
      <select
        value={filterAvailability}
        onChange={e => onFilterAvailability(e.target.value as ItemAvailability | 'all')}
        className="h-9 rounded-lg border border-gray-300 px-3 text-sm outline-none focus:border-brand-500"
      >
        <option value="all">All availabilities</option>
        {(Object.keys(AVAILABILITY) as ItemAvailability[]).map(a => (
          <option key={a} value={a}>{AVAILABILITY[a].label}</option>
        ))}
      </select>
    </div>
  )
}

// =============================================================================
// Main page
// =============================================================================

export default function TierMatrixPage() {
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  const [filterAvailability, setFilterAvailability] = useState<ItemAvailability | 'all'>('all')
  const [savingCell, setSavingCell] = useState<string | null>(null)
  const [contextMenu, setContextMenu] = useState<{
    x: number; y: number
    familyId: number; itemId: number
    current: ItemAvailability | undefined
  } | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'packaging', 'matrix'],
    queryFn: getTierMatrix,
  })

  const setCellMut = useMutation({
    mutationFn: ({ familyId, itemId, availability }: {
      familyId: number; itemId: number; availability: ItemAvailability
    }) => setMatrixCell(familyId, itemId, availability),
    onMutate: ({ familyId, itemId }) => {
      setSavingCell(`${familyId}:${itemId}`)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'packaging', 'matrix'] })
    },
    onSettled: () => setSavingCell(null),
  })

  const clearCellMut = useMutation({
    mutationFn: ({ familyId, itemId }: { familyId: number; itemId: number }) =>
      clearMatrixCell(familyId, itemId),
    onMutate: ({ familyId, itemId }) => setSavingCell(`${familyId}:${itemId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'packaging', 'matrix'] })
    },
    onSettled: () => setSavingCell(null),
  })

  const handleCellClick = (familyId: number, itemId: number, current: ItemAvailability | undefined) => {
    const next = nextAvailability(current)
    setCellMut.mutate({ familyId, itemId, availability: next })
  }

  const handleContextMenu = (
    e: React.MouseEvent,
    familyId: number,
    itemId: number,
    current: ItemAvailability | undefined,
  ) => {
    e.preventDefault()
    setContextMenu({ x: e.clientX, y: e.clientY, familyId, itemId, current })
  }

  // Filter items
  const filteredItems = (data?.items ?? []).filter((item: CatalogItem) => {
    const matchesSearch = !search ||
      item.key.toLowerCase().includes(search.toLowerCase()) ||
      item.name.toLowerCase().includes(search.toLowerCase())

    if (!matchesSearch) return false

    if (filterAvailability === 'all') return true

    // At least one tier has this availability
    return (data?.tiers ?? []).some((tier: PlanFamily) => {
      const cell = data?.matrix[`${tier.id}:${item.id}`]
      return cell?.availability === filterAvailability
    })
  })

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
      </div>
    )
  }

  if (isError) {
    return <p className="py-12 text-center text-sm text-red-500">Failed to load tier matrix.</p>
  }

  const tiers = data?.tiers ?? []
  const matrix = data?.matrix ?? {}

  return (
    <div className="space-y-5">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Tier Matrix</h1>
        <p className="mt-1 text-sm text-gray-500">
          Configure how each catalog item is offered per tier.
          Click a cell to cycle through modes. Right-click for direct selection.
        </p>
      </div>

      <MatrixLegend />

      <FilterBar
        search={search}
        onSearch={setSearch}
        filterAvailability={filterAvailability}
        onFilterAvailability={setFilterAvailability}
      />

      {/* Stats bar */}
      {data && (
        <div className="flex gap-4 text-xs text-gray-500">
          <span><strong className="text-gray-700">{tiers.length}</strong> tiers</span>
          <span><strong className="text-gray-700">{filteredItems.length}</strong> items shown</span>
          <span>
            <strong className="text-gray-700">
              {Object.values(matrix).filter((c: MatrixCell) => c.availability === 'included').length}
            </strong> included cells
          </span>
          <span>
            <strong className="text-gray-700">
              {Object.values(matrix).filter((c: MatrixCell) => c.availability === 'add_on').length}
            </strong> add-on cells
          </span>
        </div>
      )}

      {/* Matrix grid */}
      {filteredItems.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 py-16 text-center">
          <p className="text-sm text-gray-400">No catalog items match the current filter.</p>
          <p className="text-xs text-gray-400 mt-1">
            Go to the Catalog page and sync legacy modules first.
          </p>
        </div>
      ) : (
        <div className="rounded-xl border border-gray-200 bg-white overflow-x-auto">
          <table className="w-full text-sm border-collapse">
            {/* Tier headers */}
            <thead>
              <tr className="border-b border-gray-200">
                <th className="sticky left-0 z-10 bg-gray-50 border-r border-gray-200 px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase min-w-[220px]">
                  Catalog Item
                </th>
                {tiers.map((tier: PlanFamily) => (
                  <th
                    key={tier.id}
                    className="px-3 py-3 text-center text-xs font-semibold text-gray-700 bg-gray-50 min-w-[100px]"
                  >
                    <div>{tier.name}</div>
                    <div className="text-[10px] font-mono text-gray-400 font-normal">{tier.key}</div>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {filteredItems.map((item: CatalogItem) => (
                <tr key={item.id} className="hover:bg-gray-50/50 group">
                  {/* Item label — sticky left column */}
                  <td className="sticky left-0 z-10 bg-white group-hover:bg-gray-50/50 border-r border-gray-100 px-4 py-2.5">
                    <div className="flex items-center gap-2 min-w-0">
                      {item.type && (
                        <span className="shrink-0 rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">
                          {item.type.name}
                        </span>
                      )}
                      <div className="min-w-0">
                        <p className="font-mono text-xs text-gray-700 truncate">{item.key}</p>
                        <p className="text-xs text-gray-500 truncate">{item.name}</p>
                      </div>
                    </div>
                  </td>

                  {/* Tier cells */}
                  {tiers.map((tier: PlanFamily) => {
                    const cellKey = `${tier.id}:${item.id}`
                    const cell = matrix[cellKey] as MatrixCell | undefined
                    const isSaving = savingCell === cellKey

                    return (
                      <td key={tier.id} className="px-2 py-2 text-center">
                        <MatrixCellButton
                          availability={cell?.availability}
                          saving={isSaving}
                          onClick={() => handleCellClick(tier.id, item.id, cell?.availability)}
                          onRightClick={(e) => handleContextMenu(e, tier.id, item.id, cell?.availability)}
                        />
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Context menu */}
      {contextMenu && (
        <CellContextMenu
          x={contextMenu.x}
          y={contextMenu.y}
          current={contextMenu.current}
          onSelect={(availability) => {
            setCellMut.mutate({
              familyId: contextMenu.familyId,
              itemId: contextMenu.itemId,
              availability,
            })
          }}
          onClear={() => {
            clearCellMut.mutate({
              familyId: contextMenu.familyId,
              itemId: contextMenu.itemId,
            })
          }}
          onClose={() => setContextMenu(null)}
        />
      )}
    </div>
  )
}
