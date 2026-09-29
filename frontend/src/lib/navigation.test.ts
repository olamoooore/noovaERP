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

  it('has no nav entry pointing at the legacy /store SPA or /shop', () => {
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

/**
 * The new generic Storefront module must be navigable with its OWN
 * featureKey/permissions (FR-MOD-06): visible only when entitled + granted,
 * with the settings page split onto storefront.settings.
 */
describe('storefront admin nav (FR-MOD-06)', () => {
  const flat = flattenNav(baseNavItems)

  it('has a Storefront section with orders and settings', () => {
    const section = baseNavItems.find((item) => item.href === '/storefront/orders')
    expect(section).toBeDefined()
    expect(section?.featureKey).toBe('storefront')
    const hrefs = (section?.children ?? []).map((c) => c.href)
    expect(hrefs).toContain('/storefront/orders')
    expect(hrefs).toContain('/storefront/settings')
  })

  it('gates the section on storefront.view; children inherit it (server enforces storefront.settings — SF001)', () => {
    const section = baseNavItems.find((item) => item.href === '/storefront/orders')!
    expect(deriveNavPermission(section)).toBe('storefront.view')

    // flattenNav propagates the section's permission to children as
    // requiredPermission; the settings PAGE itself is permission-checked
    // server-side by RequirePermission:storefront.settings (verified by the
    // SF001 manager-deny leg), not by client nav inference.
    const flatSettings = flat.find((item) => item.href === '/storefront/settings')!
    expect(flatSettings.requiredPermission).toBe('storefront.view')
    expect(flatSettings.parentTitles).toContain('Storefront')
  })

  it('every /storefront nav href is an admin route we actually ship', () => {
    expect(
      flat
        .filter((item) => item.href.startsWith('/storefront'))
        .every((item) => ['/storefront/orders', '/storefront/settings'].includes(item.href)),
    ).toBe(true)
  })
})
