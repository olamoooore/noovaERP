// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CustomerFollowupReports } from './CustomerFollowupReports'

// #328: the follow-up report sends a customer's overdue-invoice reminders.

const { apiGet, apiPost, toast } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), toast: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet, post: apiPost } }))
vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast }) }))

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  toast.mockReset()
  apiGet.mockImplementation(async () => ({
    data: {
      data: {
        data: [
          { customer_id: 11, customer_name: 'Overdue Co', current: 0, days_1_30: 120, days_31_60: 0, days_61_90: 0, days_90_plus: 0, total: 120 },
          { customer_id: 12, customer_name: 'Current Co', current: 80, days_1_30: 0, days_31_60: 0, days_61_90: 0, days_90_plus: 0, total: 80 },
        ],
      },
    },
  }))
  apiPost.mockImplementation(async () => ({
    data: {
      message: '0 of 1 reminder(s) sent.',
      data: { sent: 0, results: [{ invoice_number: 'INV-1', outcome: 'skipped', reason: 'Customer prefers to be contacted by phone; follow up manually.' }] },
    },
  }))
})

afterEach(cleanup)

describe('CustomerFollowupReports (#328)', () => {
  it('offers Send Reminder only for customers with an overdue balance and reports skips', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <CustomerFollowupReports />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await screen.findByText('Overdue Co')
    const buttons = screen.getAllByRole('button', { name: /send reminder/i })
    expect(buttons).toHaveLength(1)

    fireEvent.click(buttons[0])

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/customers/11/followup-reminders'))
    await waitFor(() =>
      expect(toast).toHaveBeenCalledWith(expect.objectContaining({
        variant: 'destructive',
        description: expect.stringContaining('prefers to be contacted by phone'),
      })),
    )
  })
})
