// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import CreateInvoicePage from './CreateInvoicePage'

// jsdom polyfills Radix needs at render time
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

const { apiGet, apiPost } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: vi.fn(async () => ({ lines: [] })),
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (id: string, c?: any) => void }) => (
    <div>
      <button type="button" onClick={() => onValueChange('1', { id: '1', name: 'Klinik Ali', code: 'C-ALI' })}>pick-ali</button>
    </div>
  ),
}))

// The real contact combobox is a Radix popover over GET /customers/{id}. The
// behaviour under test is what the form does once a contact is picked, so the
// picker is a button that returns the same record the real one would.
vi.mock('@/components/ui/ContactSearchCombobox', () => ({
  ContactSearchCombobox: ({ value, onValueChange }: { value: string; onValueChange: (val: string, c?: any) => void }) => (
    <span>
      <span data-testid="contact-value">{value}</span>
      <button
        type="button"
        data-testid="pick-contact"
        onClick={() =>
          value === '14'
            ? onValueChange('14', {
                id: 14,
                name: 'Shipping Sue',
                email: 'sue@ali.test',
                phone: '011-3',
                core_contact_uuid: 'uuid-2',
              })
            : onValueChange('13', {
                id: 13,
                name: 'Billing Bob',
                email: 'bob@ali.test',
                phone: '011-2',
                core_contact_uuid: 'uuid-1',
              })
        }
      >
        pick-contact
      </button>
    </span>
  ),
}))

const ali = {
  id: 1,
  name: 'Klinik Ali',
  code: 'C-ALI',
  email: 'office@ali.test',
  phone: '03-1111',
  payment_terms: 30,
  customer_contacts: [
    { id: 11, name: 'Dr Doctor', email: 'doc@ali.test', contact_category: 'doctor' },
    { id: 12, name: 'Primary Pat', email: 'pat@ali.test', is_primary: true },
    { id: 13, name: 'Billing Bob', email: 'bob@ali.test', phone: '011-2', contact_category: 'billing', core_contact_uuid: 'uuid-1' },
    { id: 14, name: 'Shipping Sue', email: 'sue@ali.test', phone: '011-3', contact_category: 'shipping', core_contact_uuid: 'uuid-2' },
  ],
  addresses: [
    { type: 'billing', street: '1 Old Bill Rd', city: 'Shah Alam', is_default: false },
    { type: 'billing', street: '2 Ali Billing St', city: 'Kuala Lumpur', is_default: true },
    { type: 'shipping', street: '3 Ali Dock', city: 'Klang', is_default: true },
  ],
}

