import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'
import api from '@/lib/api'

interface Feature {
  id: string
  name: string
  key: string
  description: string
  is_core: boolean
  enabled: boolean
}

interface ModuleVisual {
  icon: string
  /** Tailwind classes for the icon tile: tint + foreground text. */
  tile: string
  /** Tailwind classes for the card's hover ring. */
  ring: string
}

const MODULE_VISUALS: Record<string, ModuleVisual> = {
  core: {
    icon: 'solar:widget-6-bold-duotone',
    tile: 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300',
    ring: 'hover:ring-slate-300 dark:hover:ring-slate-700',
  },
  accounting: {
    icon: 'solar:calculator-minimalistic-bold-duotone',
    tile: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    ring: 'hover:ring-blue-300 dark:hover:ring-blue-800',
  },
  sales: {
    icon: 'solar:cart-large-bold-duotone',
    tile: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300',
    ring: 'hover:ring-indigo-300 dark:hover:ring-indigo-800',
  },
  inventory: {
    icon: 'solar:box-bold-duotone',
    tile: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    ring: 'hover:ring-amber-300 dark:hover:ring-amber-800',
  },
  pos: {
    icon: 'solar:monitor-bold-duotone',
    tile: 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300',
    ring: 'hover:ring-purple-300 dark:hover:ring-purple-800',
  },
  ecommerce: {
    icon: 'solar:cart-large-2-bold-duotone',
    tile: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
    ring: 'hover:ring-emerald-300 dark:hover:ring-emerald-800',
  },
  'website-builder': {
    icon: 'solar:palette-bold-duotone',
    tile: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950/60 dark:text-fuchsia-300',
    ring: 'hover:ring-fuchsia-300 dark:hover:ring-fuchsia-800',
  },
  hrm: {
    icon: 'solar:users-group-rounded-bold-duotone',
    tile: 'bg-teal-100 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300',
    ring: 'hover:ring-teal-300 dark:hover:ring-teal-800',
  },
  'financial-reporting': {
    icon: 'solar:chart-square-bold-duotone',
    tile: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300',
    ring: 'hover:ring-cyan-300 dark:hover:ring-cyan-800',
  },
  'field-service': {
    icon: 'solar:clipboard-list-bold-duotone',
    tile: 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
    ring: 'hover:ring-orange-300 dark:hover:ring-orange-800',
  },
  'event_management': {
    icon: 'solar:calendar-bold-duotone',
    tile: 'bg-pink-100 text-pink-700 dark:bg-pink-950/60 dark:text-pink-300',
    ring: 'hover:ring-pink-300 dark:hover:ring-pink-800',
  },
  loyalty: {
    icon: 'solar:gift-bold-duotone',
    tile: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
    ring: 'hover:ring-rose-300 dark:hover:ring-rose-800',
  },
  iot: {
    icon: 'solar:server-square-bold-duotone',
    tile: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
    ring: 'hover:ring-sky-300 dark:hover:ring-sky-800',
  },
  ai: {
    icon: 'solar:cpu-bolt-bold-duotone',
    tile: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
    ring: 'hover:ring-violet-300 dark:hover:ring-violet-800',
  },
  communication: {
    icon: 'solar:chat-round-dots-bold-duotone',
    tile: 'bg-lime-100 text-lime-700 dark:bg-lime-950/60 dark:text-lime-300',
    ring: 'hover:ring-lime-300 dark:hover:ring-lime-800',
  },
  whatsapp: {
    icon: 'solar:chat-line-bold-duotone',
    tile: 'bg-green-100 text-green-700 dark:bg-green-950/60 dark:text-green-300',
    ring: 'hover:ring-green-300 dark:hover:ring-green-800',
  },
  payment_gateway: {
    icon: 'solar:card-bold-duotone',
    tile: 'bg-green-100 text-green-700 dark:bg-green-950/60 dark:text-green-300',
    ring: 'hover:ring-green-300 dark:hover:ring-green-800',
  },
  'ocr-bookkeeping': {
    icon: 'solar:document-add-bold-duotone',
    tile: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950/60 dark:text-yellow-300',
    ring: 'hover:ring-yellow-300 dark:hover:ring-yellow-800',
  },
}

const FALLBACK_VISUAL: ModuleVisual = {
  icon: 'solar:widget-6-bold-duotone',
  tile: 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300',
  ring: 'hover:ring-slate-300 dark:hover:ring-slate-700',
}

function visualFor(key: string): ModuleVisual {
  return MODULE_VISUALS[key] ?? FALLBACK_VISUAL
}

type Filter = 'all' | 'active' | 'core' | 'inactive'

