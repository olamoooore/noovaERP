// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { EInvoiceReadinessPanel, type EInvoiceReadiness } from './EInvoiceReadinessPanel'

const { apiGet, apiPatch, apiPut, apiPost } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPatch: vi.fn(async () => ({ data: {} })),
  apiPut: vi.fn(async () => ({ data: {} })),
  apiPost: vi.fn(async () => ({ data: {} })),
}))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, patch: apiPatch, put: apiPut, post: apiPost, delete: vi.fn() },
}))

const notReady: EInvoiceReadiness = {
  applicable: true,
  ready: false,
  countries: ['MY'],
  summary: { blocking: 1, warnings: 2 },
  issues: [
    {
      country_code: 'MY',
      code: 'buyer_tin_missing',
      severity: 'blocking',
      message: "Customer 'Delta Retail' has no TIN — add it or mark as individual",
      fix: { type: 'customer', id: 5, fields: ['tax_number', 'buyer_type'] },
    },
    {
      country_code: 'MY',
      code: 'line_classification_missing',
      severity: 'warning',
      message: "Product 'Widget' has no LHDN classification code — the pack default '001' would be sent.",
      fix: { type: 'product', id: 42, fields: ['einvoice_classification_code'] },
    },
    {
      country_code: 'MY',
      code: 'buyer_state_missing',
      severity: 'warning',
      message: "Customer 'Delta Retail' has no state on its address — MyInvois would default the buyer state to 14 (Kuala Lumpur).",
      fix: { type: 'customer', id: 5, fields: ['state'], address_id: 77 },
    },
  ],
}

function renderPanel(enabled = true) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <EInvoiceReadinessPanel invoiceId={9} enabled={enabled} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('EInvoiceReadinessPanel (#671)', () => {
  beforeEach(() => {
    apiGet.mockReset()
    apiPatch.mockClear()
    apiPut.mockClear()
    apiPost.mockClear()
  })

  afterEach(() => cleanup())

  it('lists each gap from the readiness endpoint with its fix', async () => {
    apiGet.mockResolvedValue({ data: notReady })
    renderPanel()

    expect(await screen.findByText(/has no TIN/)).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith('/invoices/9/einvoice-readiness')
    expect(screen.getByText(/E-invoice readiness \(MyInvois\)/)).toBeTruthy()
    expect(screen.getByText(/1 blocking · 2 warnings/)).toBeTruthy()
    expect(screen.getByText(/pack default '001'/)).toBeTruthy()

    const productLink = screen.getByRole('link', { name: 'Open product' })
    expect(productLink.getAttribute('href')).toBe('/products/42/edit')
    expect(screen.getAllByRole('button', { name: 'Fix' })).toHaveLength(2)
  })

  it('fixes the customer TIN in place through the customer endpoint', async () => {
    apiGet.mockResolvedValue({ data: notReady })
    renderPanel()

    fireEvent.click((await screen.findAllByRole('button', { name: 'Fix' }))[0])
    fireEvent.change(await screen.findByLabelText('TIN / Tax Number'), { target: { value: 'C2584563200' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() =>
      expect(apiPatch).toHaveBeenCalledWith('/customers/5', { buyer_type: 'business', tax_number: 'C2584563200' }),
    )
    await waitFor(() => expect(apiGet).toHaveBeenCalledTimes(2))
  })

  it('fixes the buyer state on the address the gap points at', async () => {
    apiGet.mockResolvedValue({ data: notReady })
    renderPanel()

    fireEvent.click((await screen.findAllByRole('button', { name: 'Fix' }))[1])
    fireEvent.change(await screen.findByLabelText('State'), { target: { value: 'Selangor' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledWith('/customers/5/addresses/77', { state: 'Selangor' }))
    expect(apiPatch).not.toHaveBeenCalled()
  })

  it('renders nothing when no e-invoicing country applies', async () => {
    apiGet.mockResolvedValue({ data: { applicable: false, ready: true, countries: [], summary: { blocking: 0, warnings: 0 }, issues: [] } })
    const { container } = renderPanel()

    await waitFor(() => expect(apiGet).toHaveBeenCalled())
    expect(container.querySelector('[data-testid="einvoice-readiness"]')).toBeNull()
  })

  it('does not query for a non-draft invoice', () => {
    renderPanel(false)
    expect(apiGet).not.toHaveBeenCalled()
  })
})