beforeEach(() => {
  // The invoice form auto-saves a draft; a leftover draft would trip the
  // restore guard and skip the customer-default effect under test.
  localStorage.clear()
  sessionStorage.clear()

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: ali }
    if (url === '/contacts/uuid-1/addresses') {
      return {
        data: {
          data: [
            { address_type: 'shipping', line_1: '99 Ship Rd', city: 'Klang', is_default_billing: false },
            {
              address_type: 'office',
              label: 'HQ',
              line_1: '10 Contact Bill Rd',
              line_2: 'Suite 2',
              line_3: 'Tower B',
              city: 'Ipoh',
              state: 'Perak',
              postal_code: '30000',
              country: 'Malaysia',
              is_default_billing: true,
            },
          ],
        },
      }
    }
    if (url === '/contacts/uuid-2/addresses') {
      return {
        data: {
          data: [
            { address_type: 'billing', line_1: '5 Contact Bill Rd', city: 'Billing Town', is_default_shipping: false },
            {
              address_type: 'shipping',
              label: 'Site B',
              line_1: '20 Contact Ship Rd',
              city: 'Johor Bahru',
              state: 'Johor',
              postal_code: '80000',
              country: 'Malaysia',
              is_default_shipping: true,
            },
          ],
        },
      }
    }
    if (url === '/invoices/5') {
      return {
        data: {
          id: 5,
          customer_id: 1,
          invoice_number: 'INV-0005',
          invoice_date: '2026-10-01',
          due_date: '2026-10-31',
          payment_terms: 30,
          status: 'draft',
          currency_code: 'SGD',
          billing_contact_id: null,
          shipping_contact_id: null,
          attention_to: null,
          billing_address_snapshot: 'Saved Billing Snapshot',
          shipping_address_snapshot: 'Saved Shipping Snapshot',
          lines: [],
        },
      }
    }
    if (url.startsWith('/customers')) return { data: { data: [ali] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/new']}>
        <CreateInvoicePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function renderEditPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/5/edit']}>
        <Routes>
          <Route path="/invoices/:id/edit" element={<CreateInvoicePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const detailsSheet = () => screen.getByRole('dialog')
const billingContactValue = () => within(detailsSheet()).getAllByTestId('contact-value')[0]
const shippingContactValue = () => within(detailsSheet()).getAllByTestId('contact-value')[1]
const billingStreetInput = () => within(detailsSheet()).getAllByPlaceholderText('Street')[0] as HTMLInputElement
const shippingStreetInput = () => within(detailsSheet()).getAllByPlaceholderText('Street')[1] as HTMLInputElement
const billingCityInput = () => within(detailsSheet()).getAllByPlaceholderText('City')[0] as HTMLInputElement
const billingZipInput = () => within(detailsSheet()).getAllByPlaceholderText('ZIP')[0] as HTMLInputElement

function openDetails() {
  fireEvent.click(screen.getByTitle('References, contacts, addresses, notes and profile fields'))
}

describe('CreateInvoicePage — customer/contact billing defaults', () => {
  it('selecting a customer renders its default billing and shipping contacts and addresses', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    openDetails()

    // The billing-category contact is auto-selected in the Billing picker...
    await waitFor(() => expect(billingContactValue().textContent).toBe('13'))
    // ...and the shipping contact in the Shipping picker.
    await waitFor(() => expect(shippingContactValue().textContent).toBe('14'))

    // The customer's default addresses are rendered in both sections.
    await waitFor(() => expect(billingStreetInput().value).toBe('2 Ali Billing St'))
    expect(billingCityInput().value).toBe('Kuala Lumpur')
    expect(shippingStreetInput().value).toBe('3 Ali Dock')
  })

  it('selecting a related contact person renders that contact\'s default billing address', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    openDetails()
    await waitFor(() => expect(billingStreetInput().value).toBe('2 Ali Billing St'))

    fireEvent.click(within(detailsSheet()).getAllByTestId('pick-contact')[0])

    // Contact Master's is_default_billing address wins over the customer default.
    await waitFor(() => expect(billingStreetInput().value).toBe('10 Contact Bill Rd'))
    expect((within(detailsSheet()).getAllByPlaceholderText('Street 2')[0] as HTMLInputElement).value).toBe('Suite 2 Tower B')
    expect(billingCityInput().value).toBe('Ipoh')
    expect(billingZipInput().value).toBe('30000')
  })

  it('selecting a shipping contact renders that contact\'s default shipping address', async () => {
    renderPage()
    fireEvent.click(await screen.findByText('pick-ali'))
    openDetails()
    await waitFor(() => expect(shippingStreetInput().value).toBe('3 Ali Dock'))

    fireEvent.click(within(detailsSheet()).getAllByTestId('pick-contact')[1])

    // Contact Master's is_default_shipping address wins over the customer default.
    await waitFor(() => expect(shippingStreetInput().value).toBe('20 Contact Ship Rd'))
    expect((within(detailsSheet()).getAllByPlaceholderText('City')[1] as HTMLInputElement).value).toBe('Johor Bahru')
    expect((within(detailsSheet()).getAllByPlaceholderText('ZIP')[1] as HTMLInputElement).value).toBe('80000')
  })

  it('an existing invoice without saved contacts renders the customer defaults', async () => {
    renderEditPage()
    openDetails()

    await waitFor(() => expect(billingContactValue().textContent).toBe('13'))
    await waitFor(() => expect(shippingContactValue().textContent).toBe('14'))
  })
})
