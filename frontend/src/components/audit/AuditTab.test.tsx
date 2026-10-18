// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuditTab } from './AuditTab'
import { useAuthStore } from '@/store/authStore'

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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
  },
}))

const fieldRow = {
  id: 'audit-11',
  source: 'audit_log',
  occurred_at: '2026-10-03T10:00:00+00:00',
  user: { id: 3, name: 'Ali Admin' },
  kind: 'field',
  event: 'invoice_updated',
  field: 'unit_price',
  path: 'lines.0.unit_price',
  old_value: 500,
  new_value: 875,
  request_id: 'req-905',
}

const lifecycleRow = {
  id: 'audit-12',
  source: 'audit_log',
  occurred_at: '2026-10-03T11:00:00+00:00',
  user: { id: 3, name: 'Ali Admin' },
  kind: 'lifecycle',
  event: 'invoice_posted',
  field: 'status',
  path: 'status',
  old_value: 'draft',
  new_value: 'posted',
  request_id: 'req-905-post',
}

const payload = {
  data: [fieldRow, lifecycleRow],
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
  filters: {
    fields: ['unit_price', 'status'],
    users: [{ id: 3, name: 'Ali Admin' }],
  },
}

function signedInWith(permissions: string[], role = 'Sales') {
  useAuthStore.getState().setAuth(
    { id: 3, name: 'Ali Admin', email: 'ali@example.com' },
    'test-token',
    'tenant-1',
    role,
    permissions,
  )
}

beforeEach(() => {
  localStorage.clear()
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url.endsWith('/export')) {
      return { data: 'Time,User,Event,Field,Old Value,New Value,Request ID\n' }
    }
    const field = config?.params?.field as string | undefined
    const userId = config?.params?.user_id as number | undefined
    let data = payload.data
    if (field) data = data.filter((row) => row.field === field || row.path === field)
    if (userId) data = data.filter((row) => row.user?.id === userId)
    return { data: { ...payload, data, meta: { ...payload.meta, total: data.length } } }
  })
})

afterEach(cleanup)

function renderTab(permission = 'invoices.view') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <AuditTab endpoint="/invoices/5/audit" permission={permission} />
    </QueryClientProvider>,
  )
}

describe('AuditTab — read-only document audit tab (#905 / PCU1)', () => {
  it('shows time, user, field, old -> new and lifecycle events from the audit feed', async () => {
    signedInWith(['invoices.view'])
    renderTab()

    expect(await screen.findByTestId('audit-tab')).toBeTruthy()
    expect(await screen.findByText('unit_price')).toBeTruthy()
    expect(await screen.findByText('500')).toBeTruthy()
    expect(await screen.findByText('875')).toBeTruthy()
    expect(await screen.findByText('invoice_posted')).toBeTruthy()
    expect((await screen.findAllByText('Ali Admin')).length).toBeGreaterThan(0)
    expect(apiGet).toHaveBeenCalledWith(
      '/invoices/5/audit',
      expect.objectContaining({ params: expect.objectContaining({}) }),
    )
  })

  it('filters the feed by field and by user through the endpoint', async () => {
    signedInWith(['invoices.view'])
    renderTab()

    await screen.findByText('unit_price')

    fireEvent.change(await screen.findByLabelText('Filter by field'), {
      target: { value: 'unit_price' },
    })

    await waitFor(() => {
      const lastCall = apiGet.mock.calls[apiGet.mock.calls.length - 1]
      expect(lastCall[1].params.field).toBe('unit_price')
    })
    await waitFor(() => expect(screen.queryByText('invoice_posted')).toBeNull())
    expect(screen.getByText('unit_price')).toBeTruthy()

    fireEvent.change(screen.getByLabelText('Filter by user'), {
      target: { value: '3' },
    })
    await waitFor(() => {
      const lastCall = apiGet.mock.calls[apiGet.mock.calls.length - 1]
      expect(lastCall[1].params.user_id).toBe(3)
    })
  })

  it('exports the filtered feed as CSV from the read-only export route', async () => {
    signedInWith(['invoices.view'])
    renderTab()

    await screen.findByText('unit_price')

    const createObjectURL = vi.fn(() => 'blob:audit')
    const revokeObjectURL = vi.fn()
    Object.defineProperty(window.URL, 'createObjectURL', { configurable: true, value: createObjectURL })
    Object.defineProperty(window.URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL })

    fireEvent.click(screen.getByRole('button', { name: /export audit csv/i }))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/invoices/5/audit/export',
        expect.objectContaining({ responseType: 'blob' }),
      )
    })
  })

  it('renders no audit UI and makes no audit request for a user without the document view permission', async () => {
    signedInWith(['sales.view'], 'Sales')

    const { container } = renderTab('invoices.view')

    await waitFor(() => expect(container.firstChild).toBeNull())
    expect(apiGet).not.toHaveBeenCalled()
  })
})
