import { describe, expect, it } from 'vitest'
import {
  linkableRefs,
  notificationRefs,
  refLabel,
  resolveNotificationTarget,
} from './notificationLinks'

/**
 * Regression guard for "clicking a delivery-order approval notification does
 * nothing": both notification surfaces only understood `invoice_id`, so a DO
 * approval was unclickable even though the notification existed.
 */
describe('resolveNotificationTarget', () => {
  it('routes a delivery-order approval to the delivery order', () => {
    const target = resolveNotificationTarget({
      type: 'delivery_order_approval',
      primary: { type: 'delivery_order', id: 811, number: 'DO-2026-0003' },
      links: [
        { type: 'delivery_order', id: 811, number: 'DO-2026-0003' },
        { type: 'customer', id: 1, name: "Trinity Women's Specialists" },
      ],
    })

    expect(target).toEqual({ route: '/delivery-orders/811', label: 'DO-2026-0003' })
  })

  it('routes an invoice approval to the invoice', () => {
    const target = resolveNotificationTarget({
      primary: { type: 'invoice', id: 2791, number: 'INV-2026-0005' },
    })
    expect(target).toEqual({ route: '/invoices/2791', label: 'INV-2026-0005' })
  })

  it('falls back to the first link when there is no primary', () => {
    const target = resolveNotificationTarget({
      links: [
        { type: 'customer', id: 4, name: 'Demo Customer' },
        { type: 'customer', id: 4 },
      ],
    })
    expect(target?.route).toBe('/customers/4')
    expect(target?.label).toBe('Demo Customer')
  })

  it('resolves a sales order / quotation / product target', () => {
    expect(resolveNotificationTarget({ primary: { type: 'sales_order', id: 9, number: 'SO-1' } })?.route)
      .toBe('/sales/orders/9')
    expect(resolveNotificationTarget({ primary: { type: 'quotation', id: 3, number: 'Q-1' } })?.route)
      .toBe('/quotations/3')
    expect(resolveNotificationTarget({ primary: { type: 'product', id: 74, name: 'Scope' } })?.route)
      .toBe('/products/74')
  })

  it('keeps working for legacy payloads (invoice_id / subject_type)', () => {
    expect(resolveNotificationTarget({ invoice_id: 42 })?.route).toBe('/invoices/42')
    expect(
      resolveNotificationTarget({ subject_type: 'App\\Models\\Invoice', subject_id: 43 })?.route,
    ).toBe('/invoices/43')
    expect(
      resolveNotificationTarget({ subject_type: 'Modules\\Sales\\Models\\DeliveryOrder', subject_id: 7 })?.route,
    ).toBe('/delivery-orders/7')
  })

  it('returns null when there is nothing to open', () => {
    expect(resolveNotificationTarget({})).toBeNull()
    expect(resolveNotificationTarget({ subject: 'hello', body: 'world' })).toBeNull()
    expect(resolveNotificationTarget(null)).toBeNull()
  })
})

describe('notificationRefs', () => {
  it('dedupes the primary reference out of the links list', () => {
    const refs = notificationRefs({
      primary: { type: 'delivery_order', id: 811, number: 'DO-1' },
      links: [
        { type: 'delivery_order', id: 811, number: 'DO-1' },
        { type: 'invoice', id: 5, number: 'INV-5' },
      ],
    })
    expect(refs.map((r) => `${r.type}:${r.id}`)).toEqual(['delivery_order:811', 'invoice:5'])
  })

  it('exposes only routeable references for chip rendering', () => {
    const refs = linkableRefs({
      links: [{ type: 'delivery_order', id: 1 }, { type: 'mystery', id: 2 }],
    })
    expect(refs).toHaveLength(1)
    expect(refs[0].type).toBe('delivery_order')
  })
})

describe('refLabel', () => {
  it('prefers the document number, then the name, then the type', () => {
    expect(refLabel({ type: 'invoice', id: 1, number: 'INV-1', name: 'x' })).toBe('INV-1')
    expect(refLabel({ type: 'customer', id: 1, name: 'ACME' })).toBe('ACME')
    expect(refLabel({ type: 'product', id: 1 })).toBe('Product')
  })
})
