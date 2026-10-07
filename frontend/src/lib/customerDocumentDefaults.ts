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
  /** Link to the Contact Master person (contact_addresses live there). */
  core_contact_uuid?: string | null
  // Customer-specific contact records also carry a single inline address.
  label?: string | null
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
}

/** A Contact Master address row (is_default_billing marks the billing default). */
export interface ContactMasterAddress {
  address_type?: string | null
  label?: string | null
  line_1?: string | null
  line_2?: string | null
  line_3?: string | null
  city?: string | null
  state?: string | null
  postal_code?: string | null
  country?: string | null
  is_default_billing?: boolean | null
}

/** Address shape the sales forms render/edit (matches their AddressFormState). */
export interface DocumentAddressForm {
  label: string
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
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

/** The contact's default billing address from Contact Master, then any billing address. */
export function pickContactMasterBillingAddress(
  addresses?: ContactMasterAddress[] | null,
): ContactMasterAddress | undefined {
  const list = addresses ?? []
  return (
    list.find((address) => address.is_default_billing === true)
    ?? list.find((address) => (address.address_type ?? '').toLowerCase() === 'billing')
    ?? undefined
  )
}

const hasInlineAddress = (contact: CustomerDocumentContact): boolean =>
  Boolean(contact.street || contact.street2 || contact.city || contact.state || contact.zip || contact.country)

/**
 * The billing address to render for a selected related contact person:
 * the contact's Contact Master default billing address when one is supplied,
 * otherwise the customer-specific contact record's inline address. Null when
 * neither exists, so the caller keeps the customer's default billing address.
 */
export function buildContactBillingAddress(
  contact?: CustomerDocumentContact | null,
  masterAddresses?: ContactMasterAddress[] | null,
): DocumentAddressForm | null {
  if (!contact) {
    return null
  }

  const master = pickContactMasterBillingAddress(masterAddresses)
  if (master) {
    return {
      label: master.label ?? '',
      street: master.line_1 ?? '',
      street2: [master.line_2, master.line_3].filter(Boolean).join(' '),
      city: master.city ?? '',
      state: master.state ?? '',
      zip: master.postal_code ?? '',
      country: master.country ?? '',
    }
  }

  if (hasInlineAddress(contact)) {
    return {
      label: contact.label ?? '',
      street: contact.street ?? '',
      street2: contact.street2 ?? '',
      city: contact.city ?? '',
      state: contact.state ?? '',
      zip: contact.zip ?? '',
      country: contact.country ?? '',
    }
  }

  return null
}
