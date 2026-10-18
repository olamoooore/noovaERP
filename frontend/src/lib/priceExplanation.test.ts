import { describe, expect, it } from 'vitest'
import { explainLinePrice, priceChipLabel } from '@/lib/priceExplanation'

/**
 * Issue #342 (SALES-DIFF D5): every line's price is explained from the
 * stored pricing_trace — list, tier, FX rate, manual override reason —
 * without ever showing cost to roles that lack `pricelists.cost`.
 */
const money = (value: number) => value.toFixed(2)
const texts = (input: Parameters<typeof explainLinePrice>[0]) => explainLinePrice(input).map((item) => item.text)

const tierTrace = {
  source: 'pricelist',
  pricelist_code: 'VIP-USD',
  pricelist_name: 'VIP',
  pricelist_currency: 'USD',
  compute_type: 'percentage',
  base: 'list_price',
  percent: 10,
  min_quantity: 10,
  max_quantity: null,
  rate_used: 1,
  document_currency: 'USD',
  base_currency: 'MYR',
  exchange_rate: 4.71,
  rate_date: '2026-10-01',
}

describe('explainLinePrice', () => {
  it('explains a price-list tier line with the FX snapshot', () => {
    const notes = texts({ trace: tierTrace, money })
    expect(notes).toContain('Price list: VIP (VIP-USD)')
    expect(notes).toContain('Rule: 10% off list price')
    expect(notes).toContain('Quantity tier: qty ≥ 10')
    expect(notes).toContain('FX: 1 USD = 4.71 MYR on 1 Oct 2026')
    expect(priceChipLabel({ trace: tierTrace })).toBe('VIP-USD')
  })

  it('prefers the document FX snapshot passed by the page over the trace', () => {
    const notes = texts({ trace: tierTrace, currency: 'USD', baseCurrency: 'MYR', exchangeRate: 4.7, rateDate: '2026-10-02', money })
    expect(notes).toContain('FX: 1 USD = 4.7 MYR on 2 Oct 2026')
  })

  it('explains a list in another currency bridged to the document currency', () => {
    const notes = texts({
      trace: { ...tierTrace, pricelist_currency: 'EUR', rate_used: 1.08, min_quantity: 1 },
      money,
    })
    expect(notes).toContain('List priced in EUR, converted at 1 EUR = 1.08 USD')
    expect(notes.some((note) => note.startsWith('Quantity tier'))).toBe(false)
  })

  it('flags a manual price with its recorded override reason', () => {
    const trace = {
      source: 'manual',
      override_reason: 'Tender discount approved by GM',
      override_below_floor: true,
      override_floor_kind: 'below_percent_floor',
      resolved_unit_price: 90,
    }
    const notes = texts({ trace, money })
    expect(notes[0]).toBe('Manual price — typed on this line.')
    expect(notes).toContain('Reason: Tender discount approved by GM')
    expect(notes).toContain('Below the allowed price floor — override recorded with the reason above.')
    expect(notes).toContain('Engine price would have been 90.00')
    expect(priceChipLabel({ trace })).toBe('Manual')
  })

  it('uses the reason typed on the line before the trace is saved', () => {
    expect(texts({ priceMode: 'manual', overrideReason: 'Price match', money })).toContain('Reason: Price match')
  })

  it('treats a price typed over a resolved line as manual, not as the stale list price', () => {
    const notes = texts({ trace: tierTrace, priceMode: 'manual', money })
    expect(notes[0]).toBe('Manual price — typed on this line.')
    expect(notes.join(' ')).not.toMatch(/VIP-USD/)
    expect(priceChipLabel({ trace: tierTrace, priceMode: 'manual' })).toBe('Manual')
  })

  it('never shows cost or a below-cost flag to a role without pricelists.cost', () => {
    const costTrace = { source: 'pricelist', pricelist_code: 'COSTPLUS', compute_type: 'formula', base: 'cost', base_value: 40, markup_percent: 20 }
    const hidden = texts({ trace: costTrace, money, canSeeCost: false, margin: { amount: 8, percent: 16.67 } })
    expect(hidden.join(' ')).not.toMatch(/40\.00|margin|8\.00/i)
    expect(hidden).toContain('Rule: +20% markup on cost')

    const shown = texts({ trace: costTrace, money, canSeeCost: true, margin: { amount: 8, percent: 16.67 } })
    expect(shown).toContain('Rule: +20% markup on cost (40.00)')
    expect(shown).toContain('Margin: 8.00 (16.67%)')

    const belowCost = { source: 'manual', override_reason: 'Clearance', override_below_floor: true, override_floor_kind: 'below_cost' }
    expect(texts({ trace: belowCost, money, canSeeCost: false }).join(' ')).not.toMatch(/cost/i)
    expect(texts({ trace: belowCost, money, canSeeCost: true })).toContain('Below cost — override recorded with the reason above.')
  })

  it('explains catalogue, quantity-tier catalogue, carried, shipping and FOC lines', () => {
    expect(texts({ trace: { source: 'catalog', base: 'catalog' }, money })).toContain('Catalogue price (no price list applied)')
    expect(texts({ trace: { source: 'catalog', base: 'tier' }, money })).toContain('Catalogue quantity-tier price')
    expect(texts({ trace: { source: 'catalog_in_list', pricelist_code: 'VIP', base: 'catalog' }, money }))
      .toContain('No rule in price list VIP matched — catalogue price used')
    expect(texts({ trace: { source: 'carried' }, money })).toContain('Price carried over from the source document')
    expect(texts({ priceMode: 'carried', money })).toContain('Price carried over from the source document')
    expect(texts({ trace: { source: 'delivery_method', pricing_type: 'percentage', percentage: 5, untaxed_base: 200 }, money }))
      .toContain('Shipping: 5% of the untaxed order (200.00)')
    expect(texts({ trace: { source: 'delivery_method', pricing_type: 'fixed', fixed_price: 15, free: true, free_over_amount: 500 }, money }))
      .toContain('Free shipping — order is over 500.00')
    expect(texts({ trace: { source: 'foc', override_reason: 'Buy 10 get 1' }, money })).toEqual(['Free of charge (bonus scheme)', 'Reason: Buy 10 get 1'])
  })

  it('shows the list price struck through and the #553 net-price print mode', () => {
    const notes = texts({
      trace: tierTrace,
      unitPrice: 90,
      listPrice: 100,
      quantity: 2,
      discountAmount: 10,
      displayMode: 'net_price',
      money,
    })
    expect(notes).toContain('List price: 100.00')
    expect(notes).toContain('Printed as net price 85.00 (line discount folded in)')
    expect(texts({ trace: tierTrace, unitPrice: 90, quantity: 2, discountAmount: 10, displayMode: 'show_discount', money }).join(' '))
      .not.toMatch(/Printed as net/)
  })

  it('returns nothing to explain for a line without a trace or intent', () => {
    expect(explainLinePrice({ money })).toEqual([])
    expect(priceChipLabel({})).toBeNull()
  })
})
