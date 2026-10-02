// Shared formatting helpers for the Storefront admin pages. Kept in a
// non-component module so react-refresh is happy and the pages share one
// implementation.

export const money = (value?: string | null, currency?: string | null): string => {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  const amount = Number.isFinite(n) ? n.toFixed(2) : value
  return currency ? `${currency} ${amount}` : amount
}

export const prettyLabel = (s?: string | null): string =>
  (s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) || '—'

export const fmtDate = (iso?: string | null): string => {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString()
}
