// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import QuotationEditPage from './QuotationEditPage'
import { useAuthStore } from '@/store/authStore'

/**
 * Issue #323 (§8.3): the quotation document form must use the shared compact
 * sales design system:
 *  - 36px controls, 13px text, ONE radius (no pill/rounded-md mix);
 *  - Comfortable/Compact density, switchable per user and remembered;
 *  - one right-side details drawer (references/incoterms/notes/terms/case)
 *    with a live line/total summary;
 *  - below 768px every line renders as a stacked card, above as table rows.
 */

const MOBILE_QUERY = '(max-width: 767px)'
let narrowViewport = false

// jsdom has no matchMedia; Radix and the responsive grid both need it.
// `narrowViewport` lets a test render the <768px branch.
window.matchMedia = ((query: string) => ({
  matches: query === MOBILE_QUERY ? narrowViewport : false,
  media: query,
  onchange: null,
  addListener: () => {},
  removeListener: () => {},
  addEventListener: () => {},
  removeEventListener: () => {},
  dispatchEvent: () => false,
})) as unknown as typeof window.matchMedia

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

const { apiGet, apiPost, apiPut, resolveBatchMock, listPriceListsMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  resolveBatchMock: vi.fn(),
  listPriceListsMock: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: apiPut,
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: resolveBatchMock,
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: listPriceListsMock,
  },
}))

const DETAILS_TITLE = 'References, contacts, addresses, logistics and terms'

const customer = { id: 1, name: 'Klinik Ali', payment_terms: 30 }
const allProducts = [{ id: 7, name: 'Widget', sku: 'W-1', price: '20', tax_rate: 0 }]

function makeQuotation(overrides: Record<string, unknown> = {}) {
  return {
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
    notes: 'original note',
    terms: '',
    billing_address_snapshot: '',
    shipping_address_snapshot: '',
    lines: [
      {
        id: 11,
        line_type: 'product',
        product_id: 7,
        product: { id: 7, name: 'Widget', sku: 'W-1' },
        description: 'Widget',
        quantity: 2,
        unit_price: 20,
        tax_rate: 0,
        discount_amount: 0,
      },
    ],
    ...overrides,
  }
}

beforeEach(() => {
  localStorage.clear()
  narrowViewport = false
  useAuthStore.setState({
    user: { id: 1, name: 'Me', email: 'me@example.com' },
    role: 'Owner',
    permissions: [],
    token: 'test-token',
  })

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: makeQuotation() }
    if (url.startsWith('/tags/')) return { data: { data: [] } }
    if (url.startsWith('/products/')) return { data: { data: allProducts[0] } }
    if (url.startsWith('/products')) return { data: { data: allProducts } }
    if (url.startsWith('/customers/')) return { data: customer }
    if (url.startsWith('/customers')) return { data: { data: [customer] } }
    return { data: { data: [] } }
  })

  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: { data: { id: 5 } } }))
  listPriceListsMock.mockReset()
  listPriceListsMock.mockImplementation(async () => ({ data: [] }))
  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => ({
    currency_code: 'USD',
    base_currency_code: 'USD',
    exchange_rate: 1,
    rate_date: '2026-01-01',
    rate_source: 'price_list',
    rate_stale: false,
    price_list_name: 'BASE',
    price_list_currency: 'USD',
    lines: lines.map(() => ({ unit_price: 20, list_price: 20, source: 'price_list' })),
  }))
})

afterEach(cleanup)

