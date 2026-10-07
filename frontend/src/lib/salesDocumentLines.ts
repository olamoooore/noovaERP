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
  /** #526 (#512 CN3): the price already includes tax — tax is extracted, not added. */
  tax_inclusive?: boolean
  /** #526: credit-note mode — the invoice line this line credits (see SalesDocumentLineGrid `credit`). */
  credit?: SalesCreditLineInfo
}

/**
 * #526 (CN-UX5) / #522: what an invoice line allows to be credited. Already
 * credited counts POSTED credit notes only — the same rule the #513 per-line
 * cap enforces server-side at post.
 */
export interface SalesCreditLineInfo {
  invoice_line_id: number
  invoiced_quantity: number
  credited_quantity: number
  /** The invoice line's discount, which covers the whole invoiced quantity. */
  invoiced_discount: number
}

export const CREDIT_QUANTITY_EPSILON = 0.000001

/** Quantity still creditable on the invoice line (never negative). */
export const creditableQuantity = (credit: SalesCreditLineInfo): number =>
  Math.max(credit.invoiced_quantity - credit.credited_quantity, 0)

/** The line credits more than its invoice line still allows. */
export const exceedsCreditableQuantity = (line: SalesDocumentLine): boolean =>
  !!line.credit && toNumber(line.quantity) > creditableQuantity(line.credit) + CREDIT_QUANTITY_EPSILON

/** A credit of part of the invoiced quantity carries the matching share of the invoice line's discount. */
export function creditDiscountShare(credit: SalesCreditLineInfo, quantity: number | ''): number {
  if (!credit.invoiced_discount || !credit.invoiced_quantity) return 0
  return Math.round(credit.invoiced_discount * (toNumber(quantity) / credit.invoiced_quantity) * 100) / 100
}

