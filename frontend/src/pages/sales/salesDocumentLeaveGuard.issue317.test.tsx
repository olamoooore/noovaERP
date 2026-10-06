// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Link, MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import type { ReactElement } from 'react'
import QuotationCreatePage from './QuotationCreatePage'
import SalesOrderCreatePage from './SalesOrderCreatePage'
import CreateInvoicePage from '@/pages/accounting/customers/CreateInvoicePage'

// #317 (SALES-UX / F5): sales document forms must not lose in-progress work —
// a dirty form guards leaving (in-form Back/Cancel, in-app links, reload),
// keeps a local draft that is offered back on return and cleared on save,
// and the record shortcuts open in a new tab instead of navigating away.

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

const { apiGet, apiPost, resolveBatchMock } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  resolveBatchMock: vi.fn(),
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
    resolveBatch: resolveBatchMock,
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ onValueChange }: { onValueChange: (id: string, c?: any) => void }) => (
    <button type="button" onClick={() => onValueChange('1', { id: '1', name: 'Klinik Ali', code: 'C-ALI' })}>pick-ali</button>
  ),
}))

const ali = { id: 1, name: 'Klinik Ali', code: 'C-ALI', payment_terms: 30, customer_contacts: [], addresses: [] }

const DRAFT_PREFIX = 'noovaerp:document-draft:v1'
const draftKeys = () => Object.keys(window.localStorage).filter((key) => key.startsWith(DRAFT_PREFIX))

let openSpy: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  window.localStorage.clear()
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/1') return { data: ali }
    if (url === '/invoices') return { data: { data: [] } }
    return { data: { data: [] } }
  })
  apiPost.mockReset()
  apiPost.mockImplementation(async () => ({ data: { data: { id: 99 } } }))
  resolveBatchMock.mockReset()
  resolveBatchMock.mockImplementation(async (_context: any, lines: any[]) => ({
    currency_code: 'MYR',
    base_currency_code: 'MYR',
    exchange_rate: 1,
    lines: (lines ?? []).map(() => ({ unit_price: 10, list_price: 10, source: 'price_list' })),
  }))
})

afterEach(() => {
  cleanup()
  openSpy.mockRestore()
})

function Where() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname}</div>
}

function renderAt(path: string, page: ReactElement, listPath: string) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[listPath, path]} initialIndex={1}>
        <nav><Link to="/dashboard">Sidebar dashboard</Link></nav>
        <Where />
        <Routes>
          <Route path={path} element={page} />
          <Route path="*" element={<div>Somewhere else</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const location = () => screen.getByTestId('location').textContent

const forms = [
  {
    name: 'quotation',
    render: () => renderAt('/quotations/new', <QuotationCreatePage />, '/quotations'),
    path: '/quotations/new',
    detailsTitle: 'References, contacts, addresses, logistics and terms',
    textPlaceholder: 'Internal notes...',
    customersAction: 'Manage Customers',
  },
  {
    name: 'sales order',
    render: () => renderAt('/sales/orders/new', <SalesOrderCreatePage />, '/sales/orders'),
    path: '/sales/orders/new',
    detailsTitle: null,
    textPlaceholder: 'Notes for the customer...',
    customersAction: null,
  },
  {
    name: 'invoice',
    render: () => renderAt('/accounting/customers/bills/new', <CreateInvoicePage />, '/accounting/customers/bills'),
    path: '/accounting/customers/bills/new',
    detailsTitle: 'References, contacts, addresses, notes and profile fields',
    textPlaceholder: 'Add a note for your team…',
    customersAction: 'All Customers',
  },
] as const

async function typeNote(form: (typeof forms)[number], value: string) {
  await screen.findByText('pick-ali')
  if (form.detailsTitle) fireEvent.click(screen.getByTitle(form.detailsTitle))
  const field = (await screen.findByPlaceholderText(form.textPlaceholder)) as HTMLTextAreaElement
  fireEvent.keyDown(field, { key: 'a' })
  fireEvent.change(field, { target: { value } })
  return field
}

function closeDetails(form: (typeof forms)[number]) {
  if (form.detailsTitle) fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' })
}

describe.each(forms)('$name form — unsaved-changes guard and local draft (#317)', (form) => {
  it('leaves at once when nothing was changed', async () => {
    form.render()
    await screen.findByText('pick-ali')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    await waitFor(() => expect(location()).not.toBe(form.path))
    expect(screen.queryByText('Leave with unsaved changes?')).toBeNull()
  })

  it('asks before Cancel throws away a dirty form, and stays on Keep editing', async () => {
    form.render()
    await typeNote(form, 'call before delivery')
    closeDetails(form)

    fireEvent.click(screen.getByRole('button', { name: 'Cancel', hidden: true }))
    expect(await screen.findByText('Leave with unsaved changes?')).toBeTruthy()
    expect(location()).toBe(form.path)

    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }))
    await waitFor(() => expect(screen.queryByText('Leave with unsaved changes?')).toBeNull())
    expect(location()).toBe(form.path)

    fireEvent.click(screen.getByRole('button', { name: 'Cancel', hidden: true }))
    fireEvent.click(await screen.findByRole('button', { name: 'Leave page' }))
    await waitFor(() => expect(location()).not.toBe(form.path))
  })

  it('holds an in-app link click and arms the browser beforeunload prompt while dirty', async () => {
    form.render()
    await typeNote(form, 'call before delivery')
    closeDetails(form)

    const unload = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(unload)
    expect(unload.defaultPrevented).toBe(true)

    fireEvent.click(screen.getByText('Sidebar dashboard'))
    expect(await screen.findByText('Leave with unsaved changes?')).toBeTruthy()
    expect(location()).toBe(form.path)
  })

  it('autosaves a local draft and offers it back on return', async () => {
    const first = form.render()
    await typeNote(form, 'call before delivery')
    await waitFor(() => expect(draftKeys()).toHaveLength(1))
    expect(window.localStorage.getItem(draftKeys()[0])).toContain('call before delivery')
    first.unmount()

    form.render()
    fireEvent.click(await screen.findByRole('button', { name: 'Restore draft' }))
    if (form.detailsTitle) fireEvent.click(screen.getByTitle(form.detailsTitle))
    const field = (await screen.findByPlaceholderText(form.textPlaceholder)) as HTMLTextAreaElement
    expect(field.value).toBe('call before delivery')
  })

  it('drops the draft when the user discards it', async () => {
    const first = form.render()
    await typeNote(form, 'call before delivery')
    await waitFor(() => expect(draftKeys()).toHaveLength(1))
    first.unmount()

    form.render()
    fireEvent.click(await screen.findByRole('button', { name: 'Discard draft' }))
    expect(draftKeys()).toHaveLength(0)
    expect(screen.queryByTestId('document-draft-banner')).toBeNull()
  })

  if (form.customersAction) {
    it('opens the customer / product shortcuts in a new tab instead of navigating away', async () => {
      form.render()
      await typeNote(form, 'call before delivery')
      closeDetails(form)

      fireEvent.click(screen.getByText(form.customersAction!))
      fireEvent.click(screen.getByText('Product List'))
      fireEvent.click(screen.getByText('New Product'))

      expect(openSpy).toHaveBeenCalledWith('/customers', '_blank', 'noopener,noreferrer')
      expect(openSpy).toHaveBeenCalledWith('/products', '_blank', 'noopener,noreferrer')
      expect(openSpy).toHaveBeenCalledWith('/products/new', '_blank', 'noopener,noreferrer')
      expect(location()).toBe(form.path)
    })
  }
})

