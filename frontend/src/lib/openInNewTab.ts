/**
 * Issue #317: open an in-app route in a new browser tab, so shortcuts on a
 * document form (customer list/profile, product list/new product) never
 * navigate away from — and lose — the document being edited.
 */
export function openAppPathInNewTab(path: string) {
  const base = String(import.meta.env.VITE_BASE_PATH || '/').replace(/\/+$/, '')
  const normalized = path.startsWith('/') ? path : `/${path}`
  window.open(`${base}${normalized}`, '_blank', 'noopener,noreferrer')
}
