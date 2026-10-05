// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import CreateInvoicePage from './CreateInvoicePage'

// jsdom polyfills Radix needs at render time (same as the other smoke suites).
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
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('@/lib/pricingApi', () => ({
  default: {
    resolveBatch: vi.fn(async () => ({ lines: [] })),
    sellableCurrencies: vi.fn(async () => []),
    listPriceLists: vi.fn(async () => ({ data: [] })),
  },
}))

const mockedGet = vi.mocked(api.get)
const mockedPost = vi.mocked(api.post)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices/new']}>
        <CreateInvoicePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockedGet.mockReset()
  mockedPost.mockReset()
  mockedPost.mockResolvedValue({ data: { id: 99, name: 'Klinik Sihat' } })
  mockedGet.mockImplementation(async (url: string) => {
    if (url === '/invoices') return { data: { data: [] } }
    if (url.startsWith('/invoices/')) return { data: { data: null } }
    if (url.startsWith('/customers')) return { data: { data: [] } }
    if (url.startsWith('/settings/sales')) return { data: { data: {} } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('CreateInvoicePage customer quick-create (#437 CU9)', () => {
  it('creates inside the sales document through the one shared CustomerForm', async () => {
    renderPage()

    const addCustomer = await screen.findByRole('button', { name: 'Add Customer' })
    fireEvent.click(addCustomer)

    await waitFor(() => expect(screen.getByTestId('customer-form')).toBeTruthy())

    fireEvent.change(screen.getByLabelText(/Name \*/), { target: { value: 'Klinik Sihat' } })
    fireEvent.mouseDown(screen.getByRole('tab', { name: /addresses/i }))
    await waitFor(() => expect(screen.getByLabelText('Street')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('Street'), { target: { value: '12 Jalan Ampang' } })
    fireEvent.change(screen.getByLabelText('City'), { target: { value: 'Kuala Lumpur' } })
    fireEvent.change(screen.getByLabelText('Country (ISO code)'), { target: { value: 'my' } })

    fireEvent.click(screen.getByRole('button', { name: 'Create Customer' }))

    await waitFor(() => expect(mockedPost).toHaveBeenCalled())

    const [url, body] = mockedPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/customers')
    expect(body.name).toBe('Klinik Sihat')
    expect(body.addresses).toEqual([
      {
        type: 'billing',
        street: '12 Jalan Ampang',
        street2: '',
        city: 'Kuala Lumpur',
        state: '',
        zip: '',
        country: 'MY',
        is_default: true,
      },
    ])
  })
})
