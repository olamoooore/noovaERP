// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CreditNoteRefunds } from './CreditNoteRefunds'
import type { CreditNoteRefund } from '@/types/creditNote'

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

const liveRefund: CreditNoteRefund = {
  id: 3,
  credit_note_id: 7,
  payment_id: 11,
  amount: '40.00',
  currency_code: 'MYR',
  journal_entry_id: 21,
  voided_at: null,
  reversal_journal_entry_id: null,
  created_at: '2026-10-07T00:00:00Z',
  payment: { id: 11, payment_number: 'PAY/2026/10/011', payment_date: '2026-10-07', method: 'bank_transfer' },
}

function renderPanel(props: Partial<React.ComponentProps<typeof CreditNoteRefunds>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CreditNoteRefunds
          creditNoteId={7}
          creditNoteNumber="CN-2026-0007"
          currencyCode="MYR"
          remainingCredit={60}
          refunds={[]}
          canPost
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
  apiGet.mockResolvedValue({ data: { data: [] } })
  apiPost.mockResolvedValue({ data: {} })
})

afterEach(() => {
  cleanup()
})

describe('CreditNoteRefunds (#528)', () => {
  it('refunds the remaining credit, defaulting to the full amount', async () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Refund' }))
    expect((screen.getByLabelText('Amount') as HTMLInputElement).value).toBe('60.00')

    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '40' } })
    fireEvent.click(screen.getByRole('button', { name: 'Confirm refund' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith(
      '/accounting/customers/credit-notes/7/refunds',
      expect.objectContaining({ amount: 40, method: 'bank_transfer', bank_account_id: null }),
    )
  })

  it('blocks an amount above the remaining credit', () => {
    renderPanel()

    fireEvent.click(screen.getByRole('button', { name: 'Refund' }))
    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '60.01' } })

    expect((screen.getByRole('button', { name: 'Confirm refund' }) as HTMLButtonElement).disabled).toBe(true)
    expect(apiPost).not.toHaveBeenCalled()
  })

  it('lists refunds and voids a live one', async () => {
    renderPanel({ remainingCredit: 20, refunds: [liveRefund] })

    expect(screen.getByText(/PAY\/2026\/10\/011/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Void refund' }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/accounting/customers/credit-notes/7/refunds/3/void'),
    )
  })

  it('offers no refund action without accounting.post or remaining credit', () => {
    const { container } = renderPanel({ canPost: false })
    expect(container.innerHTML).toBe('')
    cleanup()

    renderPanel({ remainingCredit: 0, refunds: [{ ...liveRefund, voided_at: '2026-10-07T01:00:00Z' }] })
    expect(screen.queryByRole('button', { name: 'Refund' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Void refund' })).toBeNull()
    expect(screen.getByText('Voided')).toBeTruthy()
  })
})
