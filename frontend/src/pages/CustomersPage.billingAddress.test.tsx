// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import { CustomersPage } from './CustomersPage'

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

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

const mockedGet = vi.mocked(api.get)
const mockedPost = vi.mocked(api.post)

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <CustomersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function openCreateDialogAddressesTab() {
  fireEvent.click(screen.getByRole('button', { name: /add customer/i }))
  await waitFor(() => expect(screen.getByText('Add New Customer')).toBeTruthy())
  fireEvent.mouseDown(screen.getByRole('tab', { name: /addresses/i }))
  await waitFor(() => expect(screen.getByLabelText('Street')).toBeTruthy())
}

beforeEach(() => {
  mockedGet.mockReset()
  mockedPost.mockReset()
  mockedGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/customers/statistics')) return { data: {} }
    if (url.startsWith('/customer-categories')) return { data: { categories: [] } }
    if (url.startsWith('/customers')) return { data: { data: [], last_page: 1, total: 0 } }
    throw new Error(`unexpected GET ${url}`)
  })
  mockedPost.mockResolvedValue({ data: { id: 1 } })
})

afterEach(cleanup)

describe('CustomersPage create dialog — CU8 structured billing address', () => {
  it('renders structured billing address fields with an ISO country input and no misleading placeholder', async () => {
    renderPage()
    await openCreateDialogAddressesTab()

    expect(screen.getByLabelText('Street')).toBeTruthy()
    expect(screen.getByLabelText('Street 2')).toBeTruthy()
    expect(screen.getByLabelText('City')).toBeTruthy()
    expect(screen.getByLabelText('State / Province')).toBeTruthy()
    expect(screen.getByLabelText('Postal Code')).toBeTruthy()
    expect(screen.getByLabelText('Country (ISO code)')).toBeTruthy()

    expect(screen.queryByPlaceholderText(/auto-populated from structured fields/i)).toBeNull()
  })

  it('posts the billing address as an addresses[] record with the ISO country code', async () => {
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /add customer/i }))
    await waitFor(() => expect(screen.getByText('Add New Customer')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('Name *'), { target: { value: 'Klinik Sihat' } })
    fireEvent.mouseDown(screen.getByRole('tab', { name: /addresses/i }))
    await waitFor(() => expect(screen.getByLabelText('Street')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('Street'), { target: { value: '12 Jalan Ampang' } })
    fireEvent.change(screen.getByLabelText('Street 2'), { target: { value: 'Suite 4-1' } })
    fireEvent.change(screen.getByLabelText('City'), { target: { value: 'Kuala Lumpur' } })
    fireEvent.change(screen.getByLabelText('State / Province'), { target: { value: '14' } })
    fireEvent.change(screen.getByLabelText('Postal Code'), { target: { value: '50450' } })
    fireEvent.change(screen.getByLabelText('Country (ISO code)'), { target: { value: 'my' } })

    fireEvent.click(screen.getByRole('button', { name: /create customer/i }))

    await waitFor(() => expect(mockedPost).toHaveBeenCalled())

    const [url, body] = mockedPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/customers')
    expect(body.name).toBe('Klinik Sihat')
    expect(body.addresses).toEqual([
      {
        type: 'billing',
        street: '12 Jalan Ampang',
        street2: 'Suite 4-1',
        city: 'Kuala Lumpur',
        state: '14',
        zip: '50450',
        country: 'MY',
        is_default: true,
      },
    ])
  })

  it('does not send an empty billing address when no address fields were filled', async () => {
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /add customer/i }))
    await waitFor(() => expect(screen.getByText('Add New Customer')).toBeTruthy())

    fireEvent.change(screen.getByLabelText('Name *'), { target: { value: 'Walk-in' } })
    fireEvent.click(screen.getByRole('button', { name: /create customer/i }))

    await waitFor(() => expect(mockedPost).toHaveBeenCalled())

    const [, body] = mockedPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(body.addresses).toBeUndefined()
  })
})
