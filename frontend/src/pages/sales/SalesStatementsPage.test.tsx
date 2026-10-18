// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SalesStatementsPage } from './SalesStatementsPage'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const CUSTOMER = {
  id: 7,
  code: 'KA-1',
  name: 'Klinik Ali',
  email: 'billing@klinik.example',
  legal_entity_id: 3,
}

const PREVIEW_ROWS = [
  {
    id: 11,
    code: 'C-11',
    name: 'Alpha Clinic',
    email: 'ap@alpha.example',
    closing_balance: 1250.5,
    overdue_amount: 250.5,
    oldest_overdue_days: 45,
  },
  {
    id: 12,
    code: 'C-12',
    name: 'Beta Hospital',
    email: 'ap@beta.example',
    closing_balance: 800,
    overdue_amount: 0,
    oldest_overdue_days: 0,
  },
]

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()

  apiGet.mockImplementation(async (url: string) => {
    if (url === '/customers/7') return { data: { data: CUSTOMER } }
    if (url === '/customers' || url.startsWith('/customers?')) {
      return { data: { data: { data: [CUSTOMER] } } }
    }
    if (url.startsWith('/accounting/customers/7/statement')) {
      return {
        data: {
          statement_id: '31',
          filename: 'SOA_KA-1_20260131',
          payload: {
            closing_balance: 1250.5,
            total_debits: 1500,
            total_credits: 249.5,
            aging: {
              current: 1000,
              days_1_30: 125.5,
              days_31_60: 100,
              days_61_90: 25,
              days_90_plus: 0,
              total: 1250.5,
            },
            transactions: [],
          },
        },
      }
    }
    return { data: { data: [] } }
  })

  apiPost.mockImplementation(async (url: string) => {
    if (url === '/accounting/customer-statements/batch/preview') {
      return {
        data: {
          data: PREVIEW_ROWS,
          summary: {
            customer_count: 2,
            total_closing_balance: 2050.5,
            total_overdue: 250.5,
          },
        },
      }
    }
    if (url === '/accounting/customer-statements/send') {
      return { data: { id: 41, customer_id: 7, closing_balance: 1250.5 } }
    }
    if (url === '/accounting/customer-statements/batch') {
      return {
        data: {
          data: {
            id: 900,
            status: 'completed',
            total_customers: 2,
            processed_customers: 2,
            generated_count: 2,
            sent_count: 2,
            failed_count: 0,
          },
        },
      }
    }
    return { data: {} }
  })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/sales/statements']}>
        <SalesStatementsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SalesStatementsPage (#607)', () => {
  it('renders the statements page with the single and batch tabs', async () => {
    renderPage()

    expect(await screen.findByText('Customer Statements')).toBeTruthy()
    expect(screen.getByRole('tab', { name: /single/i })).toBeTruthy()
    expect(screen.getByRole('tab', { name: /batch/i })).toBeTruthy()
  })

  it('previews a single customer statement with aging buckets', async () => {
    renderPage()
    await screen.findByText('Customer Statements')

    const comboboxes = await screen.findAllByRole('combobox')
    const customerPicker = comboboxes.find((element) =>
      element.textContent?.includes('Search customer name or code'),
    )
    expect(customerPicker).toBeTruthy()
    fireEvent.click(customerPicker!)
    fireEvent.change(await screen.findByPlaceholderText('Type to search...'), {
      target: { value: 'Klinik' },
    })

    const option = await screen.findByText('Klinik Ali')
    fireEvent.click(option)

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        expect.stringContaining('/accounting/customers/7/statement'),
        expect.objectContaining({
          params: expect.objectContaining({ format: 'json' }),
        }),
      )
    })

    expect(await screen.findByText('Aging')).toBeTruthy()
    expect(screen.getByText('Closing balance')).toBeTruthy()
  })

  it('previews the batch list with closing balance and overdue columns', async () => {
    renderPage()
    await screen.findByText('Customer Statements')

    fireEvent.mouseDown(screen.getByRole('tab', { name: /batch/i }))
    fireEvent.click(await screen.findByRole('button', { name: /preview list/i }))

    expect(await screen.findByText('Alpha Clinic')).toBeTruthy()
    expect(screen.getByText('Beta Hospital')).toBeTruthy()
    expect(screen.getByText('Overdue')).toBeTruthy()

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith(
        '/accounting/customer-statements/batch/preview',
        expect.objectContaining({ with_balance: true }),
      )
    })
  })
})
