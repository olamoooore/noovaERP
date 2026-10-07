/**
 * #549 (SET-UX5): search, "changed from default" and export helpers for the
 * Sales Settings page. Defaults are NOT defined here — they come from the
 * server (`default_values` in GET /settings/sales, owned by the backend
 * SalesSettings accessor).
 */

export interface SalesSettingSearchEntry {
  /** Section key in SalesSettingsPage. */
  section: string
  sectionTitle: string
  /** The setting's visible title (also used to highlight its row). */
  title: string
  keywords?: string
}

/** Every setting on the page, by section, in on-screen order. */
export const SALES_SETTINGS_INDEX: SalesSettingSearchEntry[] = [
  ...(
    [
      ['Invoice number', 'format prefix sequence'],
      ['Quotation number', 'format prefix sequence quote'],
      ['Delivery order number', 'format prefix sequence do'],
      ['Sales order number', 'format prefix sequence so'],
      ['Credit note number', 'format prefix sequence'],
      ['Payment number', 'format prefix sequence receipt'],
      ['Reset sequence', 'yearly monthly never numbering'],
      ['Next sequence value', 'numbering counter'],
      ['Date display format', 'date'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'numbering', sectionTitle: 'Document Numbering', title, keywords })),
  ...(
    [
      ['Require approval by default', 'invoice review'],
      ['Approval expiry', 'days review'],
      ['Block posting without approval', 'enforce ledger'],
      ['Delivery orders require approval by default', 'do review'],
      ['Approval reviewers', 'approver people roles'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'approvals', sectionTitle: 'Approval Workflow', title, keywords })),
  ...(
    [
      ['Default validity', 'quotation quote valid until expiry days'],
      ['Default quotation terms', 'quotation quote conditions'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'quotations', sectionTitle: 'Quotations', title, keywords })),
  ...(
    [
      ['Default invoicing policy', 'sales order ordered delivered quantity bill'],
      ['Lock confirmed orders', 'sales order lock amend confirm'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'sales-orders', sectionTitle: 'Sales Orders', title, keywords })),
  ...(
    [
      ['Industry field profile', 'medical trading services general fields'],
      ['Allow custom invoice numbers', 'override number'],
      ['Default payment terms', 'due date days credit'],
      ['Allow partial payments', 'payment'],
      ['Auto-send on post', 'email customer'],
      ['Default notes', 'invoice notes'],
      ['Default terms', 'conditions'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'defaults', sectionTitle: 'Invoice Defaults', title, keywords })),
  ...(
    [
      ['Default salesperson', 'user'],
      ['Pricelist display', 'discount net price'],
      ['Default sales team per user', 'team'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'sales-defaults', sectionTitle: 'Sales Defaults', title, keywords })),
  ...(
    [
      ['Payment methods', 'payment method cash card cheque bank transfer duitnow paynow fpx reference deposit to bank account active'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'payment-methods', sectionTitle: 'Payment Methods', title, keywords })),
  ...(
    [
      ['Customer acceptance portal', 'accept link'],
      ['Require digital signature', 'sign'],
      ['Acceptance link validity', 'expiry days'],
      ['Payment link validity', 'expiry days online'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'payments', sectionTitle: 'Payments & Portal', title, keywords })),
  ...(
    [
      ['Default tax rate', 'vat gst sst'],
      ['Default tax treatment', 'zero rated exempt'],
      ['Prices include tax', 'inclusive exclusive'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'tax', sectionTitle: 'Tax & Pricing', title, keywords })),
  ...(
    [
      ['Enable cash rounding', 'round'],
      ['Rounding method', 'nearest up down'],
      ['Rounding step', 'precision cash'],
      ['Rounding account', 'ledger gain loss'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'rounding', sectionTitle: 'Cash Rounding', title, keywords })),
  ...(
    [
      ['Attach PDF to email', 'attachment'],
      ['Document type on send', 'final invoice acceptance copy'],
      ['CC internal email', 'copy address'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'delivery', sectionTitle: 'Delivery & Sending', title, keywords })),
  ...(
    [
      ['Send follow-ups automatically', 'reminder overdue dunning payment automatic daily'],
      ['Follow-up levels', 'reminder overdue days template manual call'],
    ] as const
  ).map(([title, keywords]) => ({ section: 'followups', sectionTitle: 'Payment Follow-ups', title, keywords })),
]

