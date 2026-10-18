// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import CustomerPortalPage from './CustomerPortalPage'

// #337: public customer portal lists the customer's documents with download + pay.

const { apiGet, apiPost, toast } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), toast: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet, post: apiPost } }))
vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast }) }))

function payload() {
  return {
    customer: { name: 'Portal Customer' },
    company: { name: 'Acme' },
    expires_at: '2026-11-07T00:00:00Z',
    invoices: [
      { id: 7, invoice_number: 'INV-7', invoice_date: '2026-10-01', due_date: '2026-10-31', status: 'sent', currency_code: 'USD', total: 500, paid_amount: 0, balance: 500, can_pay: true },
    ],
    quotations: [
      { id: 3, quotation_number: 'QT-3', quotation_date: '2026-10-01', valid_until: '2026-10-31', status: 'sent', currency_code: 'USD', total: 200, accepted_at: null, acceptance_token: 'acc-tok' },
    ],
  }
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/customer-portal/tok']}>
        <Routes>
          <Route path="/customer-portal/:token" element={<CustomerPortalPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  toast.mockReset()
})

afterEach(() => cleanup())

describe('CustomerPortalPage (#337)', () => {
  it('lists invoices and quotations and links open quotations to the acceptance page', async () => {
    apiGet.mockResolvedValueOnce({ data: payload() })
    renderPage()

    expect(await screen.findByText('INV-7')).toBeTruthy()
    expect(screen.getByText('QT-3')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith('/customer-portal/tok')
    const accept = screen.getByRole('link', { name: /review/i })
    expect(accept.getAttribute('href')).toBe('/quotation-accept/acc-tok')
  })

  it('pay requests the invoice payment link and follows only an http(s) url', async () => {
    apiGet.mockResolvedValueOnce({ data: payload() })
    apiPost.mockResolvedValueOnce({ data: { url: 'javascript:alert(1)' } })
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { value: { ...window.location, assign }, writable: true })

    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Pay' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/customer-portal/tok/invoices/7/pay'))
    await waitFor(() => expect(toast).toHaveBeenCalled())
    expect(assign).not.toHaveBeenCalled()
  })

  it('lists open sales orders with delivery/invoice status and downloads the order confirmation', async () => {
    apiGet.mockResolvedValueOnce({
      data: {
        ...payload(),
        orders: [
          {
            id: 11,
            sales_order_number: 'SO-11',
            order_date: '2026-10-02',
            commitment_date: null,
            status: 'confirmed',
            currency_code: 'USD',
            total: 300,
            customer_reference: 'PO-9',
            invoice_status: 'to_invoice',
            delivery_status: 'partially_delivered',
          },
        ],
      },
    })
    apiGet.mockResolvedValueOnce({ data: new Blob(['%PDF']) })
    const createObjectURL = vi.fn(() => 'blob:x')
    const revokeObjectURL = vi.fn()
    Object.assign(URL, { createObjectURL, revokeObjectURL })

    renderPage()

    const table = await screen.findByRole('table', { name: 'Open orders' })
    expect(within(table).getByText('SO-11')).toBeTruthy()
    expect(within(table).getByText('Your ref: PO-9')).toBeTruthy()
    expect(within(table).getByText('Confirmed')).toBeTruthy()
    expect(within(table).getByText('Partially delivered')).toBeTruthy()
    expect(within(table).getByText('Not invoiced')).toBeTruthy()

    fireEvent.click(within(table).getByRole('button', { name: 'Download' }))
    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/customer-portal/tok/orders/11/pdf', { responseType: 'blob' }),
    )
  })

  it('shows an empty orders state when the customer has no open orders', async () => {
    apiGet.mockResolvedValueOnce({ data: { ...payload(), orders: [] } })
    renderPage()

    expect(await screen.findByText('No open orders.')).toBeTruthy()
  })

  it('shows the expired state on 410', async () => {
    apiGet.mockRejectedValueOnce({ response: { status: 410 } })
    renderPage()

    expect(await screen.findByText('This link has expired')).toBeTruthy()
  })
})
