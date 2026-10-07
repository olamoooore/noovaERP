/**
 * Issue #336 (Q12): reason codes sent to `POST /quotations/{id}/reject` and
 * shown back in the quotation version history.
 */
export const QUOTATION_REJECT_REASONS = [
  { value: 'price_too_high', label: 'Price too high' },
  { value: 'lead_time', label: 'Lead time too long' },
  { value: 'scope_change', label: 'Scope or quantities changed' },
  { value: 'competitor', label: 'Went with a competitor' },
  { value: 'customer_cancelled', label: 'Customer no longer needs it' },
  { value: 'other', label: 'Other' },
] as const

export const QUOTATION_REVISION_REASON = 'revision'

export function quotationReasonLabel(code?: string | null): string | null {
  if (!code) return null
  if (code === QUOTATION_REVISION_REASON) return 'New revision'
  return QUOTATION_REJECT_REASONS.find((reason) => reason.value === code)?.label ?? code
}
