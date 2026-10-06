// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { EInvoiceRetryQueue, type RetryQueueDocument } from './EInvoiceRetryQueue'

const { apiGet, apiPost, toast } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  toast: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

vi.mock('@/hooks/use-toast', () => ({
  useToast: () => ({ toast }),
}))

const queue: RetryQueueDocument[] = [
  {
    id: 11,
    document_id: 101,
    document_number: 'INV-0101',
    document_type: 'invoice',
    compliance_status: 'failed',
    country_code: 'MY',
    retry_count: 2,
    errors: [
      {
        country_code: 'MY',
        code: 'BUYER_TIN_INVALID',
        issue_code: null,
        message: "Buyer TIN not accepted by LHDN — check the TIN on customer 'ABC Trading'.",
        raw_message: 'Buyer TIN C0000000000 is not valid',
        fix: { type: 'customer', id: 5, fields: ['tax_number', 'buyer_type'] },
      },
    ],
  },
  {
    id: 12,
    document_id: 102,
    document_number: 'INV-0102',
    document_type: 'invoice',
    compliance_status: 'rejected',
    country_code: 'MY',
    retry_count: 0,
    errors: [
      {
        country_code: 'MY',
        code: 'SOMETHING_NEW',
        issue_code: null,
        message: 'Document rejected for an unlisted reason',
        raw_message: 'Document rejected for an unlisted reason',
        fix: null,
      },
    ],
  },
]

function renderQueue(canRetry = true) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <EInvoiceRetryQueue
          countryCode="MY"
          canRetry={canRetry}
          documentPath={(doc) => (doc.document_id ? `/invoices/${doc.document_id}` : null)}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('EInvoiceRetryQueue (#673)', () => {
  beforeEach(() => {
    apiGet.mockResolvedValue({ data: { data: queue } })
    apiPost.mockResolvedValue({
      data: { data: { queued: 2, skipped: 0, results: [{ id: 11, outcome: 'queued' }, { id: 12, outcome: 'queued' }] } },
    })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('loads the failed / invalid queue with errors', async () => {
    renderQueue()

    await screen.findByText('INV-0101')
    expect(apiGet).toHaveBeenCalledWith('/accounting/compliance/documents', {
      params: expect.objectContaining({ country_code: 'MY', status: 'failed,rejected', with_errors: 1 }),
    })
    expect(screen.getByText('Failed')).toBeTruthy()
    expect(screen.getByText('Invalid')).toBeTruthy()
  })

  it('shows the plain-language error with a link to the customer field, plus the raw message', async () => {
    renderQueue()

    expect(await screen.findByText("Buyer TIN not accepted by LHDN — check the TIN on customer 'ABC Trading'.")).toBeTruthy()
    const link = screen.getByRole('link', { name: 'Open customer — TIN, buyer type' })
    expect(link.getAttribute('href')).toBe('/customers/5')
    expect(screen.getByText(/BUYER_TIN_INVALID — Buyer TIN C0000000000 is not valid/)).toBeTruthy()
  })

  it('shows an unknown error as received, without a fix link', async () => {
    renderQueue()

    expect(await screen.findByText('Document rejected for an unlisted reason')).toBeTruthy()
    expect(screen.queryByText(/As received: SOMETHING_NEW/)).toBeNull()
  })

  it('shows the retry count per document', async () => {
    renderQueue()

    await screen.findByText('INV-0101')
    const rows = screen.getAllByRole('row')
    expect(rows[1].textContent).toContain('2')
    expect(rows[2].textContent).toContain('0')
  })

  it('retries the selected documents in one bulk request', async () => {
    renderQueue()

    await screen.findByText('INV-0101')
    const retryButton = screen.getByRole('button', { name: /Retry selected/ })
    expect((retryButton as HTMLButtonElement).disabled).toBe(true)

    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all failed or invalid e-invoices' }))
    fireEvent.click(screen.getByRole('button', { name: 'Retry selected (2)' }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/accounting/compliance/documents/bulk-retry', { document_ids: [11, 12] })
    })
    await waitFor(() => {
      expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: '2 e-invoice(s) queued for resubmission' }))
    })
  })

  it('retries only the rows that were ticked', async () => {
    renderQueue()

    await screen.findByText('INV-0101')
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select INV-0102' }))
    fireEvent.click(screen.getByRole('button', { name: 'Retry selected (1)' }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/accounting/compliance/documents/bulk-retry', { document_ids: [12] })
    })
  })

  it('hides selection and retry without the submit right', async () => {
    renderQueue(false)

    await screen.findByText('INV-0101')
    expect(screen.queryByRole('button', { name: /Retry selected/ })).toBeNull()
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0)
  })
})
