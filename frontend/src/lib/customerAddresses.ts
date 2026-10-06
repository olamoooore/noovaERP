/**
 * ADR-003B rule R4: which customer address a document pre-fills is decided
 * once, on the backend (`CustomerAddressResolver`), and returned by
 * `GET /customers/{id}` as `resolved_addresses`. Backend snapshots use the
 * same resolver, so the invoice/quotation screens and server-created
 * documents cannot disagree.
 */
export interface ResolvedCustomerAddress {
  label?: string | null
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
}

export interface CustomerWithResolvedAddresses {
  resolved_addresses?: {
    billing?: ResolvedCustomerAddress | null
    shipping?: ResolvedCustomerAddress | null
  } | null
}

/**
 * The resolved bill-to/ship-to address, `null` when the customer has none, or
 * `undefined` when the response carries no `resolved_addresses` (callers then
 * keep their previous selection logic).
 */
export function resolvedCustomerAddress(
  customer: CustomerWithResolvedAddresses | null | undefined,
  type: 'billing' | 'shipping',
): ResolvedCustomerAddress | null | undefined {
  if (!customer?.resolved_addresses) {
    return undefined
  }

  return customer.resolved_addresses[type] ?? null
}

/**
 * ADR-003B W2b: a resolved address as free text, one part per line, for
 * screens that hold a text address (e.g. a delivery order's ship-to).
 */
export function resolvedAddressText(address: ResolvedCustomerAddress | null | undefined): string {
  if (!address) return ''

  return [
    address.street,
    address.street2,
    [address.city, address.state, address.zip].filter(Boolean).join(' '),
    address.country,
  ]
    .filter(Boolean)
    .join('\n')
}
