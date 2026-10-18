// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import { SalesActionQueues } from './SalesActionQueues'

/**
 * #365: the Sales cockpit shows one tile per action queue the API returns —
 * count, most urgent rows, a link to the matching filtered list — and "Only my
 * documents" re-queries with mine=1.
 */

vi.mock('@/lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const mockedGet = vi.mocked(api.get)

const payload = (mine: boolean) => ({
  data: {
    data: {
      mine,
      expiring_window_days: 7,
      queues: [
        {
          key: 'quotes_expiring',
          label: 'Quotes expiring in 7 days',
          document: 'quotation',
          count: mine ? 1 : 3,
          link: `/sales/quotes?action_queue=quotes_expiring${mine ? '&mine=1' : ''}`,
          items: [
            { id: 5, number: 'QT-0005', customer: 'Acme', date: '2026-10-09', amount: 120, currency_code: 'USD', link: '/sales/quotes/5' },
          ],
        },
        {
          key: 'overdue_invoices',
          label: 'Overdue invoices',
          document: 'invoice',
          count: 0,
          link: '/invoices?action_queue=overdue_invoices',
          secondary_link: '/accounting/customers/followup-reports',
          items: [],
        },
      ],
    },
  },
})

function renderQueues() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <SalesActionQueues />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  mockedGet.mockImplementation(async (_url: string, config?: { params?: Record<string, unknown> }) =>
    payload(config?.params?.mine === 1),
  )
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('#365 — Sales cockpit action queues', () => {
  it('renders each queue with its count, rows and a link to the filtered list', async () => {
    renderQueues()

    expect(await screen.findByTestId('action-queue-count-quotes_expiring')).toHaveProperty('textContent', '3')
    expect(screen.getByTestId('action-queue-link-quotes_expiring').getAttribute('href')).toBe(
      '/sales/quotes?action_queue=quotes_expiring',
    )
    expect(screen.getByText('QT-0005').closest('a')?.getAttribute('href')).toBe('/sales/quotes/5')

    expect(screen.getByTestId('action-queue-count-overdue_invoices').textContent).toBe('0')
    expect(screen.getByText('Customer follow-up').closest('a')?.getAttribute('href')).toBe(
      '/accounting/customers/followup-reports',
    )
    expect(mockedGet).toHaveBeenCalledWith('/sales/dashboard/action-queues', { params: {} })
  })

  it('"Only my documents" re-queries with mine=1', async () => {
    renderQueues()
    await screen.findByTestId('action-queue-count-quotes_expiring')

    fireEvent.click(screen.getByTestId('action-queues-mine'))

    await waitFor(() =>
      expect(mockedGet).toHaveBeenCalledWith('/sales/dashboard/action-queues', { params: { mine: 1 } }),
    )
    await waitFor(() => expect(screen.getByTestId('action-queue-count-quotes_expiring').textContent).toBe('1'))
    expect(screen.getByTestId('action-queue-link-quotes_expiring').getAttribute('href')).toContain('mine=1')
  })

  it('hides itself when the endpoint is not available to the user', async () => {
    mockedGet.mockRejectedValue({ response: { status: 403 } })
    const { container } = renderQueues()

    await waitFor(() => expect(mockedGet).toHaveBeenCalled())
    expect(container.querySelector('[aria-label="Action queues"]')).toBeNull()
  })
})
