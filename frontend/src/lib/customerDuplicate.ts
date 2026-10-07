/**
 * Issue #441: shape of the customer duplicate guard's 409 (#435) and its
 * "merge into existing" hint.
 */

export interface CustomerDuplicateMatch {
  customer_id: number
  customer_name?: string | null
  match_type?: string
  match_types?: string[]
  tier?: 'strong' | 'medium'
  merge_endpoint?: string
}

export interface CustomerDuplicatePayload {
  message?: string
  tier?: 'strong' | 'medium'
  requires?: 'duplicate_override' | 'duplicate_acknowledgement_reason'
  matches?: CustomerDuplicateMatch[]
  merge_action?: {
    action: 'merge_into_existing'
    merged_customer_id: number | null
    requires_create_first: boolean
  }
}

/** The 409 duplicate-guard body of an axios error, or null. */
export function duplicatePayloadFromError(error: unknown): CustomerDuplicatePayload | null {
  const response = (error as { response?: { status?: number; data?: CustomerDuplicatePayload } })?.response
  if (response?.status !== 409 || !Array.isArray(response.data?.matches)) return null
  return response.data ?? null
}
