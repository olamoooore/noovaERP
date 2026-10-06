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
