/**
 * Issue #314: shared line model + pure operations for sales-document editors
 * (quotation create/edit, invoice, ...).
 *
 * These were copied three times (QuotationCreatePage, QuotationEditPage,
 * CreateInvoicePage) and drifted: the edit page had no SKU, the create page
 * had no discount column, and each page owned its own duplicate/move/reset
 * implementation. The shape and operations live here once; the shared
 * SalesDocumentLineGrid renders them.
 */

export type SalesLineType = 'product' | 'note' | 'section'

/** Pricing intent that travels with a line (FR-6.2/FR-6.3). */
export type SalesPriceMode = 'auto' | 'manual' | 'carried'

export interface SalesDocumentLine {
  line_type?: SalesLineType
  product_id: string
  sku: string
  model_number: string
  description: string
  product_notes: string
  quantity: number | ''
  unit_price: number | ''
  tax_rate: number | ''
  discount_amount: number | ''
  price_mode?: SalesPriceMode
  override_reason?: string | null
  pricing_source?: string | null
  list_price?: number | null
  pricing_trace?: Record<string, any> | null
  pricing_currency?: string | null
  pricing_base_currency?: string | null
  /** Issue #409: the shipping line added from a delivery method. */
  is_delivery?: boolean
}

export const emptySalesDocumentLine = (): SalesDocumentLine => ({
  line_type: 'product',
  product_id: '',
  sku: '',
  model_number: '',
  description: '',
  product_notes: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
  discount_amount: '',
})

/**
 * A fully-empty, untouched product row — ignored consistently by validation
 * and the payload filter, and replaced (not appended to) by catalogue adds
 * (#290). SKU counts as content so a catalogue row without a description is
 * still a real line.
 */
export const isBlankSalesDocumentLine = (line: SalesDocumentLine): boolean =>
  (line.line_type ?? 'product') === 'product'
  && !line.product_id
  && !(line.description ?? '').trim()
  && !(line.sku ?? '').trim()

const toNumber = (value: number | '' | null | undefined): number => {
  if (value === '' || value === null || value === undefined) return 0
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * Line total = (qty × price − line discount) + tax on the discounted base.
 * Text rows (note/section) never contribute.
 */
export function getSalesDocumentLineTotal(line: SalesDocumentLine): number {
  if (line.line_type === 'note' || line.line_type === 'section') return 0
  const base = toNumber(line.quantity) * toNumber(line.unit_price)
  const discounted = base - toNumber(line.discount_amount)
  return discounted + discounted * (toNumber(line.tax_rate) / 100)
}

export function duplicateSalesDocumentLine<T>(lines: T[], index: number): T[] {
  const target = lines[index]
  if (!target) return lines
  const updated = [...lines]
  updated.splice(index + 1, 0, { ...target })
  return updated
}

export function moveSalesDocumentLine<T>(lines: T[], index: number, direction: 'up' | 'down'): T[] {
  const targetIndex = direction === 'up' ? index - 1 : index + 1
  if (targetIndex < 0 || targetIndex >= lines.length) return lines
  const updated = [...lines]
  ;[updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]]
  return updated
}

/** Never deletes the last remaining row — reset it instead. */
export function removeSalesDocumentLine<T>(lines: T[], index: number): T[] {
  if (lines.length <= 1) return lines
  return lines.filter((_, currentIndex) => currentIndex !== index)
}

/** Clear a line in place so a lone row can be emptied without deleting it. */
export function resetSalesDocumentLine<T>(lines: T[], index: number, replacement: T): T[] {
  return lines.map((line, currentIndex) => (currentIndex === index ? replacement : line))
}

/**
 * #1006: bonus-scheme FOC pairing on the invoice editor. A FOC (free) line
 * carries `is_foc` and points at its paid source line through a stable client
 * key (`source_line_key` → the source's `line_key`), so the link survives
 * reorders and deletes; on save it is sent as `source_line_index` (the
 * source's position in the submitted lines array).
 */
export interface FocPairableLine {
  is_foc?: boolean
  line_key?: string
  source_line_key?: string | null
}

/** Client key for a line loaded from the API (stable across edits). */
export const focLineKeyForId = (id: number | string): string => `line-${id}`

/** A FOC line pointing at the given paid line. */
const isFocChildOf = (line: FocPairableLine, source: FocPairableLine): boolean =>
  Boolean(line.is_foc && source.line_key && line.source_line_key === source.line_key)

/**
 * Keep every FOC line directly beneath its paid source line (children in
 * their current relative order). A FOC line whose source is not on the
 * document stays where it is.
 */
export function keepFocLinesUnderSource<T extends FocPairableLine>(lines: T[]): T[] {
  const keys = new Set(lines.map((line) => line.line_key).filter(Boolean))
  const isAttached = (line: T) =>
    Boolean(line.is_foc && line.source_line_key && keys.has(line.source_line_key) && line.source_line_key !== line.line_key)
  const result: T[] = []
  for (const line of lines) {
    if (isAttached(line)) continue
    result.push(line)
    for (const child of lines) {
      if (child !== line && isAttached(child) && isFocChildOf(child, line)) result.push(child)
    }
  }
  return result
}

/**
 * Move up/down by whole blocks: a paid line moves together with its FOC
 * children (moving a FOC line moves its whole block).
 */
export function moveSalesDocumentLineWithFoc<T extends FocPairableLine>(
  lines: T[],
  index: number,
  direction: 'up' | 'down',
): T[] {
  const ordered = keepFocLinesUnderSource(lines)
  const target = lines[index]
  if (!target) return lines
  const blocks: T[][] = []
  for (const line of ordered) {
    const previous = blocks[blocks.length - 1]
    const attachedToPrevious = Boolean(previous && line.is_foc && previous.some((member) => isFocChildOf(line, member)))
    if (attachedToPrevious) previous.push(line)
    else blocks.push([line])
  }
  const blockIndex = blocks.findIndex((block) => block.includes(target))
  const swapWith = direction === 'up' ? blockIndex - 1 : blockIndex + 1
  if (blockIndex < 0 || swapWith < 0 || swapWith >= blocks.length) return lines
  ;[blocks[blockIndex], blocks[swapWith]] = [blocks[swapWith], blocks[blockIndex]]
  return blocks.flat()
}

/** Remove a line; removing a paid line also removes its FOC children. */
export function removeSalesDocumentLineWithFoc<T extends FocPairableLine>(lines: T[], index: number): T[] {
  const target = lines[index]
  if (!target) return lines
  const remaining = lines.filter((line, currentIndex) => currentIndex !== index && !isFocChildOf(line, target))
  // Never delete the last remaining row (same rule as removeSalesDocumentLine).
  return remaining.length === 0 ? lines : remaining
}

/**
 * The FOC fields a submitted line carries: `is_foc` and the index of its paid
 * source line within the submitted array (omitted when the source is not sent).
 */
export function focPayloadFields<T extends FocPairableLine>(
  submitted: T[],
  line: T,
): { is_foc?: boolean; source_line_index?: number } {
  if (!line.is_foc) return {}
  const sourceIndex = line.source_line_key
    ? submitted.findIndex((candidate) => candidate !== line && candidate.line_key === line.source_line_key)
    : -1
  return sourceIndex >= 0 ? { is_foc: true, source_line_index: sourceIndex } : { is_foc: true }
}
