import { describe, expect, it } from 'vitest'
import { baseNavItems, flattenNav, deriveNavPermission } from './navigation'

/**
 * FR-ARC-02 / MRD §2.6: the legacy commerce stack is archived — it must not
 * be discoverable in tenant navigation, and nothing may re-attach the
 * `ecommerce` featureKey. These assertions fail if anyone re-adds the old
 * 'Online Shop' entry (or maps the featureKey again).
 */
describe('legacy commerce nav freeze (FR-ARC-02)', () => {
  const flat = flattenNav(baseNavItems)

  it('has no nav entry pointing at the legacy /store SPA', () => {
    const offenders = flat.filter(
      (item) => item.href === '/store' || item.href.startsWith('/store/') || item.href === '/shop',
    )
    expect(offenders.map((o) => `${o.title} → ${o.href}`)).toEqual([])
  })

  it('carries no ecommerce featureKey anywhere in the nav tree', () => {
    expect(flat.filter((item) => item.featureKey === 'ecommerce')).toEqual([])
  })

  it('keeps WebsiteBuilder itself navigable (freeze removed commerce only, not the builder)', () => {
    expect(flat.some((item) => item.href === '/website-builder')).toBe(true)
  })

  it('deriveNavPermission no longer resolves a permission for ecommerce', () => {
    expect(deriveNavPermission({ title: 'x', href: '/y', icon: 'z', featureKey: 'ecommerce' } as never)).toBeUndefined()
  })
})
