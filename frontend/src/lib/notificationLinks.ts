/**
 * Single source of truth for turning a notification payload into a link.
 *
 * Notifications carry typed references (`primary` + `links`) describing the
 * records they are about. Both the top-bar bell and the notifications page use
 * these helpers, so adding a document type in one place makes every surface
 * route correctly — previously each handler only understood `invoice_id` and
 * silently did nothing for anything else (a delivery-order approval was
 * unclickable).
 *
 * Legacy payloads (a bare `invoice_id`, or `subject_type` + `subject_id`) are
 * still resolved so already-stored notifications keep working.
 */

export interface NotificationRef {
  type: string
  id: number | string
  /** Human reference, e.g. "DO-2026-0003" or "INV-2026-0007". */
  number?: string | null
  /** Display name for party/item references (customer, product). */
  name?: string | null
}

export interface NotificationTarget {
  route: string
  label: string
}

const REF_ROUTES: Record<string, (id: number | string) => string> = {
  invoice: (id) => `/invoices/${id}`,
  delivery_order: (id) => `/delivery-orders/${id}`,
  quotation: (id) => `/quotations/${id}`,
  sales_order: (id) => `/sales/orders/${id}`,
  customer: (id) => `/customers/${id}`,
  vendor: (id) => `/vendors/${id}`,
  product: (id) => `/products/${id}`,
  bill: (id) => `/bills/${id}`,
}

const REF_LABELS: Record<string, string> = {
  invoice: 'Invoice',
  delivery_order: 'Delivery order',
  quotation: 'Quotation',
  sales_order: 'Sales order',
  customer: 'Customer',
  vendor: 'Vendor',
  product: 'Product',
  bill: 'Bill',
}

/** Eloquent class names that older payloads put in `subject_type`. */
const CLASS_ALIASES: Array<[RegExp, string]> = [
  [/deliveryorder/i, 'delivery_order'],
  [/salesorder/i, 'sales_order'],
  [/quotation/i, 'quotation'],
  [/invoice/i, 'invoice'],
  [/customer/i, 'customer'],
  [/product/i, 'product'],
]

const normalizeRef = (ref: any): NotificationRef | null => {
  if (!ref || typeof ref !== 'object') return null
  const type = String(ref.type ?? '').toLowerCase().replace(/\s+/g, '_')
  const id = ref.id ?? ref.subject_id
  if (!type || id === undefined || id === null || id === '') return null
  return { type, id, number: ref.number ?? null, name: ref.name ?? null }
}

const refFromSubjectType = (subjectType: unknown, subjectId: unknown): NotificationRef | null => {
  if (subjectType === undefined || subjectType === null || subjectId === undefined || subjectId === null) {
    return null
  }
  const raw = String(subjectType)
  for (const [pattern, type] of CLASS_ALIASES) {
    if (pattern.test(raw)) return { type, id: subjectId as number | string }
  }
  const lowered = raw.toLowerCase()
  if (REF_ROUTES[lowered]) return { type: lowered, id: subjectId as number | string }
  return null
}

/** Route for a reference, or null when the type has no detail page yet. */
export function refRoute(ref: NotificationRef): string | null {
  return REF_ROUTES[ref.type]?.(ref.id) ?? null
}

/** Short label for a reference — its document number, name, or the type name. */
export function refLabel(ref: NotificationRef): string {
  return ref.number || ref.name || REF_LABELS[ref.type] || ref.type
}

/**
 * Every reference a notification carries, deduped, most relevant first
 * (`primary` first, then `links`). Falls back to legacy fields.
 */
export function notificationRefs(data: any): NotificationRef[] {
  if (!data || typeof data !== 'object') return []

  const candidates: NotificationRef[] = []

  const primary = normalizeRef(data.primary)
  if (primary) candidates.push(primary)

  if (Array.isArray(data.links)) {
    for (const link of data.links) {
      const ref = normalizeRef(link)
      if (ref) candidates.push(ref)
    }
  }

  // Legacy shapes.
  if (data.invoice_id) candidates.push({ type: 'invoice', id: data.invoice_id, number: data.invoice_number })
  if (data.delivery_order_id) {
    candidates.push({
      type: 'delivery_order',
      id: data.delivery_order_id,
      number: data.delivery_order_number,
    })
  }
  const fromSubject = refFromSubjectType(data.subject_type, data.subject_id)
  if (fromSubject) candidates.push(fromSubject)

  const seen = new Set<string>()
  return candidates.filter((ref) => {
    const key = `${ref.type}:${ref.id}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

/**
 * Where clicking a notification should take the user, plus a sensible label.
 * Returns null when nothing is resolvable (the row renders as plain text).
 */
export function resolveNotificationTarget(data: any): NotificationTarget | null {
  const refs = notificationRefs(data)
  for (const ref of refs) {
    const route = refRoute(ref)
    if (route) return { route, label: refLabel(ref) }
  }
  return null
}

/** References that have a route, for rendering as chips inside a row. */
export function linkableRefs(data: any): NotificationRef[] {
  return notificationRefs(data).filter((ref) => refRoute(ref) !== null)
}
