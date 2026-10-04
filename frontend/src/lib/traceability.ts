/**
 * Issue #583 — lot/serial traceability report request helpers.
 *
 * Kept out of the page component so the page module only exports components
 * (react-refresh/only-export-components).
 */

export type TraceType = 'lot' | 'serial'

export interface TraceabilityQuery {
  type: TraceType
  value: string
}

export function traceabilityParams(query: TraceabilityQuery | null): Record<string, string> {
  if (!query) return {}
  return query.type === 'lot' ? { lot_number: query.value } : { serial_number: query.value }
}
