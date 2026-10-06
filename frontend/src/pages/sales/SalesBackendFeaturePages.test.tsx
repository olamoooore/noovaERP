// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
import { baseNavItems, flattenNav } from '@/lib/navigation'
import { BlanketOrdersPage } from './BlanketOrdersPage'
import { BlanketOrderDetailPage } from './BlanketOrderDetailPage'
import { RecurringOrdersPage } from './RecurringOrdersPage'
import { SalesPipelinePage } from './SalesPipelinePage'

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

const { apiGet, apiPost, mockNavigate } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), mockNavigate: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => mockNavigate }
})

// The search comboboxes have their own tests; here a plain input stands in so
// the page's payload can be asserted.
vi.mock('@/components/ui/CustomerSearchCombobox', () => ({
  CustomerSearchCombobox: ({ value, onValueChange }: { value: string; onValueChange: (v: string) => void }) => (
    <input aria-label="Customer" value={value} onChange={(e) => onValueChange(e.target.value)} />
  ),
}))
vi.mock('@/components/inventory/ProductSearchCombobox', () => ({
  ProductSearchCombobox: ({ value, onValueChange }: { value: string; onValueChange: (v: string, p?: unknown) => void }) => (
    <input
      aria-label="Product"
      value={value}
      onChange={(e) => onValueChange(e.target.value, { id: Number(e.target.value), name: 'Widget', sku: 'W-1', price: 12 })}
    />
  ),
}))

function asAdmin() {
  useAuthStore.setState({ role: 'Admin', user: { id: 1, name: 'Admin', email: 'a@test.local' }, token: 't', permissions: [] })
}
function asViewer() {
  useAuthStore.setState({
    role: 'Viewer',
    user: { id: 2, name: 'Viewer', email: 'v@test.local' },
    token: 't',
    permissions: ['sales.view'],
  })
}

function renderAt(path: string, routePath: string, element: JSX.Element) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={routePath} element={element} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const blanketSummary = {
  blanket: {
    id: 3,
    blanket_number: 'BO-0003',
    customer_id: 1,
    customer_name: 'Klinik Ali',
    status: 'active',
    valid_from: '2026-01-01',
    valid_to: '2026-12-31',
    currency: 'MYR',
  },
  lines: [
    {
      blanket_line_id: 31,
      product_id: 7,
      product_name: 'Widget',
      sku: 'W-1',
      committed_qty: 100,
      released_qty: 40,
      remaining_qty: 60,
      unit_price: 12,
      committed_value: 1200,
      released_value: 480,
      remaining_value: 720,
    },
  ],
  totals: { committed_value: 1200, released_value: 480, remaining_value: 720 },
}

beforeEach(() => {
  asAdmin()
  mockNavigate.mockReset()
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/blanket-orders') {
      return {
        data: {
          data: [
            {
              id: 3,
              blanket_number: 'BO-0003',
              name: 'Annual supply',
              status: 'active',
              valid_from: '2026-01-01',
              valid_to: '2026-12-31',
              currency: 'MYR',
              customer: { id: 1, name: 'Klinik Ali' },
              lines: [],
            },
          ],
          current_page: 1,
          last_page: 1,
        },
      }
    }
    if (url === '/sales/blanket-orders/3') return { data: { data: blanketSummary } }
    if (url === '/sales/recurrences') {
      return {
        data: {
          data: [
            {
              id: 9,
              sales_order_id: 5,
              sales_order: { id: 5, sales_order_number: 'SO-0005' },
              interval: 'monthly',
              status: 'active',
              start_date: '2026-11-01',
              next_run_date: '2026-12-01',
              cycles_generated: 1,
              max_cycles: 12,
            },
          ],
          current_page: 1,
          last_page: 1,
        },
      }
    }
    if (url === '/sales/analytics/pipeline') {
      return {
        data: {
          data: {
            period: { from: '2026-10-01', to: '2026-10-31' },
            base_currency: 'MYR',
            unconverted: {},
            orders_by_status: [{ status: 'confirmed', count: 2, value: 300 }],
            pipeline: { open_orders: 2, open_value: 300, won_orders: 1, won_value: 150 },
            quotations: {
              total: 4,
              open: 1,
              won: 2,
              lost: 1,
              win_rate_pct: 66.7,
              aging: { '0_7_days': 1, '8_30_days': 0, '31_60_days': 0, over_60_days: 0 },
            },
            expected_revenue_by_month: [{ month: '2026-10', count: 2, value: 300 }],
            salespeople: [{ user_id: 1, name: 'Aisyah', orders: 3, value: 450, won: 1 }],
            product_mix: [{ product_id: 7, product_name: 'Widget', sku: 'W-1', quantity: 10, value: 120 }],
          },
        },
      }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { message: 'ok' } })
})

afterEach(cleanup)

describe('Sales menu entries for the backend features (#376)', () => {
  const flat = flattenNav(baseNavItems)
  it.each(['/sales/blanket-orders', '/sales/recurring-orders', '/sales/pipeline'])(
    'lists %s under the sales.view gate',
    (href) => {
      const entry = flat.find((item) => item.href === href)
      expect(entry).toBeDefined()
      expect(entry?.requiredPermission).toBe('sales.view')
    },
  )
})

