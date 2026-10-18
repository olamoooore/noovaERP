import { describe, it, expect } from 'vitest'
import {
  matchPastedProduct,
  parsePastedSalesRows,
  repeatSalesLineForRepricing,
} from '@/lib/salesDocumentLines'

// #343 (D6): rapid line entry — paste "SKU qty [price]" rows and repeat a
// previous document's lines. Copied prices/tax are never trusted: the engine
// re-prices and the server re-taxes; unknown or malformed rows are reported.

describe('parsePastedSalesRows (#343)', () => {
  it('parses tab-separated SKU, qty and optional price rows with their row numbers', () => {
    const result = parsePastedSalesRows('SKU-001\t2\t19.90\r\nSKU-002\t1\n\n  sku-003 \t 4 ')

    expect(result.errors).toEqual([])
    expect(result.rows).toEqual([
      { row: 1, code: 'SKU-001', quantity: 2, price: 19.9 },
      { row: 2, code: 'SKU-002', quantity: 1, price: null },
      { row: 4, code: 'sku-003', quantity: 4, price: null },
    ])
  })

  it('parses comma-separated rows too', () => {
    const result = parsePastedSalesRows('SKU-001,3,5.5\nSKU-002,1')

    expect(result.errors).toEqual([])
    expect(result.rows.map((row) => [row.code, row.quantity, row.price])).toEqual([
      ['SKU-001', 3, 5.5],
      ['SKU-002', 1, null],
    ])
  })

  it('skips a spreadsheet header row', () => {
    const result = parsePastedSalesRows('SKU\tQty\tPrice\nSKU-001\t2')

    expect(result.errors).toEqual([])
    expect(result.rows).toEqual([{ row: 2, code: 'SKU-001', quantity: 2, price: null }])
  })

  it('reports rows with an invalid quantity instead of guessing 1', () => {
    const result = parsePastedSalesRows('SKU-001\tabc\nSKU-002\t-2\nSKU-003\t2')

    expect(result.rows).toEqual([{ row: 3, code: 'SKU-003', quantity: 2, price: null }])
    expect(result.errors).toEqual([
      { row: 1, text: 'SKU-001\tabc', reason: 'Invalid quantity "abc"' },
      { row: 2, text: 'SKU-002\t-2', reason: 'Invalid quantity "-2"' },
    ])
  })

  it('reports a row without a SKU', () => {
    const result = parsePastedSalesRows('\t2')

    expect(result.rows).toEqual([])
    expect(result.errors).toEqual([{ row: 1, text: '\t2', reason: 'Missing SKU' }])
  })
})

describe('matchPastedProduct (#343)', () => {
  const candidates = [
    { id: 1, sku: 'YRN-10', barcode: null, name: 'Yarn 10' },
    { id: 2, sku: 'YRN-1', barcode: '9555000000011', name: 'Yarn 1' },
  ]

  it('matches an exact SKU case-insensitively, never a partial one', () => {
    expect(matchPastedProduct('yrn-1', candidates)?.id).toBe(2)
    expect(matchPastedProduct('YRN', candidates)).toBeNull()
  })

  it('matches an exact barcode', () => {
    expect(matchPastedProduct('9555000000011', candidates)?.id).toBe(2)
  })

  it('does not match a product only by its name', () => {
    expect(matchPastedProduct('Yarn 1', candidates)).toBeNull()
  })
})

describe('repeatSalesLineForRepricing (#343)', () => {
  it('drops the copied price, discount and tax so the engine and server re-resolve them', () => {
    const line = repeatSalesLineForRepricing(
      {
        product_id: 5,
        product: { sku: 'W-5', effective_tax_rate: 9 },
        description: 'Widget',
        quantity: 3,
        unit_price: 10,
        tax_rate: 7,
        tax_rule_id: 4,
        tax_treatment: 'exempt',
        discount_amount: 2,
        price_mode: 'carried',
      },
      8,
    )

    expect(line).toMatchObject({
      product_id: '5',
      sku: 'W-5',
      description: 'Widget',
      quantity: 3,
      unit_price: '',
      discount_amount: '',
      price_mode: 'auto',
      // The product's CURRENT tax, not the 7% booked on the old line.
      tax_rate: 9,
    })
    expect(line).not.toHaveProperty('tax_rule_id')
    expect(line).not.toHaveProperty('tax_treatment')
  })

  it('falls back to the default tax rate when the product carries none', () => {
    const line = repeatSalesLineForRepricing({ product_id: 6, description: 'Gadget', quantity: 1, unit_price: 66, tax_rate: 0 }, 8)

    expect(line.tax_rate).toBe(8)
    expect(line.unit_price).toBe('')
  })

  it('leaves a free-text line without a price — there is no engine price to resolve', () => {
    const line = repeatSalesLineForRepricing({ product_id: null, description: 'Consulting', quantity: 2, unit_price: 150 }, null)

    expect(line).toMatchObject({ product_id: '', description: 'Consulting', quantity: 2, unit_price: '' })
  })
})
