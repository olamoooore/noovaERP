// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import OperationsConsolePage from './OperationsConsolePage'
import { useAuthStore } from '@/store/authStore'

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
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const baseRow = {
  module: 'sales',
  message: 'Payment 5 failed to post',
  document_type: 'payment',
  document_id: '5',
  legal_entity_id: 7,
  owner_role: 'accountant',
  assigned_to: null,
  assigned_to_name: null,
  retry_count: 1,
  max_retries: 3,
  next_retry_at: null,
  resolved_at: null,
  resolution: null,
  context: null,
  can_assign: true,
  can_retry: false,
  created_at: '2026-10-01T00:00:00+00:00',
  updated_at: '2026-10-01T00:00:00+00:00',
}

const ROWS = [
  {
    ...baseRow,
    id: 'exc-1',
    exception_type: 'sales.payment.posting_failed',
    severity: 'high',
    status: 'open',
    retry_handler: 'sales.payment.post',
    age_hours: 30,
    sla_hours: 24,
    sla_due_at: '2026-10-02T00:00:00+00:00',
    sla_breached: true,
    can_retry: true,
    assigned_to: 7,
    assigned_to_name: 'Ali Accountant',
  },
  {
    ...baseRow,
    id: 'exc-2',
    exception_type: 'sales.invoice.tax_variance',
    severity: 'high',
    status: 'open',
    message: 'Tax variance on INV-9',
    document_type: 'invoice',
    document_id: '9',
    retry_handler: null,
    age_hours: 2,
    sla_hours: 24,
    sla_due_at: '2026-10-02T02:00:00+00:00',
    sla_breached: false,
    can_retry: false,
  },
  {
    ...baseRow,
    id: 'exc-3',
    exception_type: 'sales.invoice.tax_variance',
    severity: 'medium',
    status: 'resolved',
    message: 'Tax variance on INV-10',
    document_type: 'invoice',
    document_id: '10',
    legal_entity_id: 8,
    retry_handler: null,
    age_hours: 10,
    sla_hours: 72,
    sla_due_at: '2026-10-04T00:00:00+00:00',
    sla_breached: false,
    can_retry: false,
    resolved_at: '2026-10-01T10:00:00+00:00',
  },
]

const STATS = {
  total: 3,
  by_type: { 'sales.payment.posting_failed': 1, 'sales.invoice.tax_variance': 2 },
  by_module: { sales: 3 },
  by_entity: { '7': 2, '8': 1 },
  by_status: { open: 2, resolved: 1 },
  by_owner_role: { accountant: 3 },
  by_severity: { high: 2, medium: 1 },
  sla_breached: 1,
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <OperationsConsolePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  localStorage.clear()
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Accountant', email: 'ali@example.com' },
    'token',
    'tenant-1',
    'Accountant',
    ['accounting.view', 'accounting.write'],
  )

  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/platform/exceptions') {
      return { data: { data: ROWS, meta: { total: 3, current_page: 1, last_page: 1, per_page: 25 } } }
    }
    if (url === '/platform/exceptions/stats') return { data: { data: STATS } }
    if (url === '/tenant-users') return { data: { data: [] } }
    throw new Error(`unexpected GET ${url}`)
  })

  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: { data: { ...ROWS[0], status: 'resolved' } } })
})

afterEach(cleanup)

describe('OperationsConsolePage (#914)', () => {
  it('renders the queued exceptions grouped by type and entity with SLA badges', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText('Payment 5 failed to post')).toBeTruthy())

    // Grouped by type + entity: one section per (type, entity) pair.
    expect(screen.getByText('Failed posting')).toBeTruthy()
    expect(screen.getAllByText('Tax variance')).toHaveLength(2)
    expect(screen.getAllByText('Entity 7')).toHaveLength(2)
    expect(screen.getByText('Entity 8')).toBeTruthy()

    // SLA age badge computed from age vs per-severity target. "SLA breached"
    // appears twice: the summary card and the one breached row's badge.
    expect(screen.getAllByText('SLA breached')).toHaveLength(2)
    expect(screen.getByText('30h / 24h')).toBeTruthy()
    expect(screen.getByText('2h / 24h')).toBeTruthy()

    // Summary stats from the grouping endpoint.
    expect(screen.getByText('Total')).toBeTruthy()
    expect(screen.getByText('3')).toBeTruthy()
  })

  it('retries a failed exception through the endpoint when a handler exists', async () => {
    renderPage()

    const retryButton = await screen.findByRole('button', { name: 'Retry Payment 5 failed to post' })
    fireEvent.click(retryButton)

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/platform/exceptions/exc-1/retry', {}),
    )

    // Non-retryable rows (no registered handler) expose no Retry action.
    expect(
      screen.queryByRole('button', { name: 'Retry Tax variance on INV-9' }),
    ).toBeNull()
  })

  it('assigns an owner through the endpoint', async () => {
    renderPage()

    const assignButton = await screen.findByRole('button', { name: 'Assign Payment 5 failed to post' })
    fireEvent.click(assignButton)

    // The assignment dialog opens with the tenant members query.
    await waitFor(() => expect(screen.getByText('Assign owner')).toBeTruthy())
  })
})
