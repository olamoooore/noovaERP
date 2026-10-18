// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import api from '@/lib/api'
import { CustomerContactDialog, type CustomerContactRecord } from './CustomerContactDialog'

if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

vi.mock('@/lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const mockedGet = vi.mocked(api.get)
const mockedPost = vi.mocked(api.post)
const mockedPut = vi.mocked(api.put)

const ORG = 'org-uuid'
const PERSON = 'person-uuid'
const SITE = 'site-uuid'

const TAXONOMY = [
  { taxonomy_type: 'functional_category', code: 'FINANCE', display_label: 'Finance' },
  { taxonomy_type: 'persistent_role', code: 'BILLING_CONTACT', display_label: 'Billing Contact' },
  { taxonomy_type: 'persistent_role', code: 'DELIVERY_CONTACT', display_label: 'Delivery Contact' },
  { taxonomy_type: 'classification', code: 'OTHER', display_label: 'Other' },
]

const CONTACT: CustomerContactRecord = {
  id: 7,
  name: 'Siti',
  email: 'siti@example.test',
  core_contact_uuid: PERSON,
  is_primary: false,
}

function mockContactMaster(options: { rolesPending?: boolean } = {}) {
  mockedGet.mockImplementation((url: string) => {
    if (url === '/contacts/taxonomy') return Promise.resolve({ data: { data: TAXONOMY } })
    if (url === `/contacts/${ORG}/addresses`) {
      return Promise.resolve({
        data: {
          data: [
            { id: SITE, address_type: 'site', label: 'Main Clinic' },
            { id: 'billing-uuid', address_type: 'billing', label: 'Head Office' },
          ],
        },
      })
    }
    if (url === `/contacts/${ORG}/relationships`) {
      return Promise.resolve({
        data: {
          data: [{
            id: 'rel-uuid',
            relationship_type: 'org_person',
            related_contact_id: PERSON,
            functional_category_code: 'FINANCE',
            department_name: 'Accounts',
            site_address_id: SITE,
          }],
        },
      })
    }
    if (url === `/contacts/${PERSON}/roles`) {
      if (options.rolesPending) return new Promise(() => {})
      return Promise.resolve({
        data: { data: [{ role: 'BILLING_CONTACT', relationship_id: 'rel-uuid' }, { role: 'DELIVERY_CONTACT', relationship_id: 'other-rel' }] },
      })
    }
    if (url === `/contacts/${PERSON}/classifications`) {
      return Promise.resolve({ data: { data: { classification_codes: [] } } })
    }
    return Promise.reject(new Error(`unexpected GET ${url}`))
  })
}

function renderDialog(contact: CustomerContactRecord | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onSaved = vi.fn()
  render(
    <QueryClientProvider client={client}>
      <CustomerContactDialog
        open
        onOpenChange={() => {}}
        customerId={1}
        organisationId={ORG}
        contact={contact}
        onSaved={onSaved}
      />
    </QueryClientProvider>,
  )
  return { onSaved }
}

/**
 * ADR-003B W4: the contact dialog has no address fields, links the person to
 * a company Site, and sends the canonical ADR-003A fields.
 */
describe('CustomerContactDialog', () => {
  beforeEach(() => {
    mockedPost.mockResolvedValue({ data: { id: 8 } })
    mockedPut.mockResolvedValue({ data: { id: 7 } })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('has no address fields', async () => {
    mockContactMaster()
    renderDialog(null)

    await screen.findByTestId('customer-contact-dialog')
    for (const label of [/^street/i, /^city$/i, /^state$/i, /zip/i, /^country$/i]) {
      expect(screen.queryByLabelText(label)).toBeNull()
    }
  })

  it('adds a contact with canonical fields and no legacy category/type or address', async () => {
    mockContactMaster()
    renderDialog(null)

    fireEvent.change(await screen.findByLabelText('Name *'), { target: { value: 'Siti' } })
    fireEvent.click(await screen.findByLabelText('Billing Contact'))
    fireEvent.click(screen.getByRole('button', { name: 'Add Contact' }))

    await waitFor(() => expect(mockedPost).toHaveBeenCalled())
    const [url, body] = mockedPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/customers/1/contacts')
    expect(body.name).toBe('Siti')
    expect(body.persistent_role_codes).toEqual(['BILLING_CONTACT'])
    expect(body.site_address_id).toBeNull()
    for (const legacy of ['contact_category', 'contact_type', 'street', 'street2', 'city', 'state', 'zip', 'country']) {
      expect(legacy in body).toBe(false)
    }
  })

  it('edits keep the current site, category and roles at this company', async () => {
    mockContactMaster()
    renderDialog(CONTACT)

    const save = await screen.findByRole('button', { name: 'Save Changes' })
    await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(save)

    await waitFor(() => expect(mockedPut).toHaveBeenCalled())
    const [url, body] = mockedPut.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/customers/1/contacts/7')
    expect(body.site_address_id).toBe(SITE)
    expect(body.functional_category_code).toBe('FINANCE')
    expect(body.department_name).toBe('Accounts')
    // Only the roles on THIS company's relationship.
    expect(body.persistent_role_codes).toEqual(['BILLING_CONTACT'])
  })

  it('cannot save an edit before the current roles and site have loaded', async () => {
    mockContactMaster({ rolesPending: true })
    renderDialog(CONTACT)

    const save = await screen.findByRole('button', { name: 'Save Changes' })
    expect((save as HTMLButtonElement).disabled).toBe(true)
  })

  it('shows an older personal address read-only', async () => {
    mockContactMaster()
    renderDialog({ ...CONTACT, street: '7 Clinic Lane', city: 'Ipoh' })

    const note = await screen.findByTestId('legacy-personal-address')
    expect(note.textContent).toContain('7 Clinic Lane, Ipoh')
    expect(note.querySelector('input')).toBeNull()
  })
})
