// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesOrderBillingRecurrenceCard } from './SalesOrderBillingRecurrenceCard'

// Issue #330: a Sales Order recurrence can generate invoices, optionally
// auto-posted and auto-sent; a failed run is shown on the schedule.

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

vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }))

const renderCard = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SalesOrderBillingRecurrenceCard orderId={5} billByDelivered={false} hasInvoices canWrite canRecur />
    </QueryClientProvider>,
  )

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('SalesOrderBillingRecurrenceCard — recurring invoices (#330)', () => {
  it('creates an invoice schedule with auto-post and auto-send', async () => {
    apiGet.mockResolvedValue({ data: { data: [] } })
    apiPost.mockResolvedValue({ status: 201, data: { message: 'Recurrence created successfully', data: { id: 9 } } })
    renderCard()

    fireEvent.click(await screen.findByRole('button', { name: /make recurring/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/generates/i), { target: { value: 'invoice' } })
    fireEvent.click(within(dialog).getByLabelText(/post each invoice automatically/i))
    fireEvent.click(within(dialog).getByLabelText(/email each invoice/i))
    fireEvent.change(within(dialog).getByLabelText(/start date/i), { target: { value: '2026-11-01' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create schedule/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/5/recurrence', {
        interval: 'monthly',
        start_date: '2026-11-01',
        end_date: null,
        max_cycles: null,
        document_type: 'invoice',
        auto_post: true,
        auto_send: true,
      }),
    )
  })

  it('shows an invoice schedule and its last failure', async () => {
    apiGet.mockResolvedValue({
      data: {
        data: [
          {
            id: 9,
            sales_order_id: 5,
            interval: 'monthly',
            status: 'active',
            next_run_date: '2026-11-01',
            cycles_generated: 1,
            document_type: 'invoice',
            auto_post: true,
            auto_send: false,
            last_error: 'Credit check failed: limit exceeded',
          },
        ],
      },
    })
    renderCard()

    const row = await screen.findByTestId('sales-order-recurrence-9')
    expect(within(row).getByText(/invoices · auto-post/i)).toBeTruthy()
    expect(within(row).getByText(/next invoice/i)).toBeTruthy()
    expect(within(row).getByTestId('sales-order-recurrence-error-9').textContent).toContain('Credit check failed')
  })
})
