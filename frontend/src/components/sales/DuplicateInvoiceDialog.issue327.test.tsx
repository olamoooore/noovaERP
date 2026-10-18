// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { DuplicateInvoiceDialog } from './DuplicateInvoiceDialog'
import { duplicateWarning, likelyDuplicateFromError, type DuplicateCheckResult } from '@/lib/duplicateCheck'

// Issue #327: a likely duplicate (same reference / PO) needs confirmation;
// a possible one (same total within 7 days) only warns.

const likely: DuplicateCheckResult = {
  level: 'likely',
  confirmed: false,
  matches: [
    {
      id: 11,
      invoice_number: 'INV-0011',
      invoice_date: '2026-08-01',
      total: 999,
      currency_code: 'MYR',
      status: 'posted',
      level: 'likely',
      matched_on: ['po_number'],
    },
  ],
}

afterEach(cleanup)

describe('DuplicateInvoiceDialog (#327)', () => {
  it('lists the matching invoice and why it matched, and confirms on "Create anyway"', () => {
    const onConfirm = vi.fn()
    render(<DuplicateInvoiceDialog check={likely} onCancel={() => {}} onConfirm={onConfirm} />)

    const list = screen.getByTestId('duplicate-invoice-matches')
    expect(list.textContent).toContain('INV-0011')
    expect(list.textContent).toContain('same PO number')

    fireEvent.click(screen.getByRole('button', { name: 'Create anyway' }))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('renders nothing when there is no likely duplicate', () => {
    render(<DuplicateInvoiceDialog check={null} onCancel={() => {}} onConfirm={() => {}} />)
    expect(screen.queryByTestId('duplicate-invoice-matches')).toBeNull()
  })
})

describe('duplicate helpers (#327)', () => {
  it('reads the likely-duplicate verdict only from a DUPLICATE_INVOICE_LIKELY refusal', () => {
    expect(likelyDuplicateFromError({ response: { data: { code: 'DUPLICATE_INVOICE_LIKELY', duplicate_check: likely } } })).toEqual(likely)
    expect(likelyDuplicateFromError({ response: { data: { code: 'CREDIT_LIMIT_EXCEEDED' } } })).toBeNull()
    expect(likelyDuplicateFromError(undefined)).toBeNull()
  })

  it('warns for a possible duplicate only', () => {
    const possible: DuplicateCheckResult = {
      level: 'possible',
      confirmed: false,
      matches: [{ ...likely.matches[0], level: 'possible', matched_on: ['total'] }],
    }
    expect(duplicateWarning({ duplicate_check: possible })).toContain('INV-0011')
    expect(duplicateWarning({ duplicate_check: { level: 'none', matches: [], confirmed: false } })).toBeUndefined()
    expect(duplicateWarning({})).toBeUndefined()
  })
})
