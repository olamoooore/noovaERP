// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CreditNoteApplications } from './CreditNoteApplications'
import type { CreditNoteApplication } from '@/types/creditNote'

// #528: "Apply to invoices" on the credit note detail, through the existing
// #495 POST /accounting/customers/credit-notes/{id}/applications endpoint.

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

const openInvoices = [
  // The note's own invoice: already holds the credit, never offered.
  { id: 1, invoice_number: 'INV-SRC', balance: 50, currency_code: 'MYR', lifecycle_status: 'posted', legal_entity_id: 5 },
  { id: 2, invoice_number: 'INV-OPEN', balance: 300, currency_code: 'MYR', lifecycle_status: 'posted', legal_entity_id: 5 },
  { id: 3, invoice_number: 'INV-SMALL', balance: 30, currency_code: 'MYR', lifecycle_status: 'credited', legal_entity_id: 5 },
  // Refused by the backend → not offered.
  { id: 4, invoice_number: 'INV-USD', balance: 100, currency_code: 'USD', lifecycle_status: 'posted', legal_entity_id: 5 },
  { id: 5, invoice_number: 'INV-OTHER-ENTITY', balance: 100, currency_code: 'MYR', lifecycle_status: 'posted', legal_entity_id: 9 },
  { id: 6, invoice_number: 'INV-DRAFT', balance: 100, currency_code: 'MYR', lifecycle_status: 'draft', legal_entity_id: 5 },
]

const application: CreditNoteApplication = {
  id: 41,
  credit_note_id: 7,
  invoice_id: 2,
  applied_amount: '15.00',
  currency_code: 'MYR',
  reversed_at: null,
  created_at: '2026-10-07T00:00:00Z',
  invoice: { id: 2, invoice_number: 'INV-OPEN' },
}

function renderPanel(props: Partial<React.ComponentProps<typeof CreditNoteApplications>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CreditNoteApplications
          creditNoteId={7}
          creditNoteNumber="CN-2026-0007"
          customerId={12}
          sourceInvoiceId={1}
          currencyCode="MYR"
          legalEntityId={5}
          remainingCredit={60}
          applications={[]}
          canApply
          isPosted
          {...props}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockResolvedValue({ data: { data: openInvoices } })
  apiPost.mockResolvedValue({ data: { data: [] } })
})

afterEach(() => {
  cleanup()
})

describe('CreditNoteApplications (#528)', () => {
  it('lists only the customer\'s other open invoices of the same currency and entity', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Apply to invoices' }))

    expect(await screen.findByText('INV-OPEN')).toBeTruthy()
    expect(screen.getByText('INV-SMALL')).toBeTruthy()
    expect(apiGet).toHaveBeenCalledWith('/payments/eligible-invoices', { params: { customer_id: 12 } })
    for (const hidden of ['INV-SRC', 'INV-USD', 'INV-OTHER-ENTITY', 'INV-DRAFT']) {
      expect(screen.queryByText(hidden)).toBeNull()
    }
  })

  it('applies a partial amount to the picked invoice through the existing applications endpoint', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Apply to invoices' }))
    fireEvent.change(await screen.findByLabelText('Credit to apply to INV-OPEN'), { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'Apply credit' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    expect(apiPost).toHaveBeenCalledWith('/accounting/customers/credit-notes/7/applications', {
      applications: [{ invoice_id: 2, amount: 25 }],
    })
  })

  it('blocks a total above the remaining credit', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Apply to invoices' }))
    fireEvent.change(await screen.findByLabelText('Credit to apply to INV-OPEN'), { target: { value: '60.01' } })

    expect(screen.getByText('Exceeds the remaining credit')).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Apply credit' }) as HTMLButtonElement).disabled).toBe(true)

    // Split across two invoices, still above the remaining 60.
    fireEvent.change(screen.getByLabelText('Credit to apply to INV-OPEN'), { target: { value: '40' } })
    fireEvent.change(screen.getByLabelText('Credit to apply to INV-SMALL'), { target: { value: '25' } })
    expect((screen.getByRole('button', { name: 'Apply credit' }) as HTMLButtonElement).disabled).toBe(true)
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('blocks an amount above the invoice balance', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Apply to invoices' }))
    fireEvent.change(await screen.findByLabelText('Credit to apply to INV-SMALL'), { target: { value: '31' } })

    expect(screen.getByText('Exceeds the invoice balance')).toBeTruthy()
    expect((screen.getByRole('button', { name: 'Apply credit' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('Max fills the invoice with the credit still unallocated', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Apply to invoices' }))
    fireEvent.change(await screen.findByLabelText('Credit to apply to INV-SMALL'), { target: { value: '30' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Max' })[0])

    expect((screen.getByLabelText('Credit to apply to INV-OPEN') as HTMLInputElement).value).toBe('30.00')
  })

  it('lists existing applications and hides the action without remaining credit or permission', () => {
    renderPanel({ remainingCredit: 0, applications: [application] })

    expect(screen.getByRole('link', { name: 'INV-OPEN' }).getAttribute('href')).toBe('/accounting/customers/bills/2')
    expect(screen.queryByRole('button', { name: 'Apply to invoices' })).toBeNull()

    cleanup()
    renderPanel({ canApply: false, applications: [application] })
    expect(screen.queryByRole('button', { name: 'Apply to invoices' })).toBeNull()
  })
})