function renderEdit() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quotations/5/edit']}>
        <Routes>
          <Route path="/quotations/:id/edit" element={<QuotationEditPage />} />
          <Route path="/quotations/:id" element={<div>quotation detail</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const quoteDateInput = (container: HTMLElement) => {
  const labelElement = [...container.querySelectorAll('label')].find(
    (candidate) => candidate.textContent?.trim() === 'Quote Date',
  )
  expect(labelElement, 'Quote Date label').toBeTruthy()
  return labelElement!.parentElement!.querySelector('input[type="date"]') as HTMLInputElement
}

const rowInputs = (container: HTMLElement, rowIndex: number) => {
  const row = container.querySelectorAll('tbody tr')[rowIndex]
  return row.querySelectorAll('input[type="number"]') as NodeListOf<HTMLInputElement>
}

async function openDetails() {
  fireEvent.click(screen.getByTitle(DETAILS_TITLE))
  return screen.findByTestId('sales-details-summary')
}

async function closeDetails() {
  fireEvent.click(screen.getByRole('button', { name: 'Close' }))
  await waitFor(() => expect(screen.queryByTestId('sales-details-summary')).toBeNull())
}

describe('#323 compact sales design system', () => {
  it('uses one radius, 36px controls and 13px text on the header and drawer controls', async () => {
    const { container } = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    const quoteDate = quoteDateInput(container)
    expect(quoteDate.className).toMatch(/\bh-9\b/)
    expect(quoteDate.className).toMatch(/\btext-sm\b/)
    expect(quoteDate.className).toMatch(/\brounded-lg\b/)
    expect(quoteDate.className).not.toMatch(/rounded-full|rounded-md/)

    const details = screen.getByTitle(DETAILS_TITLE)
    expect(details.className).toMatch(/\bh-9\b/)
    expect(details.className).toMatch(/\brounded-lg\b/)
    expect(details.className).not.toMatch(/rounded-full|rounded-md/)

    const densityToggle = screen.getByTestId('sales-density-toggle')
    expect(densityToggle.className).toMatch(/\bh-9\b/)
    expect(densityToggle.className).toMatch(/\brounded-lg\b/)
    expect(densityToggle.className).not.toMatch(/rounded-full|rounded-md/)

    await openDetails()

    const customerRef = screen.getByPlaceholderText('PO / customer code')
    expect(customerRef.className).toMatch(/\bh-9\b/)
    expect(customerRef.className).toMatch(/\btext-sm\b/)
    expect(customerRef.className).toMatch(/\brounded-lg\b/)
    expect(customerRef.className).not.toMatch(/rounded-full|rounded-md/)

    const notes = screen.getByPlaceholderText('Internal notes...')
    expect(notes.className).toMatch(/\btext-sm\b/)
    expect(notes.className).toMatch(/\brounded-lg\b/)
    expect(notes.className).not.toMatch(/rounded-full|rounded-md/)

    const linePrice = rowInputs(container, 0)[1]
    expect(linePrice.className).toMatch(/\brounded-lg\b/)
    expect(linePrice.className).not.toMatch(/rounded-md|rounded-full/)

    const discountMode = screen.getByLabelText('Document discount mode')
    expect(discountMode.className).toMatch(/\bh-9\b/)
    expect(discountMode.className).toMatch(/\brounded-lg\b/)
    expect(discountMode.className).not.toMatch(/rounded-full|rounded-md/)

    const discountAmount = screen.getByLabelText('Document discount amount')
    expect(discountAmount.className).toMatch(/\bh-9\b/)
    expect(discountAmount.className).toMatch(/\btext-sm\b/)
    expect(discountAmount.className).toMatch(/\brounded-lg\b/)
    expect(discountAmount.className).not.toMatch(/rounded-full|rounded-md/)
  })

  it('switches Comfortable/Compact per user, remembers the choice and drives the density attribute', async () => {
    renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    const formRoot = document.querySelector('.sales-form')
    expect(formRoot, 'sales form root').toBeTruthy()
    expect(formRoot!.getAttribute('data-density')).toBe('comfortable')

    const toggle = screen.getByTestId('sales-density-toggle')
    expect(toggle.textContent).toContain('Comfortable')

    fireEvent.click(toggle)
    await waitFor(() => expect(formRoot!.getAttribute('data-density')).toBe('compact'))
    expect(toggle.textContent).toContain('Compact')
    expect(localStorage.getItem('noovaerp.document.density')).toBe('compact')

    // The row class the compact CSS measures is rendered.
    expect(document.querySelector('.doc-line-row')).toBeTruthy()

    cleanup()
    renderEdit()
    await screen.findByRole('button', { name: /save changes/i })
    expect(document.querySelector('.sales-form')!.getAttribute('data-density')).toBe('compact')

    fireEvent.click(screen.getByTestId('sales-density-toggle'))
    await waitFor(() =>
      expect(document.querySelector('.sales-form')!.getAttribute('data-density')).toBe('comfortable'),
    )
    expect(localStorage.getItem('noovaerp.document.density')).toBe('comfortable')
  })

  it('opens one right-side details drawer with a live line/total summary', async () => {
    const { container } = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    await openDetails()
    const drawer = screen.getByRole('dialog')
    expect(within(drawer).getByText('Quotation details')).toBeTruthy()
    expect(screen.getByTestId('summary-line-count').textContent).toBe('1')
    expect(screen.getByTestId('summary-total').textContent).toMatch(/40\.00/)
    // Notes/terms/incoterms moved into the single drawer.
    expect(within(drawer).getByPlaceholderText('Internal notes...')).toBeTruthy()
    expect(within(drawer).getByPlaceholderText('Payment terms...')).toBeTruthy()
    expect(within(drawer).getByLabelText('Incoterm Code')).toBeTruthy()

    await closeDetails()

    // Change a quantity while the drawer is closed: reopening shows the live totals.
    fireEvent.change(rowInputs(container, 0)[0], { target: { value: '3' } })
    await openDetails()
    expect(screen.getByTestId('summary-total').textContent).toMatch(/60\.00/)
    expect(screen.getByTitle(DETAILS_TITLE).textContent).toMatch(/60\.00/)
  })

  it('renders stacked line cards below 768px and table rows above', async () => {
    narrowViewport = true
    const { container } = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    expect(screen.getAllByTestId('sales-line-card')).toHaveLength(1)
    expect(container.querySelector('table tbody tr')).toBeNull()
    expect(screen.getByText('Widget')).toBeTruthy()

    cleanup()
    narrowViewport = false
    const wide = renderEdit()
    await screen.findByRole('button', { name: /save changes/i })

    expect(screen.queryAllByTestId('sales-line-card')).toHaveLength(0)
    expect(wide.container.querySelectorAll('table tbody tr')).toHaveLength(1)
  })
})
