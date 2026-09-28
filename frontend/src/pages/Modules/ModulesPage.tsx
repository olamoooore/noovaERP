import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import { Dialog, DialogContent, DialogFooter, DialogTitle } from '@/components/ui/dialog'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { cn } from '@/lib/utils'
import { useFeatureAccess } from '@/hooks/use-feature-access'

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

const normalizeKey = (key: string) => key.toLowerCase().replace(/[\s_]+/g, '-')

/**
 * Marketing copy for the module detail popup: a tagline plus the concrete
 * things the module gives you — this is also how add-ons get sold.
 */
const MODULE_CONTENT: Record<string, { tagline: string; features: string[] }> = {
  core: {
    tagline: 'The foundation every organization runs on.',
    features: ['Users, roles and granular permissions', 'Tenant-wide settings and preferences', 'Approval policies and audit trail', 'Dashboards, search and navigation'],
  },
  accounting: {
    tagline: 'Your books, always balanced and close-ready.',
    features: ['General ledger with double-entry posting', 'Accounts receivable and payables with aging', 'Bank reconciliation and cash controls', 'Lock dates, journals and financial close'],
  },
  inventory: {
    tagline: 'Know exactly what you have, where, and what it cost.',
    features: ['Real-time stock by warehouse', 'Lot, batch and serial tracking with expiry', 'Transfers, adjustments and stock counts', 'Purchase orders and goods receipts'],
  },
  contact: {
    tagline: 'One clean record for every customer and contact.',
    features: ['Customer and vendor master data', 'Contacts, addresses and communication history', 'Status, categories and tags', 'Import and de-duplication tools'],
  },
  sales: {
    tagline: 'From quote to cash without the spreadsheet chase.',
    features: ['Quotations, sales orders and delivery orders', 'Linked, staged invoicing', 'Approval gates and credit checks', 'Customer and product performance reporting'],
  },
  pos: {
    tagline: 'Fast counter sales that stay perfectly in sync.',
    features: ['Touch-friendly checkout terminal', 'Cash sessions and shift control', 'Receipts, returns and exchanges', 'Offline queue with automatic sync'],
  },
  'website-builder': {
    tagline: 'Your own website, no developer required.',
    features: ['Drag-and-drop page designer', 'Blog, SEO and media management', 'Publish to your own domain', 'Lead and enquiry capture'],
  },
  ecommerce: {
    tagline: 'Sell online with a storefront that matches your brand.',
    features: ['Product catalogue and online checkout', 'Web orders flowing into stock and invoices', 'Customer accounts and order history', 'Shipping and fulfilment status'],
  },
  'template-builder': {
    tagline: 'Every document, on your letterhead.',
    features: ['Design invoices, statements and receipts', 'Brand colours, logos and layouts', 'Versioning with publish and restore', 'Consistent PDFs and email templates'],
  },
  hrm: {
    tagline: 'Everything you need to run your people operations.',
    features: ['Employee records and org structure', 'Attendance, leave and holidays', 'Payroll runs and payslips', 'HR reports and compliance'],
  },
  whatsapp: {
    tagline: 'Reach customers where they already are.',
    features: ['Send invoices and statements', 'Payment and delivery reminders', 'Template message management', 'Delivery status tracking'],
  },
  'event-management': {
    tagline: 'Plan, sell and run events end to end.',
    features: ['Events, sessions and venues', 'Ticketing and bookings', 'Staff rosters and live operations', 'Attendee feedback and reports'],
  },
  'field-service': {
    tagline: 'Schedule work, dispatch teams, close jobs.',
    features: ['Work orders and service scheduling', 'Technician assignment and routes', 'On-site checklists and evidence', 'Service performance analytics'],
  },
  'financial-reporting': {
    tagline: 'See the numbers that drive decisions.',
    features: ['Profit & loss, balance sheet and cash flow', 'Trial balance and general ledger', 'Aged receivables and payables', 'Excel, PDF and Word exports'],
  },
  'ocr-bookkeeping': {
    tagline: 'Snap a document, get a journal.',
    features: ['OCR capture of supplier invoices', 'Auto-matching to bills and accounts', 'Smart field extraction and review', 'Faster, cleaner period close'],
  },
  'payment-gateway': {
    tagline: 'Get paid online, reconciled automatically.',
    features: ['Pay-by-link and hosted checkout', 'Cards and local payment methods', 'Automatic allocation to invoices', 'Gateway transaction audit trail'],
  },
  loyalty: {
    tagline: 'Turn first-time buyers into regulars.',
    features: ['Points earning and redemption', 'Tiers and rewards programmes', 'Customer loyalty statements', 'Campaign performance reporting'],
  },
  crm: {
    tagline: 'Never let a lead go cold.',
    features: ['Leads and pipeline stages', 'Activities, calls and follow-ups', 'Conversion analytics', 'Complete customer 360 view'],
  },
  iot: {
    tagline: 'Connect devices to your operations.',
    features: ['Terminal and device registry', 'Live monitoring dashboards', 'Threshold alerts and notifications', 'Device health and usage reporting'],
  },
  ai: {
    tagline: 'A copilot for your business data.',
    features: ['Ask questions in plain language', 'Drafting and summarisation', 'Insight suggestions across modules', 'Chat right inside your workflow'],
  },
  'financial-close': {
    tagline: 'Close the period with confidence.',
    features: ['Close checklists and task owners', 'Balance checks and findings inbox', 'Sign-offs and full audit trail', 'Deadline tracking and reminders'],
  },
  fulfillment: {
    tagline: 'Pick, pack and ship without errors.',
    features: ['Order fulfilment queue', 'Picking, packing and handover', 'Carrier and tracking data', 'Exception handling and backorders'],
  },
  storefront: {
    tagline: 'Embed a shop inside your website.',
    features: ['Drop-in storefront shortcodes', 'Customer account tokens', 'Catalogue browsing and checkout', 'Order history and reordering'],
  },
  'accounting-foundation': {
    tagline: 'Set up accounting the way your business works.',
    features: ['Chart of accounts foundation', 'Opening balances and imports', 'Accounting controls and policies', 'Country-ready configuration'],
  },
  accountingcountry: {
    tagline: 'Tax and e-invoicing compliance, handled.',
    features: ['Malaysia MyInvois e-invoicing', 'Submission status tracking', 'Compliance error resolution', 'Country-specific tax rules'],
  },
  communication: {
    tagline: 'Messages and notifications in one place.',
    features: ['In-app inbox and alerts', 'Email and SMS outbox', 'Reusable message templates', 'Delivery and read status'],
  },
}

