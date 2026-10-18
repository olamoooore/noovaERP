// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { QuotationSettings, SalesOrderSettings, PaymentMethodSettings } from './SalesSettingsSections'
import { searchSalesSettings } from './salesSettingsTools'

// #545 (SET-UX1): Quotations, Sales Orders and Payment Methods sections.
// Each saves only its own fields through the endpoint the live code reads.


// jsdom polyfills Radix needs at render time
if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, put: apiPut },
}))

function settingsPayload() {
  return {
    data: {
      data: {
        quotation: { reviewer_user_ids: [3], reviewer_roles: [], default_validity_days: 30, default_terms: '' },
        sales_order: { reviewer_user_ids: [], reviewer_roles: ['manager'], auto_lock_on_confirm: false, default_invoicing_policy: 'ordered_qty' },
      },
    },
  }
}

const methods = [
  { id: 1, code: 'cash', label: 'Cash', is_active: true, requires_reference: false, default_bank_account_id: null },
  { id: 2, code: 'card', label: 'Card', is_active: true, requires_reference: false, default_bank_account_id: null },
]
const bankAccounts = [{ id: 9, account_name: 'Operating', bank_name: 'Maybank' }]

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/payment-methods') return { data: { data: methods } }
    if (url === '/accounting/bank-accounts') return { data: { data: bankAccounts } }
    return settingsPayload()
  })
})

afterEach(cleanup)

function renderWith(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>)
}

describe('QuotationSettings (#545)', () => {
  it('saves validity days and terms in the quotation group only', async () => {
    renderWith(<QuotationSettings />)

    const days = (await screen.findByLabelText('Default validity days')) as HTMLInputElement
    expect(days.value).toBe('30')
    fireEvent.change(days, { target: { value: '14' } })
    fireEvent.change(screen.getByLabelText('Default quotation terms'), { target: { value: 'Stock on hand.' } })

    fireEvent.click(screen.getByRole('button', { name: /save quotation settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        quotation: { default_validity_days: 14, default_terms: 'Stock on hand.' },
      })
    })
    // The reviewer lists sharing the group are never sent from here.
    expect(JSON.stringify(apiPut.mock.calls[0][1])).not.toContain('reviewer')
  })
})

describe('SalesOrderSettings (#545)', () => {
  it('saves the invoicing policy and the lock toggle', async () => {
    renderWith(<SalesOrderSettings />)

    fireEvent.click(await screen.findByText('Ordered quantities'))
    fireEvent.click(await screen.findByText('Delivered quantities'))
    fireEvent.click(screen.getByLabelText('Lock confirmed orders'))

    fireEvent.click(screen.getByRole('button', { name: /save sales order settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        sales_order: { default_invoicing_policy: 'delivered_qty', auto_lock_on_confirm: true },
      })
    })
  })
})

describe('PaymentMethodSettings (#545)', () => {
  it('lists every method (inactive included) and saves each edited one', async () => {
    renderWith(<PaymentMethodSettings />)

    await screen.findByLabelText('card active')
    expect(apiGet).toHaveBeenCalledWith('/accounting/payment-methods', { params: { include_inactive: 1 } })

    fireEvent.click(screen.getByLabelText('card active'))
    fireEvent.click(screen.getByLabelText('cash requires reference'))
    fireEvent.click(screen.getByLabelText('cash deposit to'))
    fireEvent.click(await screen.findByText('Operating — Maybank'))

    fireEvent.click(screen.getByRole('button', { name: /save payment methods/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/accounting/payment-methods/2', { is_active: false })
      expect(apiPut).toHaveBeenCalledWith('/accounting/payment-methods/1', {
        requires_reference: true,
        default_bank_account_id: 9,
      })
    })
  })

  it('keeps the edit when the server refuses the save', async () => {
    apiPut.mockRejectedValueOnce({
      response: { data: { errors: { is_active: ['At least one payment method must stay active.'] } } },
    })
    renderWith(<PaymentMethodSettings />)

    fireEvent.click(await screen.findByLabelText('cash active'))
    fireEvent.click(screen.getByRole('button', { name: /save payment methods/i }))

    await waitFor(() => expect(apiPut).toHaveBeenCalledTimes(1))
    expect(screen.getByText('Unsaved changes')).toBeTruthy()
  })
})

describe('settings search (#545)', () => {
  it('finds the new document-type settings', () => {
    expect(searchSalesSettings('quotation validity').map((r) => `${r.sectionTitle} › ${r.title}`)).toEqual([
      'Quotations › Default validity',
    ])
    expect(searchSalesSettings('lock').map((r) => r.section)).toContain('sales-orders')
    expect(searchSalesSettings('delivered').map((r) => r.title)).toContain('Default invoicing policy')
    expect(searchSalesSettings('duitnow').map((r) => r.section)).toEqual(['payment-methods'])
  })
})
