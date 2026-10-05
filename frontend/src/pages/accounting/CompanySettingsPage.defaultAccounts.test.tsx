// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CompanySettingsPage from './CompanySettingsPage'

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

vi.mock('@/pages/Settings/CountryPluginSettings', () => ({
  CountryPluginSettings: () => <div>STUB-country-plugin</div>,
}))
vi.mock('@/pages/accounting/ChartOfAccountsPage', () => ({
  default: () => <div>STUB-chart-of-accounts</div>,
}))
vi.mock('@/pages/accounting/MultiCurrencyPage', () => ({
  default: () => <div>STUB-multi-currency</div>,
}))
vi.mock('@/pages/accounting/AccountingEntityManagementPage', () => ({
  default: () => <div>STUB-entities</div>,
}))
vi.mock('@/pages/accounting/AccountingOnboardingPage', () => ({
  default: () => <div>STUB-onboarding</div>,
}))

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, put: apiPut, post: vi.fn(async () => ({ data: {} })) },
}))
const { coaGetAccounts } = vi.hoisted(() => ({ coaGetAccounts: vi.fn() }))
vi.mock('@/lib/chartOfAccountsApi', () => ({
  default: { getAccounts: coaGetAccounts },
}))

function renderPage(initial = '/accounting/configuration/company?tab=accounts') {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initial]}>
        <CompanySettingsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const ENTITY_PAYLOAD = {
  data: {
    success: true,
    data: {
      accounts_receivable: 33,
      accounts_payable: null,
      bank_account: null,
      sales_revenue: 22,
      shipping_revenue: null,
      sales_tax: null,
      purchase_expense: null,
      purchase_tax: null,
      inventory: null,
      cost_of_goods_sold: null,
      retained_earnings: null,
      bank_charges: null,
    },
    entity_id: 7,
    scope: { accounts_receivable: 'entity', sales_revenue: 'default' },
    unmapped: ['bank_charges'],
    health: {
      unmapped: [],
      wrong_type: [],
      inactive_account: [],
      summary: { expected_roles: 1, unmapped: 0, wrong_type: 0, inactive_account: 0 },
    },
  },
}

beforeEach(() => {
  localStorage.clear()
  apiGet.mockReset()
  apiPut.mockReset()
  coaGetAccounts.mockReset()

  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/settings') {
      return { data: { data: { company_name: 'ACME Pte Ltd' } } }
    }
    if (url === '/accounting/settings/fiscal-year') {
      return { data: { data: { fiscal_year_start: '2026-01-01', fiscal_year_end: '2026-12-31' } } }
    }
    if (url === '/accounting/settings/currencies') {
      return { data: { data: { base_currency: 'SGD' }, available_currencies: [] } }
    }
    if (url === '/accounting/legal-entities') {
      return {
        data: [
          { id: 7, name: 'Entity A', status: 'active' },
          { id: 8, name: 'Entity B', status: 'active' },
        ],
      }
    }
    if (url === '/accounting/settings/accounts') {
      return ENTITY_PAYLOAD
    }
    throw new Error(`unexpected GET ${url}`)
  })
  apiPut.mockImplementation(async () => ({ data: { success: true } }))
  coaGetAccounts.mockImplementation(async () => [
    { id: 11, code: '1100', name: 'Receivable', type: 'asset' },
    { id: 22, code: '4100', name: 'Revenue', type: 'revenue' },
    { id: 33, code: '1199', name: 'Entity A Receivable', type: 'asset' },
  ])
})

afterEach(cleanup)

describe('CompanySettingsPage Default Accounts per entity (issue #885)', () => {
  it('shows the entity selector and the used-by hint per role', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText(/apply only to Entity A/i)).toBeTruthy())

    expect(screen.getByText(/customer invoices and receipts/i)).toBeTruthy()
    expect(screen.getByText(/vendor bills and payments/i)).toBeTruthy()
  })

  it('warns about roles left unmapped for the selected entity', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText(/has no account for/i)).toBeTruthy())
    expect(screen.getByText(/Bank Charges/)).toBeTruthy()
  })

  it('marks entity-scoped values and saves the selected entity with the payload', async () => {
    renderPage()

    await waitFor(() => expect(screen.getByText(/apply only to Entity A/i)).toBeTruthy())
    await waitFor(() => expect(screen.getByText(/this entity only/i)).toBeTruthy())

    fireEvent.submit(document.getElementById('default-accounts-form')!)

    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    const [url, body] = apiPut.mock.calls[0]
    expect(String(url)).toBe('/accounting/settings/accounts')
    expect(body.entity_id).toBe(7)
    expect(body.accounts_receivable).toBe(33)
  })
})
