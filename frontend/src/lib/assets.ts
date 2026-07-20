/**
 * Shared asset paths for the ERP app.
 * The logo is served from /noovaerp/ in production (multi-project Nginx).
 */
export const NOOVAERP_LOGO_URL = import.meta.env.PROD
  ? '/noovaerp/noovaerplogo.png'
  : '/noovaerplogo.png'