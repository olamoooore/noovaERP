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
  games: `${STOREFRONT_BASE}/games`,
  membership: `${STOREFRONT_BASE}/membership`,
  checkout: `${STOREFRONT_BASE}/checkout`,
  waitingRoom: `${STOREFRONT_BASE}/waiting-room`,
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

/**
 * Generate the full URL for an individual arcade game arena.
 */
export function gamePlayRoute(gameId: string): string {
  return `${STOREFRONT_BASE}/games/${gameId}`;
}
