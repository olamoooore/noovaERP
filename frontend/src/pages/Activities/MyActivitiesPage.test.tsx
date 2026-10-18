// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import MyActivitiesPage from './MyActivitiesPage'

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

const payload = {
  data: {
    overdue: [
      {
        id: 1,
        activity_type: 'call',
        summary: 'Call the customer',
        note: null,
        due_date: '2026-09-30T09:00:00+00:00',
        status: 'planned',
        subject: { type: 'App\\Models\\Invoice', id: 11, label: 'INV-0001' },
      },
    ],
    today: [
      {
        id: 2,
        activity_type: 'follow_up',
        summary: 'Send the updated quote',
        note: null,
        due_date: '2026-10-04T09:00:00+00:00',
        status: 'planned',
        subject: { type: 'App\\Models\\Quotation', id: 12, label: 'QUO-0002' },
      },
    ],
    upcoming: [
      {
        id: 3,
        activity_type: 'todo',
        summary: 'Check the delivery',
        note: null,
        due_date: '2026-10-10T09:00:00+00:00',
        status: 'planned',
        subject: { type: 'Modules\\Sales\\Models\\DeliveryOrder', id: 13, label: 'DO-0003' },
      },
    ],
    total: 3,
  },
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockResolvedValue({ data: payload })
  apiPost.mockReset()
  apiPost.mockResolvedValue({ data: { data: { status: 'done' } } })
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/activities']}>
        <MyActivitiesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('MyActivitiesPage (#637)', () => {
  it('renders activities grouped overdue / today / upcoming with document links', async () => {
    renderPage()

    await screen.findByText('Call the customer')
    expect(screen.getByText('Overdue')).toBeTruthy()
    expect(screen.getByText('Today')).toBeTruthy()
    expect(screen.getByText('Upcoming')).toBeTruthy()
    expect(screen.getByText('Send the updated quote')).toBeTruthy()
    expect(screen.getByText('Check the delivery')).toBeTruthy()

    const invoiceLink = screen.getByRole('link', { name: /INV-0001/ })
    expect(invoiceLink.getAttribute('href')).toBe('/invoices/11')

    const quoteLink = screen.getByRole('link', { name: /QUO-0002/ })
    expect(quoteLink.getAttribute('href')).toBe('/quotations/12')

    const deliveryLink = screen.getByRole('link', { name: /DO-0003/ })
    expect(deliveryLink.getAttribute('href')).toBe('/delivery-orders/13')
  })

  it('marks an activity done through POST /activities/{id}/complete', async () => {
    renderPage()

    await screen.findByText('Call the customer')
    fireEvent.click(screen.getAllByRole('button', { name: /mark done/i })[0])

    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/activities/1/complete'),
    )
  })

  it('snoozes an activity to a future due date through POST /activities/{id}/snooze', async () => {
    renderPage()

    await screen.findByText('Call the customer')
    const snoozeButtons = screen.getAllByRole('button', { name: /snooze/i })
    fireEvent.pointerDown(snoozeButtons[0], { button: 0, ctrlKey: false })

    const tomorrow = await screen.findByText('Tomorrow')
    fireEvent.click(tomorrow)

    await waitFor(() => {
      const call = apiPost.mock.calls.find((c) => c[0] === '/activities/1/snooze')
      expect(call).toBeTruthy()
      expect(typeof call![1].due_date).toBe('string')
      expect(new Date(call![1].due_date).getTime()).toBeGreaterThan(Date.now())
    })
  })
})
