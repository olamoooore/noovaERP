// Tenant-facing Storefront admin pages (FR-ADM-01/02). Public shopper UX
// lives on the published pages (WebsiteBuilder + storefront shortcodes),
// NOT in this SPA. The /storefront/* admin paths are free: the legacy shop
// used /store and /shop (both removed from nav), and the public runtime
// lives under /api/v1/storefront on the backend host.
export { StorefrontOrdersPage } from './StorefrontOrdersPage'
export { StorefrontOrderDetailPage } from './StorefrontOrderDetailPage'
export { StorefrontSettingsPage } from './StorefrontSettingsPage'