const FILTERS: Array<{ key: Filter; label: string; icon: string }> = [
  { key: 'all', label: 'All', icon: 'solar:widget-6-bold-duotone' },
  { key: 'active', label: 'Active', icon: 'solar:check-circle-bold-duotone' },
  { key: 'core', label: 'Core', icon: 'solar:shield-check-bold-duotone' },
  { key: 'inactive', label: 'Inactive', icon: 'solar:close-circle-bold-duotone' },
]

export function ModulesPage() {
  const tenantId = localStorage.getItem('tenant_id') || 'default'
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')

  const { data: features, isLoading } = useQuery<Feature[]>({
    queryKey: ['features', tenantId],
    queryFn: async () => {
      const response = await api.get('/features', {
        headers: { 'X-Feature-Gate-Fresh': '1' },
      })
      return response.data
    },
  })

  const list = features ?? []

  const counts = useMemo(
    () => ({
      all: list.length,
      active: list.filter((f) => f.enabled).length,
      core: list.filter((f) => f.is_core).length,
      inactive: list.filter((f) => !f.enabled).length,
    }),
    [list],
  )

  const visible = useMemo(() => {
    const term = search.trim().toLowerCase()
    return list
      .filter((f) => {
        if (filter === 'active') return f.enabled
        if (filter === 'core') return f.is_core
        if (filter === 'inactive') return !f.enabled
        return true
      })
      .filter((f) => {
        if (!term) return true
        return (
          f.name.toLowerCase().includes(term) ||
          f.key.toLowerCase().includes(term) ||
          (f.description ?? '').toLowerCase().includes(term)
        )
      })
      .sort((a, b) => {
        if (a.is_core !== b.is_core) return a.is_core ? -1 : 1
        if (a.enabled !== b.enabled) return a.enabled ? -1 : 1
        return a.name.localeCompare(b.name)
      })
  }, [list, filter, search])

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-24">
        <div className="flex flex-col items-center gap-3 text-muted-foreground">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
          <span className="text-sm">Loading modules…</span>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Modules &amp; Add-ons</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Every capability entitled for this organization. Core modules are always on; add-ons can be
            enabled by your platform administrator.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-64">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search modules…"
              className="h-8 rounded-full pl-8 pr-3 text-xs"
            />
          </div>
          {FILTERS.map((f) => {
            const isActive = filter === f.key
            return (
              <button
                key={f.key}
                type="button"
                onClick={() => setFilter(f.key)}
                className={cn(
                  'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-all',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  isActive
                    ? 'border-primary/40 bg-primary/10 text-primary shadow-sm'
                    : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-muted/60 hover:text-foreground',
                )}
              >
                <Icon icon={f.icon} className="h-3.5 w-3.5" />
                {f.label}
                <span
                  className={cn(
                    'rounded-full px-1.5 py-0.5 text-[10px] font-semibold tabular-nums',
                    isActive ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground',
                  )}
                >
                  {counts[f.key]}
                </span>
              </button>
            )
          })}
        </div>
      </div>

      {/* Grid */}
      {visible.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-20 text-muted-foreground">
          <Icon icon="solar:widget-6-bold-duotone" className="mb-3 h-12 w-12 opacity-40" />
          <p className="text-sm font-medium">No modules match your search</p>
          <p className="mt-1 text-xs">Try a different term or clear the filter.</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
          {visible.map((feature) => {
            const visual = visualFor(feature.key)
            const isActive = feature.enabled
            return (
              <div
                key={feature.id}
                className={cn(
                  'group relative flex aspect-square flex-col overflow-hidden rounded-2xl border bg-card p-4 shadow-sm transition-all duration-200',
                  'ring-1 ring-transparent hover:-translate-y-0.5 hover:shadow-lg',
                  visual.ring,
                  !isActive && !feature.is_core && 'opacity-60 hover:opacity-100',
                )}
              >
                {/* Status chip */}
                <div className="absolute right-3 top-3">
                  {feature.is_core ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-slate-900/90 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm dark:bg-slate-100/90 dark:text-slate-900">
                      <Icon icon="solar:shield-check-bold" className="h-3 w-3" />
                      Core
                    </span>
                  ) : isActive ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">
                      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                      Active
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                      <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
                      Inactive
                    </span>
                  )}
                </div>

                {/* Icon tile */}
                <div
                  className={cn(
                    'flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl transition-transform duration-200 group-hover:scale-105',
                    visual.tile,
                  )}
                >
                  <Icon icon={visual.icon} className="h-7 w-7" />
                </div>

                {/* Name + description */}
                <div className="mt-3 flex min-h-0 flex-1 flex-col">
                  <h3 className="text-sm font-semibold leading-tight">{feature.name}</h3>
                  <p className="mt-1 line-clamp-3 text-xs leading-relaxed text-muted-foreground">
                    {feature.description || 'No description available.'}
                  </p>
                  <div className="mt-auto pt-2">
                    <span className="font-mono text-[10px] uppercase tracking-wide text-muted-foreground/70">
                      {feature.key}
                    </span>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
