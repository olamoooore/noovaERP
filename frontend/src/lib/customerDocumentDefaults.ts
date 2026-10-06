/**
 * Issue #320: the customer-derived defaults a sales document fills when a
 * customer is picked. Shared by the invoice and quotation forms so the two
 * screens choose the same contact and the same default addresses.
 */

export interface CustomerDocumentContact {
  id?: number | string
  name?: string | null
  email?: string | null
  phone?: string | null
  mobile?: string | null
  contact_category?: string | null
  contact_type?: string | null
  is_primary?: boolean
}

export interface CustomerDocumentAddress {
  type?: string | null
  is_default?: boolean
}

export interface CustomerDocumentSource<
  C extends CustomerDocumentContact = CustomerDocumentContact,
  A extends CustomerDocumentAddress = CustomerDocumentAddress,
> {
  name?: string | null
  email?: string | null
  phone?: string | null
  mobile?: string | null
  customer_contacts?: C[]
  customerContacts?: C[]
  addresses?: A[]
}

/**
 * The contact that belongs on a document's Bill-To snapshot: a billing-category
 * contact first, then the primary contact, then the first one. Picking
 * `contacts[0]` blindly put a doctor/requestor's details on invoices.
 */
export function getPrimaryCustomerContact<C extends CustomerDocumentContact>(
  customer?: CustomerDocumentSource<C, any> | null,
): C | undefined {
  const contacts = customer?.customer_contacts ?? customer?.customerContacts ?? []
  const categoryOf = (contact: C) => (contact.contact_category || contact.contact_type || '').toLowerCase()

  return (
    contacts.find((contact) => categoryOf(contact) === 'billing')
    ?? contacts.find((contact) => contact.is_primary)
    ?? contacts[0]
  )
}

/** The customer's default address of a type, else the first of that type. */
export function pickCustomerAddress<A extends CustomerDocumentAddress>(
  customer: CustomerDocumentSource<any, A> | null | undefined,
  type: 'billing' | 'shipping',
): A | undefined {
  const addresses = customer?.addresses ?? []
  return addresses.find((address) => address.type === type && address.is_default)
    ?? addresses.find((address) => address.type === type)
}

/** Attention To / email / phone for a document, from the chosen contact or the customer itself. */
export function getCustomerContactDefaults(customer?: CustomerDocumentSource | null) {
  const contact = getPrimaryCustomerContact(customer)
  return {
    attentionTo: contact?.name ?? customer?.name ?? '',
    contactEmail: contact?.email ?? customer?.email ?? '',
    contactPhone: contact?.phone ?? contact?.mobile ?? customer?.phone ?? customer?.mobile ?? '',
  }
}