describe('SalesPipelinePage (#376 / #150)', () => {
  it('loads GET /sales/analytics/pipeline for the chosen period', async () => {
    renderAt('/sales/pipeline', '/sales/pipeline', <SalesPipelinePage />)

    await screen.findByText('Aisyah')
    expect(apiGet).toHaveBeenCalledWith('/sales/analytics/pipeline', {
      params: expect.objectContaining({ from_date: expect.any(String), to_date: expect.any(String) }),
    })
    expect(screen.getByTestId('pipeline-win-rate').textContent).toContain('66.7')

    fireEvent.change(screen.getByLabelText(/^from$/i), { target: { value: '2026-01-01' } })
    fireEvent.change(screen.getByLabelText(/^to$/i), { target: { value: '2026-03-31' } })
    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/sales/analytics/pipeline', {
        params: { from_date: '2026-01-01', to_date: '2026-03-31' },
      }),
    )
  })
})

describe('RecurringOrdersPage (#376 / #151)', () => {
  it('lists schedules from GET /sales/recurrences and pauses through its route', async () => {
    renderAt('/sales/recurring-orders', '/sales/recurring-orders', <RecurringOrdersPage />)

    const row = await screen.findByTestId('recurrence-row-9')
    expect(within(row).getByText('SO-0005')).toBeTruthy()
    fireEvent.click(within(row).getByRole('button', { name: /pause/i }))
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/sales/recurrences/9/pause'))
  })

  it('filters by status', async () => {
    renderAt('/sales/recurring-orders', '/sales/recurring-orders', <RecurringOrdersPage />)
    await screen.findByTestId('recurrence-row-9')

    fireEvent.change(screen.getByLabelText(/status/i), { target: { value: 'paused' } })
    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/sales/recurrences', { params: expect.objectContaining({ status: 'paused' }) }),
    )
  })

  it('hides schedule actions from a user without sales.write', async () => {
    asViewer()
    renderAt('/sales/recurring-orders', '/sales/recurring-orders', <RecurringOrdersPage />)
    const row = await screen.findByTestId('recurrence-row-9')
    expect(within(row).queryByRole('button', { name: /pause/i })).toBeNull()
  })
})

describe('BlanketOrdersPage (#376 / #152)', () => {
  it('lists agreements from GET /sales/blanket-orders', async () => {
    renderAt('/sales/blanket-orders', '/sales/blanket-orders', <BlanketOrdersPage />)
    expect(await screen.findByText('BO-0003')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith('/sales/blanket-orders', { params: expect.any(Object) })
  })

  it('creates an agreement through POST /sales/blanket-orders', async () => {
    apiPost.mockResolvedValue({ data: { message: 'Blanket order created successfully', data: { id: 4 } } })
    renderAt('/sales/blanket-orders', '/sales/blanket-orders', <BlanketOrdersPage />)
    await screen.findByText('BO-0003')

    fireEvent.click(screen.getByRole('button', { name: /new blanket order/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Customer'), { target: { value: '1' } })
    fireEvent.change(within(dialog).getByLabelText(/agreement name/i), { target: { value: 'FY27 supply' } })
    fireEvent.change(within(dialog).getByLabelText(/valid from/i), { target: { value: '2027-01-01' } })
    fireEvent.change(within(dialog).getByLabelText(/valid to/i), { target: { value: '2027-12-31' } })
    fireEvent.change(within(dialog).getByLabelText('Product'), { target: { value: '7' } })
    fireEvent.change(within(dialog).getByLabelText(/committed quantity/i), { target: { value: '500' } })
    fireEvent.change(within(dialog).getByLabelText(/unit price/i), { target: { value: '11.5' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create agreement/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/blanket-orders', {
        customer_id: 1,
        name: 'FY27 supply',
        valid_from: '2027-01-01',
        valid_to: '2027-12-31',
        notes: null,
        lines: [{ product_id: 7, description: 'Widget', committed_qty: 500, unit_price: 11.5 }],
      }),
    )
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/sales/blanket-orders/4'))
  })

  it('hides creation from a user without sales.write', async () => {
    asViewer()
    renderAt('/sales/blanket-orders', '/sales/blanket-orders', <BlanketOrdersPage />)
    await screen.findByText('BO-0003')
    expect(screen.queryByRole('button', { name: /new blanket order/i })).toBeNull()
  })
})

describe('BlanketOrderDetailPage (#376 / #152)', () => {
  it('shows committed/released/remaining from GET /sales/blanket-orders/{id}', async () => {
    renderAt('/sales/blanket-orders/3', '/sales/blanket-orders/:id', <BlanketOrderDetailPage />)
    const row = await screen.findByTestId('blanket-line-31')
    expect(row.textContent).toContain('100')
    expect(row.textContent).toContain('40')
    expect(row.textContent).toContain('60')
  })

  it('releases a call-off order through POST /sales/blanket-orders/{id}/release', async () => {
    apiPost.mockResolvedValue({
      data: { message: 'Release order created successfully', sales_order: { id: 55, sales_order_number: 'SO-0055' } },
    })
    renderAt('/sales/blanket-orders/3', '/sales/blanket-orders/:id', <BlanketOrderDetailPage />)
    await screen.findByTestId('blanket-line-31')

    fireEvent.click(screen.getByRole('button', { name: /release order/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/release quantity for widget/i), { target: { value: '15' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create release order/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/blanket-orders/3/release', {
        lines: [{ blanket_line_id: 31, quantity: 15 }],
        notes: null,
      }),
    )
    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/sales/orders/55'))
  })

  it('hides the release action from a user without sales.write', async () => {
    asViewer()
    renderAt('/sales/blanket-orders/3', '/sales/blanket-orders/:id', <BlanketOrderDetailPage />)
    await screen.findByTestId('blanket-line-31')
    expect(screen.queryByRole('button', { name: /release order/i })).toBeNull()
  })
})
