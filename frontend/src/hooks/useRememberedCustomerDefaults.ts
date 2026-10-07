import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

/**
 * Issue #346 (D9): per-customer remembered choices.
 *
 * The server derives them from the customer's most recent document of the
 * same type in the same legal entity (SalesDefaultsService) and also applies
 * them itself to any field a create request leaves out — so this hook only
 * pre-fills the form's visible, still-blank fields and feeds the "remembered
 * from last …" hint. Nothing is kept in browser storage.
 */
export type RememberedDocument = 'quotation' | 'sales_order' | 'invoice'

export interface RememberedDefaults {
  document: RememberedDocument
  source: { type: RememberedDocument; id: number; number: string | null; date: string | null } | null
  values: Record<string, unknown>
}

export function useRememberedCustomerDefaults(customerId: string, document: RememberedDocument, enabled = true) {
  return useQuery({
    queryKey: ['sales-settings', 'defaults', 'remembered', document, customerId],
    queryFn: async () => {
      const response = await api.get('/settings/sales/defaults', {
        params: { customer_id: customerId, document },
      })
      return (response.data?.data?.remembered ?? null) as RememberedDefaults | null
    },
    enabled: enabled && Boolean(customerId),
    staleTime: 30_000,
  })
}

const FIELD_LABELS: Record<string, string> = {
  warehouse_id: 'warehouse',
  billing_contact_id: 'billing contact',
  shipping_contact_id: 'shipping contact',
  shipping_method: 'shipping method',
  incoterm_code: 'Incoterm',
  incoterm_place: 'Incoterm place',
  notes: 'notes',
}

const DOCUMENT_LABELS: Record<RememberedDocument, string> = {
  quotation: 'last quotation',
  sales_order: 'last order',
  invoice: 'last invoice',
}

/** The remembered string value for a field, or '' when none. */
export function rememberedText(remembered: RememberedDefaults | null | undefined, field: string): string {
  const value = remembered?.values?.[field]
  return value === null || value === undefined ? '' : String(value)
}

/**
 * Fill each still-blank field from the remembered values. A field the user
 * already filled is never touched. Returns the fields that were filled.
 */
export function applyRememberedToBlankFields(
  remembered: RememberedDefaults | null | undefined,
  fields: Array<{ key: string; current: string; set: (value: string) => void }>,
): string[] {
  if (!remembered?.source) return []
  const applied: string[] = []
  for (const field of fields) {
    const value = rememberedText(remembered, field.key)
    if (value === '' || field.current.trim() !== '') continue
    field.set(value)
    applied.push(field.key)
  }
  return applied
}

/** "Remembered from last order SO-0012: warehouse, notes." — or null when nothing is remembered. */
export function rememberedHintText(remembered: RememberedDefaults | null | undefined): string | null {
  if (!remembered?.source) return null
  const fields = Object.keys(remembered.values ?? {})
  if (fields.length === 0) return null
  const labels = fields.map((field) => FIELD_LABELS[field] ?? field.replace(/_/g, ' '))
  const number = remembered.source.number ? ` ${remembered.source.number}` : ''
  return `Remembered from ${DOCUMENT_LABELS[remembered.document] ?? 'last document'}${number}: ${labels.join(', ')}.`
}
