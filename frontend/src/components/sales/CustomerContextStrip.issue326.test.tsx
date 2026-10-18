// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { CustomerContextStrip } from './CustomerContextStrip'
import { creditWarning, type CreditCheckVerdict } from '@/lib/creditCheck'

// Issue #326: the customer context strip shows the credit verdict invoice
// create/post enforce, with the tenant policy (block vs warn).

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const verdict = (overrides: Partial<CreditCheckVerdict> = {}): CreditCheckVerdict => ({
  approved: false,
  reason: 'Customer is on hold: Bounced cheque',
  credit_limit: 0,
  current_balance: 0,
  available_credit: 0,
  document_total: 0,
  policy: 'block',
  blocked: true,
  ...overrides,
})

const context = (credit_check?: CreditCheckVerdict) => ({
  customer_id: 7,
  currency_code: 'MYR',
  outstanding: 0,
  current: 0,
  overdue: 0,
  overdue_count: 0,
  oldest_overdue_days: 0,
  credit_limit: null,
  credit_utilization_pct: null,
  credit_available: null,
  last_invoice_date: null,
  credit_check,
})

afterEach(() => {
  cleanup()
  apiGet.mockReset()
})

describe('CustomerContextStrip credit verdict (#326)', () => {
  it('shows a blocking chip with the reason when the check fails under the block policy', async () => {
    apiGet.mockResolvedValue({ data: context(verdict()) })
    render(<CustomerContextStrip customerId={7} />)

    const chip = await screen.findByTestId('credit-check-chip')
    expect(chip.textContent).toContain('posting blocked')
    expect(chip.getAttribute('title')).toBe('Customer is on hold: Bounced cheque')
  })

  it('says warning only under the warn policy', async () => {
    apiGet.mockResolvedValue({ data: context(verdict({ policy: 'warn', blocked: false })) })
    render(<CustomerContextStrip customerId={7} />)

    expect((await screen.findByTestId('credit-check-chip')).textContent).toContain('warning only')
  })

  it('shows no credit chip when the check passes', async () => {
    apiGet.mockResolvedValue({ data: context(verdict({ approved: true, blocked: false, reason: 'Within credit limit' })) })
    render(<CustomerContextStrip customerId={7} />)

    await screen.findByText(/No invoices yet/)
    expect(screen.queryByTestId('credit-check-chip')).toBeNull()
  })
})

describe('creditWarning (#326)', () => {
  it('returns the warning for a failed check and nothing otherwise', () => {
    expect(creditWarning({ credit_check: verdict({ policy: 'warn', blocked: false }) })).toBe(
      'Credit warning: Customer is on hold: Bounced cheque'
    )
    expect(creditWarning({ credit_check: verdict({ approved: true }) })).toBeUndefined()
    expect(creditWarning({})).toBeUndefined()
    expect(creditWarning(undefined)).toBeUndefined()
  })
})