/** Settings whose title, section or keywords contain every word of the query. */
export function searchSalesSettings(query: string): SalesSettingSearchEntry[] {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean)
  if (words.length === 0) return []
  return SALES_SETTINGS_INDEX.filter((entry) => {
    const haystack = `${entry.title} ${entry.sectionTitle} ${entry.keywords ?? ''}`.toLowerCase()
    return words.every((w) => haystack.includes(w))
  })
}

function normalise(value: unknown): unknown {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value.trim()
  return value
}

/**
 * Does a saved value differ from its server default? `undefined` default
 * means the server reports none, so there is nothing to mark.
 */
export function isChangedFromDefault(value: unknown, defaultValue: unknown): boolean {
  if (defaultValue === undefined) return false
  const a = normalise(value)
  const b = normalise(defaultValue)
  if (typeof a === 'number' || typeof b === 'number') return Number(a) !== Number(b)
  return a !== b
}

/** Payload fields that are server state or directory data, not settings. */
const NON_SETTING_FIELDS: Record<string, string[]> = {
  cash_rounding: ['methods'],
  invoice: ['available_reviewers', 'approval_enforcement'],
  followup: ['actions'],
}

export interface NumberingFormatsPayload {
  date_format?: string
  [type: string]: unknown
}

/**
 * The export for support and tenant cloning: every saved sales setting plus
 * the document number formats. Leaves out the user directory, computed state
 * (approval enforcement), the read-only defaults and numbering counters.
 */
export function buildSalesSettingsExport(
  settings: Record<string, unknown>,
  formats?: NumberingFormatsPayload | null,
  exportedAt: Date = new Date(),
) {
  const salesSettings: Record<string, unknown> = {}
  for (const [group, value] of Object.entries(settings)) {
    if (group === 'default_values') continue
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      const copy: Record<string, unknown> = { ...(value as Record<string, unknown>) }
      for (const field of NON_SETTING_FIELDS[group] ?? []) delete copy[field]
      salesSettings[group] = copy
    } else {
      salesSettings[group] = value
    }
  }

  const numbering: Record<string, unknown> = {}
  if (formats) {
    for (const [type, info] of Object.entries(formats)) {
      if (type === 'date_format') continue
      if (info && typeof info === 'object' && 'current' in info) {
        const { current, reset_period } = info as { current?: string; reset_period?: string }
        numbering[type] = { format: current, ...(reset_period ? { reset_period } : {}) }
      }
    }
    if (formats.date_format) numbering.date_format = formats.date_format
  }

  return {
    type: 'noovaerp.sales_settings',
    version: 1,
    exported_at: exportedAt.toISOString(),
    sales_settings: salesSettings,
    document_numbering: numbering,
  }
}

/** Issue #328: one follow-up level — days overdue -> action + template. */
export interface FollowupLevel {
  days: number
  action: 'send' | 'manual'
  template: string
}

/** Issue #328: levels are valid when every row has a distinct day count of 1–365. */
export function followupLevelsError(levels: FollowupLevel[]): string | null {
  if (levels.length === 0) return 'Add at least one follow-up level.'
  const days = levels.map((level) => level.days)
  if (days.some((d) => !Number.isInteger(d) || d < 1 || d > 365)) return 'Days overdue must be a whole number from 1 to 365.'
  if (new Set(days).size !== days.length) return 'Each level needs a different number of days overdue.'
  return null
}
