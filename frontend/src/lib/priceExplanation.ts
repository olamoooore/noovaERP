/**
 * Issue #342 (SALES-DIFF D5): plain-language explanation of how a sales line
 * price was reached, built only from what the pricing engine already stores
 * on the line (`pricing_trace`, see PricingService) plus the document's FX
 * snapshot and the #553 pricelist display mode.
 *
 * Cost rule (#338 / FR-3.6): the server already masks cost inputs for roles
 * without `pricelists.cost`; this formatter additionally never prints a cost
 * figure, a "below cost" flag or a margin unless `canSeeCost` is true.
 */

export type PriceExplanationKind =
  | 'source'
  | 'rule'
  | 'tier'
  | 'bridge'
  | 'fx'
  | 'manual'
  | 'reason'
  | 'floor'
  | 'list'
  | 'display'
  | 'margin'

export interface PriceExplanationItem {
  kind: PriceExplanationKind
  text: string
}

export interface PriceExplanationInput {
  trace?: Record<string, any> | null
  priceMode?: 'auto' | 'manual' | 'carried' | string | null
  /** Reason typed on the line before it is saved (the trace holds it after). */
  overrideReason?: string | null
  listPrice?: number | null
  unitPrice?: number | '' | null
  quantity?: number | '' | null
  discountAmount?: number | '' | null
  /** Document FX snapshot (wins over the copy on the trace). */
  currency?: string | null
  baseCurrency?: string | null
  exchangeRate?: number | null
  rateDate?: string | null
  /** #553 sales.pricelist.display: 'show_discount' | 'net_price'. */
  displayMode?: string | null
  /** Holder of `pricelists.cost` (#338). Defaults to false (fail closed). */
  canSeeCost?: boolean
  /** Server-computed margin, only ever sent to cost-capable roles. */
  margin?: { amount?: number | null; percent?: number | null } | null
  money?: (value: number) => string
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

const num = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

const fmtNumber = (value: number): string => String(Number(value.toFixed(6)))

/** '2026-10-01' → '1 Oct 2026' without timezone drift. */
export function formatRateDate(value: unknown): string | null {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value))
  if (!match) return String(value)
  const month = MONTHS[Number(match[2]) - 1]
  return month ? `${Number(match[3])} ${month} ${match[1]}` : String(value)
}

const BASE_LABELS: Record<string, string> = {
  list_price: 'list price',
  list_price_fallback: 'list price',
  cost: 'cost',
  other_pricelist: 'another price list',
}

function ruleText(trace: Record<string, any>, canSeeCost: boolean, money: (value: number) => string): string | null {
  const compute = String(trace.compute_type ?? '')
  const base = String(trace.base ?? 'list_price')
  const baseLabel = BASE_LABELS[base] ?? base.replace(/_/g, ' ')
  const percent = num(trace.percent)

  if (compute === 'fixed') {
    const fixed = num(trace.fixed_price)
    if (fixed === null) return null
    return `Rule: fixed price ${money(fixed)}${trace.pricelist_currency ? ` ${trace.pricelist_currency}` : ''}`
  }

  if (compute === 'percentage') {
    if (percent === null || percent === 0) return `Rule: ${baseLabel}`
    return percent > 0
      ? `Rule: ${fmtNumber(percent)}% off ${baseLabel}`
      : `Rule: +${fmtNumber(-percent)}% markup on ${baseLabel}`
  }

  if (compute === 'formula') {
    const parts: string[] = []
    const markup = num(trace.markup_percent)
    if (markup) parts.push(markup > 0 ? `+${fmtNumber(markup)}% markup` : `${fmtNumber(markup)}% markdown`)
    if (percent) parts.push(`${fmtNumber(percent)}% off`)
    const fixedDiscount = num(trace.fixed_discount)
    if (fixedDiscount) parts.push(`less ${money(fixedDiscount)}`)
    const baseValue = num(trace.base_value)
    const showValue = baseValue !== null && (base !== 'cost' || canSeeCost)
    const on = `${baseLabel}${showValue ? ` (${money(baseValue as number)})` : ''}`
    return parts.length ? `Rule: ${parts.join(', ')} on ${on}` : `Rule: ${on}`
  }

  return null
}

function lineSource(trace: Record<string, any> | null, priceMode: PriceExplanationInput['priceMode']): string {
  if (priceMode === 'manual') return 'manual'
  return String(trace?.source ?? (priceMode === 'carried' ? 'carried' : ''))
}

