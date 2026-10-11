/**
 * Asset path constants for the storefront.
 * Uses import.meta.env.BASE_URL to correctly prefix paths
 * when the app is deployed under a subpath (e.g., /noovaerp/).
 */

const BASE = import.meta.env.BASE_URL;

/** Storefront logo (header & footer) */
export const STOREFRONT_LOGO_URL = `${BASE}storefront-logo.png`;

/** Placeholder image for products with missing/broken images */
export const PLACEHOLDER_PRODUCT_URL = `${BASE}placeholder-product.svg`;
