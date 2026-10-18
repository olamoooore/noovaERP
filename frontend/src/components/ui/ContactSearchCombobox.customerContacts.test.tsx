// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ContactSearchCombobox } from './ContactSearchCombobox'

if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false, media: query, onchange: null,
    addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {}, dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} }
}
if (!HTMLElement.prototype.scrollIntoView) HTMLElement.prototype.scrollIntoView = () => {}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet } }))

const customer = {
  id: 7,
  name: 'Klinik Ali',
  customer_contacts: [
    { id: 11, name: 'Aminah Yusof', email: 'aminah@example.test' },
    { id: 12, name: 'Badrul Hisham', email: 'badrul@example.test' },
  ],
  addresses: [
    { id: 21, type: 'billing', street: '1 Jalan Ampang', city: 'Kuala Lumpur' },
  ],
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/7') return { data: { data: customer } }
    if (url === '/contacts/uuid-saved-old') return { data: { data: { id: 'uuid-saved-old', display_name: 'Former Accounts Person' } } }
    return { data: { data: [] } }
  })
})
afterEach(cleanup)

const customerFetches = () => apiGet.mock.calls.filter(([url]) => url === '/customers/7').length

function renderPicker(value = '') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <ContactSearchCombobox value={value} onValueChange={() => {}} customerId={7} source="customer_contacts" />
    </QueryClientProvider>,
  )
}

describe('ContactSearchCombobox customer contacts (#301)', () => {
  it('fetches the customer once and filters its contacts locally while typing', async () => {
    renderPicker()
    fireEvent.click(screen.getByRole('combobox'))
    expect(await screen.findByText('Aminah Yusof')).toBeTruthy()
    expect(customerFetches()).toBe(1)

    const input = screen.getByPlaceholderText('Type to search...')
    fireEvent.change(input, { target: { value: 'Bad' } })
    await waitFor(() => {
      expect(screen.queryByText('Aminah Yusof')).toBeNull()
      expect(screen.getByText('Badrul Hisham')).toBeTruthy()
    }, { timeout: 2000 })

    fireEvent.change(input, { target: { value: 'Badrul H' } })
    await new Promise((resolve) => setTimeout(resolve, 450))
    fireEvent.change(input, { target: { value: 'Ami' } })
    expect(await screen.findByText('Aminah Yusof', {}, { timeout: 2000 })).toBeTruthy()

    // The search text must never refetch the whole customer record.
    expect(customerFetches()).toBe(1)
    expect(apiGet.mock.calls.some(([url]) => url === '/contacts')).toBe(false)
  })

  it('shows the selected contact name from the same single customer fetch', async () => {
    renderPicker('12')
    await waitFor(() => expect(screen.getByRole('combobox').textContent).toContain('Badrul Hisham'))
    fireEvent.click(screen.getByRole('combobox'))
    expect(await screen.findByText('Aminah Yusof')).toBeTruthy()
    expect(customerFetches()).toBe(1)
  })

  it('#1007: a saved core-contact UUID outside the customer list shows its name, not "Loading..."', async () => {
    renderPicker('uuid-saved-old')
    await waitFor(() => expect(screen.getByRole('combobox').textContent).toContain('Former Accounts Person'))
  })
})