export function explainLinePrice(input: PriceExplanationInput): PriceExplanationItem[] {
  const money = input.money ?? ((value: number) => value.toFixed(2))
  const canSeeCost = input.canSeeCost === true
  // A price typed after the line was resolved is manual even though the
  // stored trace still describes the previous (engine) price.
  const trace = input.priceMode === 'manual' && input.trace?.source !== 'manual' ? null : (input.trace ?? null)
  const source = lineSource(input.trace ?? null, input.priceMode)
  const items: PriceExplanationItem[] = []
  const push = (kind: PriceExplanationKind, text: string | null) => {
    if (text) items.push({ kind, text })
  }

  if (!source) return items

  switch (source) {
    case 'manual': {
      push('manual', 'Manual price — typed on this line.')
      const reason = String(input.overrideReason || trace?.override_reason || '').trim()
      if (reason) push('reason', `Reason: ${reason}`)
      if (trace?.override_below_floor) {
        push('floor', trace.override_floor_kind === 'below_cost' && canSeeCost
          ? 'Below cost — override recorded with the reason above.'
          : 'Below the allowed price floor — override recorded with the reason above.')
      }
      const resolved = num(trace?.resolved_unit_price)
      if (resolved !== null) push('source', `Engine price would have been ${money(resolved)}`)
      break
    }
    case 'pricelist': {
      const name = trace?.pricelist_name
      const code = trace?.pricelist_code
      push('source', `Price list: ${name && code && name !== code ? `${name} (${code})` : (name || code || 'applied list')}`)
      push('rule', trace ? ruleText(trace, canSeeCost, money) : null)
      const min = num(trace?.min_quantity)
      const max = num(trace?.max_quantity)
      if ((min !== null && min > 1) || max !== null) {
        const from = min !== null && min > 1 ? `qty ≥ ${fmtNumber(min)}` : 'qty'
        push('tier', `Quantity tier: ${from}${max !== null ? ` up to ${fmtNumber(max)}` : ''}`)
      }
      break
    }
    case 'catalog':
    case 'catalog_in_list': {
      if (source === 'catalog_in_list') {
        const list = trace?.pricelist_code ? `price list ${trace.pricelist_code}` : 'the price list'
        push('source', `No rule in ${list} matched — catalogue price used`)
      } else {
        push('source', trace?.base === 'tier' ? 'Catalogue quantity-tier price' : 'Catalogue price (no price list applied)')
      }
      if (source === 'catalog_in_list' && trace?.base === 'tier') push('tier', 'Catalogue quantity-tier price')
      break
    }
    case 'carried':
      push('source', 'Price carried over from the source document')
      break
    case 'delivery_method': {
      if (trace?.free) {
        const over = num(trace.free_over_amount)
        push('source', over !== null ? `Free shipping — order is over ${money(over)}` : 'Free shipping')
      } else if (trace?.pricing_type === 'percentage') {
        const base = num(trace.untaxed_base)
        push('source', `Shipping: ${fmtNumber(num(trace.percentage) ?? 0)}% of the untaxed order${base !== null ? ` (${money(base)})` : ''}`)
      } else {
        const fixed = num(trace?.fixed_price)
        push('source', fixed !== null ? `Shipping: fixed charge ${money(fixed)}` : 'Shipping charge')
      }
      break
    }
    case 'foc': {
      push('source', 'Free of charge (bonus scheme)')
      const reason = String(trace?.override_reason ?? '').trim()
      if (reason) push('reason', `Reason: ${reason}`)
      break
    }
    default:
      push('source', `Source: ${source.replace(/_/g, ' ')}`)
  }

  // A list kept in another currency is bridged to the document currency.
  const documentCurrency = input.currency || trace?.document_currency || trace?.currency || null
  const listCurrency = trace?.pricelist_currency
  const bridgeRate = num(trace?.rate_used)
  if (source === 'pricelist' && listCurrency && documentCurrency && listCurrency !== documentCurrency && bridgeRate !== null) {
    push('bridge', `List priced in ${listCurrency}, converted at 1 ${listCurrency} = ${fmtNumber(bridgeRate)} ${documentCurrency}`)
  }

  // Document FX snapshot: 1 document currency = rate base currency.
  const baseCurrency = input.baseCurrency || trace?.base_currency || null
  const rate = num(input.exchangeRate) ?? num(trace?.exchange_rate)
  if (documentCurrency && baseCurrency && documentCurrency !== baseCurrency && rate !== null && rate > 0) {
    const date = formatRateDate(input.rateDate || trace?.rate_date)
    push('fx', `FX: 1 ${documentCurrency} = ${fmtNumber(rate)} ${baseCurrency}${date ? ` on ${date}` : ''}`)
  }

  const unit = num(input.unitPrice)
  const list = num(input.listPrice)
  if (list !== null && unit !== null && unit < list) push('list', `List price: ${money(list)}`)

  const quantity = num(input.quantity)
  const discount = num(input.discountAmount)
  if (input.displayMode === 'net_price' && unit !== null && quantity && quantity > 0 && discount && discount > 0) {
    push('display', `Printed as net price ${money(Math.round((unit - discount / quantity) * 10000) / 10000)} (line discount folded in)`)
  }

  const marginAmount = num(input.margin?.amount)
  if (canSeeCost && marginAmount !== null) {
    const pct = num(input.margin?.percent)
    push('margin', `Margin: ${money(marginAmount)}${pct !== null ? ` (${fmtNumber(pct)}%)` : ''}`)
  }

  return items
}

/** Short chip label for the line (null = nothing to explain). */
export function priceChipLabel(input: Pick<PriceExplanationInput, 'trace' | 'priceMode'>): string | null {
  const trace = input.trace ?? null
  const source = lineSource(trace, input.priceMode)
  switch (source) {
    case '':
      return null
    case 'manual':
      return 'Manual'
    case 'pricelist':
      return String(trace?.pricelist_code || trace?.pricelist_name || 'Price list')
    case 'catalog':
    case 'catalog_in_list':
      return trace?.base === 'tier' ? 'Tier' : 'Catalogue'
    case 'carried':
      return 'Carried'
    case 'delivery_method':
      return 'Shipping'
    case 'foc':
      return 'FOC'
    default:
      return source.replace(/_/g, ' ')
  }
}
