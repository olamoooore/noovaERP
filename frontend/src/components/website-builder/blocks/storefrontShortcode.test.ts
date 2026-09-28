import { describe, expect, it } from 'vitest'
import {
  buildToken,
  coerceValues,
  defaultValues,
  parseTokenText,
  resolveComponentValues,
  type SfFunctionSchema,
} from './storefrontShortcode'

const productsSchema: SfFunctionSchema = {
  key: 'storefront.products',
  label: 'Product Grid',
  version: '1',
  attributes: [
    { name: 'ids', kind: 'list', default: [], max: 100 },
    { name: 'category', kind: 'slug', default: null, max_length: 120 },
    { name: 'search', kind: 'string', default: null, max_length: 80 },
    { name: 'sort', kind: 'enum', default: 'newest', enum: ['newest', 'price_asc', 'price_desc', 'name'] },
    { name: 'limit', kind: 'int', default: 12, min: 1, max: 48 },
    { name: 'page', kind: 'int', default: 1, min: 1, max: 500 },
    { name: 'columns', kind: 'enum', default: 4, enum: [2, 3, 4, 5] },
    { name: 'layout', kind: 'enum', default: 'grid', enum: ['grid', 'list'] },
    { name: 'in_stock_only', kind: 'bool', default: false },
    { name: 'new_only', kind: 'bool', default: false },
  ],
}

const guestCheckoutSchema: SfFunctionSchema = {
  key: 'storefront.checkout',
  label: 'Checkout',
  version: '1',
  attributes: [
    { name: 'guest', kind: 'bool', default: true },
    { name: 'terms_url', kind: 'string', default: null, max_length: 2048 },
  ],
}

describe('buildToken', () => {
  it('omits defaults so tokens stay minimal and deterministic', () => {
    expect(buildToken(productsSchema.key, productsSchema, defaultValues(productsSchema))).toBe('[storefront.products]')
  })

  it('serializes non-default values in schema order with quoting', () => {
    expect(
      buildToken(productsSchema.key, productsSchema, {
        ...defaultValues(productsSchema),
        category: 'mugs',
        limit: 8,
        columns: 3,
        in_stock_only: true,
      }),
    ).toBe('[storefront.products category="mugs" limit="8" columns="3" in_stock_only]')
  })

  it('expresses bool-off for true-defaults explicitly (bare flags only mean true)', () => {
    expect(buildToken(guestCheckoutSchema.key, guestCheckoutSchema, { guest: false, terms_url: '' })).toBe('[storefront.checkout guest="false"]')
    expect(buildToken(guestCheckoutSchema.key, guestCheckoutSchema, { guest: true, terms_url: '' })).toBe('[storefront.checkout]')
  })

  it('escapes markup characters in values', () => {
    const token = buildToken(productsSchema.key, productsSchema, { ...defaultValues(productsSchema), search: '<script>alert(1)</script>' })
    expect(token).not.toContain('<script>')
    expect(token).toContain('&lt;script&gt;')
  })
})

describe('parseTokenText (data-attrs fallback)', () => {
  it('parses quoted, unquoted, and bare-flag forms', () => {
    const parsed = parseTokenText('[storefront.products category="mugs" limit=8 in_stock_only]')
    expect(parsed?.key).toBe('storefront.products')
    expect(parsed?.raw).toEqual({ category: 'mugs', limit: '8', in_stock_only: true })
  })

  it('survives entity-escaped text-node round-trip', () => {
    const parsed = parseTokenText('[storefront.products search="a &amp; b &lt;i&gt;"]')
    expect(parsed?.raw.search).toBe('a & b <i>')
  })

  it('returns null for non-tokens', () => {
    expect(parseTokenText('<div>nothing here</div>')).toBeNull()
    expect(parseTokenText('[shop.products]')).toBeNull()
  })
})

describe('coerceValues mirrors the backend bounds', () => {
  it('clamps ints, rejects unknown enums, sanitizes id lists', () => {
    const out = coerceValues(productsSchema, {
      ids: '1, x, 2; DROP, 3, 4, 5, 6',
      limit: '999',
      page: '-12',
      sort: 'DROP TABLE',
      columns: '9',
      in_stock_only: 'true',
    } as never)

    expect(out.ids).toBe('1,3,4,5,6') // non-digit fragments dropped; digits kept
    expect(out.limit).toBe(48)
    expect(out.page).toBe(1)
    expect(out.sort).toBe('newest')
    expect(out.columns).toBe('4')
    expect(out.in_stock_only).toBe(true)
  })

  it('truncates to max_length', () => {
    const out = coerceValues(productsSchema, { ...defaultValues(productsSchema), search: 'x'.repeat(300) })
    expect((out.search as string).length).toBe(80)
  })
})

describe('resolveComponentValues priority', () => {
  it('prefers data-attrs JSON over token text', () => {
    const values = resolveComponentValues(
      '[storefront.products limit="12"]',
      JSON.stringify({ limit: 8 }),
      productsSchema,
    )
    expect(values.limit).toBe(8)
  })

  it('falls back to token text when data-attrs is corrupt', () => {
    const values = resolveComponentValues('[storefront.products limit="6" new_only]', '{not json', productsSchema)
    expect(values.limit).toBe(6)
    expect(values.new_only).toBe(true)
  })

  it('falls back to schema defaults when both are missing', () => {
    const values = resolveComponentValues('', undefined, productsSchema)
    expect(values.limit).toBe(12)
    expect(values.sort).toBe('newest')
  })
})

describe('round-trip: build → parse → build stability', () => {
  it('is idempotent (stable tokens ⇒ stable fragment cache keys)', () => {
    const values = { ...defaultValues(productsSchema), category: 'mugs', limit: 8, layout: 'list', in_stock_only: true }
    const t1 = buildToken(productsSchema.key, productsSchema, values)
    const reparsed = resolveComponentValues(t1, null, productsSchema)
    const t2 = buildToken(productsSchema.key, productsSchema, reparsed)
    expect(t2).toBe(t1)
  })
})
