/**
 * Shared asset paths for the ERP app.
 * Use VITE_BASE_PATH (not Vite's BASE_URL) so assets resolve correctly
 * in subpath deployments (e.g. /noovaerp/ on the multi-project server).
 */
const BASE = (import.meta.env.VITE_BASE_PATH || '/').replace(/\/+$/, '')

export const NOOVAERP_LOGO_URL = `${BASE}/noovaerplogo.png`