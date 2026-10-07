// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesOrderDownPaymentAction } from './SalesOrderDownPaymentAction'

// Issue #331: a down-payment invoice (percentage or fixed amount) from a
// confirmed sales order.

const { apiPost, navigate } = vi.hoisted(() => ({ apiPost: vi.fn(), navigate: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(async () => ({ data: {} })),
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return { ...actual, useNavigate: () => navigate }
})

vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }))

const renderAction = () =>
  render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <SalesOrderDownPaymentAction orderId={7} orderNumber="SO-0007" orderTotal={1090} currency="SGD" />
      </QueryClientProvider>
    </MemoryRouter>,
  )

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('SalesOrderDownPaymentAction (#331)', () => {
  it('creates a percentage down payment and opens the invoice', async () => {
    apiPost.mockResolvedValue({ status: 201, data: { invoice: { id: 42, invoice_number: 'INV-42' } } })
    renderAction()

    fireEvent.click(screen.getByRole('button', { name: /down payment/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/percentage/i), { target: { value: '30' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create down-payment invoice/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/7/down-payment-invoice', { method: 'percentage', percentage: 30 }),
    )
    await waitFor(() => expect(navigate).toHaveBeenCalledWith('/invoices/42'))
  })

  it('sends a fixed amount when that method is chosen', async () => {
    apiPost.mockResolvedValue({ status: 201, data: { invoice: { id: 43 } } })
    renderAction()

    fireEvent.click(screen.getByRole('button', { name: /down payment/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/down payment as/i), { target: { value: 'fixed' } })
    fireEvent.change(within(dialog).getByLabelText(/amount/i), { target: { value: '218' } })
    fireEvent.click(within(dialog).getByRole('button', { name: /create down-payment invoice/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/sales/orders/7/down-payment-invoice', { method: 'fixed', amount: 218 }),
    )
  })

  it('does not allow a percentage above 100', async () => {
    renderAction()

    fireEvent.click(screen.getByRole('button', { name: /down payment/i }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText(/percentage/i), { target: { value: '120' } })

    expect(within(dialog).getByRole('button', { name: /create down-payment invoice/i }).hasAttribute('disabled')).toBe(true)
  })
})