describe('draft is cleared on a successful save (#317)', () => {
  it('sales order: saving removes the local draft', async () => {
    const product = { id: 5, name: 'Yarn', sku: 'YRN-1', price: '10', tax_rate: 0 }
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/customers/1') return { data: ali }
      if (url.startsWith('/products/')) return { data: { data: product } }
      if (url.startsWith('/products')) return { data: { data: [product] } }
      return { data: { data: [] } }
    })
    renderAt('/sales/orders/new', <SalesOrderCreatePage />, '/sales/orders')
    fireEvent.click(await screen.findByText('pick-ali'))
    const productTrigger = (await screen.findAllByRole('combobox')).find((c) => c.textContent?.includes('Search product'))
    fireEvent.click(productTrigger!)
    fireEvent.click(await screen.findByText('Yarn'))
    const notes = screen.getByPlaceholderText('Notes for the customer...')
    fireEvent.keyDown(notes, { key: 'a' })
    fireEvent.change(notes, { target: { value: 'deliver to dock 3' } })
    await waitFor(() => expect(draftKeys()).toHaveLength(1))

    fireEvent.click(screen.getByRole('button', { name: /create sales order/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(location()).toBe('/sales/orders/99'))
    expect(draftKeys()).toHaveLength(0)
  })
})

describe('a restored draft is not overwritten by the customer defaults (#317)', () => {
  const cases = [
    { name: 'quotation', form: forms[0] },
    { name: 'invoice', form: forms[2] },
  ] as const

  it.each(cases)('$name: restored contact survives the restored customer loading', async ({ form }) => {
    const first = form.render()
    fireEvent.click(await screen.findByText('pick-ali'))
    fireEvent.click(screen.getByTitle(form.detailsTitle!))
    const attention = (await screen.findByPlaceholderText('Primary contact')) as HTMLInputElement
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/customers/1'))
    fireEvent.keyDown(attention, { key: 'a' })
    fireEvent.change(attention, { target: { value: 'Night shift desk' } })
    await waitFor(() => expect(window.localStorage.getItem(draftKeys()[0] ?? '') ?? '').toContain('Night shift desk'))
    first.unmount()

    form.render()
    fireEvent.click(await screen.findByRole('button', { name: 'Restore draft' }))
    await waitFor(() => expect(apiGet.mock.calls.filter(([url]) => url === '/customers/1').length).toBeGreaterThan(1))
    fireEvent.click(screen.getByTitle(form.detailsTitle!))
    const restored = (await screen.findByPlaceholderText('Primary contact')) as HTMLInputElement
    // Give the customer-default effects a chance to run, then check again.
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(restored.value).toBe('Night shift desk')
  })
})
