import { Preset } from './types'

// =============================================================================
// SHOP THEME CONFIGURATION (Default values)
// These can be overridden via website settings in the builder
// =============================================================================

// =============================================================================
// SHOP PRESETS - Using ID-based CSS for GrapesJS editability
// =============================================================================

// Shop presets removed - using React EcommerceStorefront at /shop instead.
// The preset array is kept empty for backward compatibility.
export const shopPresets: Preset[] = []

// =============================================================================
// HELPER FUNCTIONS FOR DEFAULT PAGE CONTENT
// Used when auto-creating shop pages
// =============================================================================
// =============================================================================

/**
 * Get the default HTML content for a shop page type.
 * Instead of using fragile GrapesJS preset scripts, these pages redirect
 * to the React-based EcommerceStorefront at /shop which has full
 * product listing, cart, checkout (Stripe), and order success flows.
 */
export function getShopPageDefaultContent(pageType: 'shop' | 'cart' | 'checkout' | 'product'): { html: string; css: string } {
  // Map page types to their React storefront routes
  const routeMap: Record<string, string> = {
    shop: '/shop',
    cart: '/shop/cart',
    checkout: '/shop/checkout',
    product: '/shop/product',
  }

  const targetRoute = routeMap[pageType] || '/shop'
  const pageTitles: Record<string, string> = {
    shop: 'Shop',
    cart: 'Shopping Cart',
    checkout: 'Checkout',
    product: 'Product',
  }
  const title = pageTitles[pageType] || 'Shop'

  // Generate a simple redirect page that sends users to the React storefront
  const html = `
    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;font-family:system-ui,-apple-system,sans-serif;">
      <div style="text-align:center;">
        <div style="font-size:48px;margin-bottom:16px;">🛍️</div>
        <h2 style="font-size:24px;font-weight:600;color:#1a1a1a;margin:0 0 8px;">${title}</h2>
        <p style="color:#666;margin:0 0 24px;">Redirecting to our store...</p>
        <a href="${targetRoute}" style="display:inline-block;padding:12px 32px;background:#00b207;color:white;text-decoration:none;border-radius:8px;font-weight:500;font-size:16px;">Go to ${title}</a>
      </div>
    </div>
    <script>
      // Auto-redirect to the React storefront
      (function() {
        var basePath = '';
        // Detect base path from current URL (e.g. /noovaerp)
        var match = window.location.pathname.match(new RegExp('^/([^/]+)/w/'));
        if (match) basePath = '/' + match[1];
        window.location.href = basePath + '${targetRoute}';
      })();
    </script>
  `

  return { html, css: '' }
}

export default shopPresets
