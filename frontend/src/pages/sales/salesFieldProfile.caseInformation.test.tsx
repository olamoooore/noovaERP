// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import QuotationCreatePage from './QuotationCreatePage'
import QuotationEditPage from './QuotationEditPage'
import CreateInvoicePage from '../accounting/customers/CreateInvoicePage'
import { useAuthStore } from '@/store/authStore'

/**
 * Issue #316 (F11 / D4): the Case Information group (case, patient, PR,
 * contract, plant code …) renders only when the tenant's industry field
 * profile (`sales.invoice.field_profile`, read from /settings/sales/defaults)
 * enables it — i.e. the Medical profile. A document that already carries case
 * data keeps showing it, so existing data stays readable and editable.
 */

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

const { apiGet, control } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  control: {
    profile: 'medical' as string,
    quotation: {} as Record<string, unknown>,
    invoice: {} as Record<string, unknown>,
  },
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: { data: { id: 99 } } })),
    put: vi.fn(async () => ({ data: { data: { id: 5 } } })),
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

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }

const baseQuotation = {
  id: 5,
  quotation_number: 'QUO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-09-01',
  valid_until: '2026-09-30',
  status: 'draft',
  lock_version: 3,
  discount_type: 'fixed',
  discount_value: 0,
  notes: '',
  terms: '',
  billing_address_snapshot: '',
  shipping_address_snapshot: '',
  lines: [],
}

const baseInvoice = {
  id: 7,
  customer_id: 1,
  invoice_number: 'INV-7',
  invoice_date: '2026-01-15',
  due_date: '2026-02-14',
  payment_terms: 30,
  discount_amount: 0,
  lines: [],
}

beforeEach(() => {
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  } as any)
  control.profile = 'medical'
  control.quotation = { ...baseQuotation }
  control.invoice = { ...baseInvoice }

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/sales/defaults') {
      return { data: { data: { invoice: { field_profile: control.profile } } } }
    }
    if (url === '/quotations/5') return { data: control.quotation }
    if (url === '/invoices/7') return { data: control.invoice }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderAt(path: string, element: React.ReactElement, routePath?: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        {routePath ? (
          <Routes>
            <Route path={routePath} element={element} />
          </Routes>
        ) : (
          element
        )}
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const DETAILS_TITLE = 'References, contacts, addresses, logistics and terms'

/** Opens the quotation details drawer once the settings have been read. */
async function openQuotationDetails() {
  await waitFor(() =>
    expect(apiGet.mock.calls.some(([url]) => url === '/settings/sales/defaults')).toBe(true),
  )
  fireEvent.click(await screen.findByTitle(DETAILS_TITLE))
  // The drawer is open once its always-present Notes field renders.
  await screen.findByPlaceholderText('Internal notes...')
}

describe('#316 quotation form — Case Information follows the field profile', () => {
  it('create: hides Case information for a Trading tenant', async () => {
    control.profile = 'trading'
    renderAt('/quotations/new', <QuotationCreatePage />)
    await openQuotationDetails()

    await waitFor(() => expect(screen.queryByText('Case information')).toBeNull())
    expect(screen.queryByPlaceholderText('Case reference number')).toBeNull()
  })

  it('create: shows Case information for a Medical tenant', async () => {
    control.profile = 'medical'
    renderAt('/quotations/new', <QuotationCreatePage />)
    await openQuotationDetails()

    expect(await screen.findByText('Case information')).toBeTruthy()
  })

  it('edit: hides Case information for a General tenant when the quotation has none', async () => {
    control.profile = 'general'
    renderAt('/quotations/5/edit', <QuotationEditPage />, '/quotations/:id/edit')
    await screen.findByRole('button', { name: /save changes/i })
    await openQuotationDetails()

    await waitFor(() => expect(screen.queryByText('Case information')).toBeNull())
  })

  it('edit: keeps existing case data visible even when the profile disables the group', async () => {
    control.profile = 'general'
    control.quotation = { ...baseQuotation, case_number: 'CASE-42', patient_name: 'Ali' }
    renderAt('/quotations/5/edit', <QuotationEditPage />, '/quotations/:id/edit')
    await screen.findByRole('button', { name: /save changes/i })
    await openQuotationDetails()

    expect(await screen.findByText('Case information')).toBeTruthy()
    expect(await screen.findByDisplayValue('CASE-42')).toBeTruthy()
  })
})

describe('#316 invoice form — Case Information follows the field profile', () => {
  it('create: hides the Cases card for a Trading tenant', async () => {
    control.profile = 'trading'
    renderAt('/invoices/new', <CreateInvoicePage />)
    await waitFor(() =>
      expect(apiGet.mock.calls.some(([url]) => url === '/settings/sales/defaults')).toBe(true),
    )

    await waitFor(() => expect(screen.queryByText('Add case')).toBeNull())
  })

  it('edit: hides the Cases card for a Trading tenant when the invoice has no case data', async () => {
    control.profile = 'trading'
    renderAt('/invoices/7', <CreateInvoicePage />, '/invoices/:id')
    await waitFor(() => expect(apiGet.mock.calls.some(([url]) => url === '/invoices/7')).toBe(true))
    await waitFor(() =>
      expect(apiGet.mock.calls.some(([url]) => url === '/settings/sales/defaults')).toBe(true),
    )

    await waitFor(() => expect(screen.queryByText('Add case')).toBeNull())
    expect(screen.queryByText('Cases')).toBeNull()
  })

  it('edit: keeps existing case data visible for a Trading tenant', async () => {
    control.profile = 'trading'
    control.invoice = { ...baseInvoice, case_number: 'CASE-42', patient_name: 'Ali' }
    renderAt('/invoices/7', <CreateInvoicePage />, '/invoices/:id')

    expect(await screen.findByText('Cases (1)')).toBeTruthy()
  })
})