type Filter = 'all' | 'active' | 'core' | 'inactive'

const FILTERS: Array<{ key: Filter; label: string; icon: string }> = [
  { key: 'all', label: 'All', icon: 'solar:widget-6-bold-duotone' },
  { key: 'active', label: 'Active', icon: 'solar:check-circle-bold-duotone' },
  { key: 'core', label: 'Core', icon: 'solar:shield-check-bold-duotone' },
  { key: 'inactive', label: 'Inactive', icon: 'solar:close-circle-bold-duotone' },
]

export function ModulesPage() {
  const [search, setSearch] = useState('')
  const [filter, setFilter] = useState<Filter>('all')

  // The hub is the full platform catalogue, but the Active badge must reflect
  // what THIS tenant actually owns (platform enabled flag + tenant blocks).
  const { features, isLoading, isFeatureEnabled } = useFeatureAccess()
  const [selected, setSelected] = useState<any | null>(null)
  const list = features ?? []
  const isModuleActive = (feature: any) => Boolean(feature.enabled) && isFeatureEnabled(feature.key)

  const counts = {
    all: list.length,
    active: list.filter((f) => isModuleActive(f)).length,
    core: list.filter((f) => f.is_core).length,
    inactive: list.filter((f) => !isModuleActive(f)).length,
  }

  const visible = (() => {
    const term = search.trim().toLowerCase()
    return list
      .filter((f) => {
        if (filter === 'active') return isModuleActive(f)
        if (filter === 'core') return f.is_core
        if (filter === 'inactive') return !isModuleActive(f)
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
        const aActive = isModuleActive(a)
        const bActive = isModuleActive(b)
        if (aActive !== bActive) return aActive ? -1 : 1
        return (a.name ?? a.key).localeCompare(b.name ?? b.key)
      })
  })()

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
            Every capability available on the platform. Active modules are enabled for this
            organization; other modules are managed by your platform administrator.
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
            const isActive = isModuleActive(feature)
            return (
              <button
                key={feature.id}
                type="button"
                onClick={() => setSelected(feature)}
                className={cn(
                  'group relative flex aspect-square w-full flex-col overflow-hidden rounded-2xl border bg-card p-4 text-left shadow-sm transition-all duration-200',
                  'ring-1 ring-transparent hover:-translate-y-0.5 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  visual.ring,
                  !isActive && 'opacity-60 hover:opacity-100',
                )}
              >
                {/* Status chip */}
                <div className="absolute right-3 top-3">
                  {feature.is_core && isActive ? (
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
              </button>
            )
          })}
        </div>
      )}

      {/* Module detail popup — a mini marketing card: what you get, and status */}
      <Dialog open={Boolean(selected)} onOpenChange={(open) => !open && setSelected(null)}>
        <DialogContent className="max-w-lg gap-0 overflow-hidden rounded-2xl p-0">
          {selected && (() => {
            const content = MODULE_CONTENT[normalizeKey(selected.key)]
            const active = isModuleActive(selected)
            return (
              <>
                <div className="bg-gradient-to-br from-primary/12 via-primary/5 to-transparent p-5">
                  <div className="flex items-start gap-4">
                    <div className={cn('flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl shadow-sm', visualFor(selected.key).tile)}>
                      <Icon icon={visualFor(selected.key).icon} className="h-7 w-7" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle className="text-lg">{selected.name}</DialogTitle>
                        {active ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            Active
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
                            Inactive
                          </span>
                        )}
                        {selected.is_core && (
                          <span className="inline-flex items-center gap-1 rounded-full bg-slate-900/90 px-2 py-0.5 text-[11px] font-semibold text-white dark:bg-slate-100/90 dark:text-slate-900">
                            <Icon icon="solar:shield-check-bold" className="h-3 w-3" />
                            Core
                          </span>
                        )}
                      </div>
                      <p className="mt-1 text-sm font-medium text-primary">
                        {content?.tagline ?? selected.description ?? 'A platform module.'}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="space-y-4 px-5 pt-4">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What you get</p>
                    <ul className="mt-2.5 space-y-2">
                      {(content?.features ?? [
                        'Enablement is managed by your platform administrator.',
                        'Ask about adding this module to your plan.',
                      ]).map((feature) => (
                        <li key={feature} className="flex items-start gap-2.5 text-sm">
                          <Icon icon="solar:check-circle-bold" className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                          <span>{feature}</span>
                        </li>
                      ))}
                    </ul>
                  </div>

                  <p className={cn(
                    'rounded-xl px-3.5 py-2.5 text-xs',
                    active
                      ? 'bg-emerald-50/70 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                      : 'bg-amber-50/70 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
                  )}>
                    {active
                      ? 'Enabled for your organization — it is live in your workspace.'
                      : 'Not in your plan yet. Enablement is managed by your platform administrator.'}
                  </p>
                </div>

                <DialogFooter className="px-5 pb-5 pt-4">
                  <HeaderActionButton variant="outline" onClick={() => setSelected(null)}>
                    Close
                  </HeaderActionButton>
                </DialogFooter>
              </>
            )
          })()}
        </DialogContent>
      </Dialog>
    </div>
  )
}
