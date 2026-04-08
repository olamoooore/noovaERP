/**
 * Storefront path configuration.
 * All storefront routes are nested under this base path in App.tsx.
 */
export const STOREFRONT_BASE = '/store';

/**
 * Generate absolute storefront path.
 */
export function storefrontPath(path: string): string {
  if (path === '/') return STOREFRONT_BASE;
  return `${STOREFRONT_BASE}${path}`;
}

/**
 * Pre-defined storefront routes.
 */
export const storefrontRoutes = {
  home: STOREFRONT_BASE,
  shop: `${STOREFRONT_BASE}/shop`,
  events: `${STOREFRONT_BASE}/events`,
  membership: `${STOREFRONT_BASE}/membership`,
  checkout: `${STOREFRONT_BASE}/checkout`,
  account: `${STOREFRONT_BASE}/account`,
  login: `${STOREFRONT_BASE}/login`,
  faq: `${STOREFRONT_BASE}/faq`,
  shipping: `${STOREFRONT_BASE}/shipping`,
  returns: `${STOREFRONT_BASE}/returns`,
  about: `${STOREFRONT_BASE}/about`,
  contact: `${STOREFRONT_BASE}/contact`,
} as const;

/**
 * Generate the full URL for a product detail page.
 */
export function productRoute(id: string | number): string {
  return `${STOREFRONT_BASE}/product/${id}`;
}
