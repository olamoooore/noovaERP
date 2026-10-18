// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import { SalesActionQueues } from './SalesActionQueues'

/**
 * #410: the caller's due scheduled activities feed the Sales cockpit — a tile
 * with the count, the overdue count, the documents and a link to My activities.
 */

vi.mock('@/lib/api', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const mockedGet = vi.mocked(api.get)

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
  mockedGet.mockResolvedValue({
    data: {
      data: {
        mine: false,
        expiring_window_days: 7,
        queues: [],
        activities: {
          label: 'My activities due',
          count: 2,
          overdue: 1,
          link: '/activities',
          items: [
            { id: 11, number: 'QT-0410', customer: 'Acme', date: '2026-10-06', amount: null, currency_code: null, link: '/sales/quotes/4' },
          ],
        },
      },
    },
  })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('#410 — due activities on the Sales cockpit', () => {
  it('renders the due-activities tile linking to My activities and the document', async () => {
    renderQueues()

    expect(await screen.findByTestId('action-queue-count-activities_due')).toHaveProperty('textContent', '2')
    expect(screen.getByTestId('action-queue-overdue-activities_due').textContent).toBe('1 overdue')
    expect(screen.getByTestId('action-queue-link-activities_due').getAttribute('href')).toBe('/activities')
    expect(screen.getByText('QT-0410').closest('a')?.getAttribute('href')).toBe('/sales/quotes/4')
  })
})
