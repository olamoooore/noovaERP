/**
 * ADR-003B W4: shared helpers for the customer contact dialog and the
 * contact cards (company Site addresses, canonical contact payload).
 */
export interface SiteAddress {
  id: string
  address_type: string
  label?: string | null
  line_1?: string | null
  line_2?: string | null
  city?: string | null
  postal_code?: string | null
  country?: string | null
}

export interface ContactFormState {
  name: string
  job_title: string
  email: string
  phone: string
  mobile: string
  notes: string
  is_primary: boolean
  functional_category_code: string
  classification_code: string
  persistent_role_codes: string[]
  department_name: string
  site_address_id: string
}

export const emptyContactForm = (): ContactFormState => ({
  name: '',
  job_title: '',
  email: '',
  phone: '',
  mobile: '',
  notes: '',
  is_primary: false,
  functional_category_code: '',
  classification_code: '',
  persistent_role_codes: [],
  department_name: '',
  site_address_id: '',
})

export const siteLabel = (site: SiteAddress) =>
  site.label || [site.line_1, site.city].filter(Boolean).join(', ') || 'Unnamed site'

/** The canonical contact payload sent to POST/PUT /customers/{id}/contacts. */
export function contactFormToPayload(form: ContactFormState): Record<string, unknown> {
  return {
    name: form.name.trim(),
    job_title: form.job_title || null,
    email: form.email || null,
    phone: form.phone || null,
    mobile: form.mobile || null,
    notes: form.notes || null,
    is_primary: form.is_primary,
    functional_category_code: form.functional_category_code || null,
    classification_code: form.classification_code || null,
    persistent_role_codes: form.persistent_role_codes,
    department_name: form.department_name || null,
    site_address_id: form.site_address_id || null,
  }
}