/** Set the quantity to credit and the discount share that follows it. */
export function withCreditQuantity(line: SalesDocumentLine, quantity: number | ''): SalesDocumentLine {
  if (!line.credit) return { ...line, quantity }
  return { ...line, quantity, discount_amount: creditDiscountShare(line.credit, quantity) }
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
 * A tax-inclusive line (#512 CN3, #526) already carries its tax, so its total
 * is the discounted amount. Text rows (note/section) never contribute.
 */
export function getSalesDocumentLineTotal(line: SalesDocumentLine): number {
  if (line.line_type === 'note' || line.line_type === 'section') return 0
  const base = toNumber(line.quantity) * toNumber(line.unit_price)
  const discounted = base - toNumber(line.discount_amount)
  if (line.tax_inclusive) return discounted
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

/**
 * #343 (D6): rapid line entry — rows pasted from a spreadsheet as
 * "SKU ⇥ Qty [⇥ Price]" (tab- or comma-separated). The price column is read
 * only so it can be reported: pasted lines are priced by the engine and taxed
 * by the server, never at a copied price. Rows that cannot be read are
 * returned as errors with their row number, never silently dropped or guessed.
 */
export interface PastedSalesRow {
  /** 1-based row number in the pasted text. */
  row: number
  code: string
  quantity: number
  /** The pasted price (informational only — never applied to the line). */
  price: number | null
}

export interface PastedSalesRowError {
  row: number
  text: string
  reason: string
}

const PASTE_HEADER_CODE = /^(sku|code|item|product|barcode)( ?(code|no\.?|#))?$/i
const PASTE_HEADER_QTY = /^(qty|quantity)$/i

const parsePastedNumber = (raw: string, delimiter: string): number => {
  // A tab-separated cell may carry a thousands separator ("1,000").
  const cleaned = delimiter === '\t' ? raw.replace(/,/g, '') : raw
  return cleaned === '' ? NaN : Number(cleaned)
}

export function parsePastedSalesRows(text: string): { rows: PastedSalesRow[]; errors: PastedSalesRowError[] } {
  const rows: PastedSalesRow[] = []
  const errors: PastedSalesRowError[] = []
  let seenContent = false

  text.split(/\r?\n/).forEach((rawRow, index) => {
    const rowNumber = index + 1
    if (rawRow.trim() === '') return
    const delimiter = rawRow.includes('\t') ? '\t' : ','
    const cells = rawRow.split(delimiter).map((cell) => cell.trim())
    const [code = '', qtyCell = '', priceCell = ''] = cells

    // A spreadsheet header ("SKU ⇥ Qty ⇥ Price") as the first row is skipped.
    const isFirst = !seenContent
    seenContent = true
    if (isFirst && (PASTE_HEADER_CODE.test(code) || PASTE_HEADER_QTY.test(qtyCell)) && !Number.isFinite(parsePastedNumber(qtyCell, delimiter))) {
      return
    }

    if (code === '') {
      errors.push({ row: rowNumber, text: rawRow, reason: 'Missing SKU' })
      return
    }

    const quantity = qtyCell === '' ? 1 : parsePastedNumber(qtyCell, delimiter)
    if (!Number.isFinite(quantity) || quantity <= 0) {
      errors.push({ row: rowNumber, text: rawRow, reason: `Invalid quantity "${qtyCell}"` })
      return
    }

    const price = parsePastedNumber(priceCell, delimiter)
    rows.push({ row: rowNumber, code, quantity, price: Number.isFinite(price) && price >= 0 ? price : null })
  })

  return { rows, errors }
}

/** #343: the product whose SKU or barcode is exactly the pasted code (case-insensitive). */
export function matchPastedProduct<P extends { sku?: string | null; barcode?: string | null }>(
  code: string,
  candidates: P[],
): P | null {
  const needle = code.trim().toLowerCase()
  if (!needle) return null
  return candidates.find((candidate) =>
    [candidate.sku, candidate.barcode]
      .filter(Boolean)
      .some((value) => String(value).trim().toLowerCase() === needle),
  ) ?? null
}

/**
 * #343 (D6): a previous document's line brought into a new one ("Repeat last
 * invoice" / copy lines). Only what the customer bought is copied — product,
 * description and quantity. The old price, line discount and tax identity
 * are dropped: the line is `price_mode: 'auto'` so the pricing engine
 * re-prices it (and the server re-prices auto lines on save), and its tax
 * starts from the product's current rate (else the default) so the #302
 * SalesLineTaxResolver re-resolves it on save instead of a stale rate, rule
 * or explicit 0%. A free-text line has no engine price and comes in unpriced.
 */
export function repeatSalesLineForRepricing(
  previous: {
    product_id?: number | string | null
    product?: { sku?: string | null; effective_tax_rate?: unknown; tax_rate?: unknown; tracking_method?: string | null } | null
    description?: string | null
    description_html?: string | null
    quantity?: number | string | null
    [key: string]: unknown
  },
  defaultTaxRate?: number | null,
): Pick<SalesDocumentLine, 'product_id' | 'sku' | 'description' | 'quantity' | 'unit_price' | 'discount_amount' | 'tax_rate' | 'price_mode'> & {
  description_html: string
  tracking_method?: string
} {
  const hasProduct = previous.product_id !== null && previous.product_id !== undefined && previous.product_id !== ''
  const quantity = Number(previous.quantity)
  const productRate = Number(previous.product?.effective_tax_rate ?? previous.product?.tax_rate ?? 0)
  const fallbackRate = Number(defaultTaxRate ?? 0)
  const taxRate: number | '' = hasProduct && Number.isFinite(productRate) && productRate > 0
    ? productRate
    : (Number.isFinite(fallbackRate) && fallbackRate > 0 ? fallbackRate : '')

  return {
    product_id: hasProduct ? String(previous.product_id) : '',
    sku: previous.product?.sku ?? '',
    description: previous.description ?? '',
    description_html: previous.description_html || '',
    quantity: Number.isFinite(quantity) && quantity > 0 ? quantity : 1,
    unit_price: '',
    discount_amount: '',
    tax_rate: taxRate,
    ...(hasProduct ? { price_mode: 'auto' as const } : {}),
    ...(previous.product?.tracking_method ? { tracking_method: previous.product.tracking_method } : {}),
  }
}
