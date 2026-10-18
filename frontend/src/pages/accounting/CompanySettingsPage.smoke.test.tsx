// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
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

// Embedded sub-pages have their own data needs — stub them so this test only
// covers the section navigation this page owns.
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

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="loc">{location.pathname + location.search}</div>
}

function renderPage(initial = '/accounting/configuration/company') {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initial]}>
        <CompanySettingsPage />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const nav = (label: string) => screen.getByRole('button', { name: new RegExp(label, 'i') })

beforeEach(() => {
  localStorage.clear()
  apiGet.mockReset()
  apiPut.mockReset()
  coaGetAccounts.mockReset()

  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/settings') {
      return {
        data: {
          data: {
            company_name: 'ACME Pte Ltd',
            tax_id: 'T1234567A',
            email: 'ops@acme.test',
            phone: '+65 6000 0000',
            address: '1 Test Road',
            website: 'https://acme.test',
            company_logo_url: '',
          },
        },
      }
    }
    if (url === '/accounting/settings/fiscal-year') {
      return {
        data: {
          data: { fiscal_year_start: '2026-01-01', fiscal_year_end: '2026-12-31', lock_date: null },
        },
      }
    }
    if (url === '/accounting/settings/currencies') {
      return {
        data: {
          data: {
            base_currency: 'SGD',
            date_format: 'DD/MM/YYYY',
            number_format: 'en-US',
            decimal_places: 2,
            thousand_separator: ',',
            decimal_separator: '.',
          },
          available_currencies: [{ code: 'SGD', name: 'Singapore Dollar' }],
        },
      }
    }
    if (url === '/accounting/settings/accounts') {
      return { data: { data: {} } }
    }
    throw new Error(`unexpected GET ${url}`)
  })
  apiPut.mockImplementation(async () => ({ data: {} }))
  coaGetAccounts.mockImplementation(async () => [
    { id: 1, code: '1000', name: 'Cash at Bank', type: 'asset' },
  ])
})

afterEach(cleanup)

describe('CompanySettingsPage (redesigned with the shared settings kit)', () => {
  it('renders the header and the section nav list', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())

    expect(screen.getByText('Company Settings')).toBeTruthy()
    for (const label of [
      'Company Info',
      'Fiscal Year',
      'Currency & Formats',
      'Default Accounts',
      'Country Plugin',
      'Chart of Accounts',
      'Multi-Currency',
      'Entities',
    ]) {
      expect(nav(label)).toBeTruthy()
    }
  })

  it('reaches every section from the nav, including the embedded pages', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())

    fireEvent.click(nav('Fiscal Year'))
    await waitFor(() => expect(screen.getByText('Fiscal Year Start Date')).toBeTruthy())

    fireEvent.click(nav('Currency & Formats'))
    await waitFor(() => expect(screen.getByText('Base Currency')).toBeTruthy())

    fireEvent.click(nav('Default Accounts'))
    await waitFor(() => expect(screen.getByText('Accounts Receivable')).toBeTruthy())

    fireEvent.click(nav('Country Plugin'))
    await waitFor(() => expect(screen.getByText('STUB-country-plugin')).toBeTruthy())

    fireEvent.click(nav('Chart of Accounts'))
    await waitFor(() => expect(screen.getByText('STUB-chart-of-accounts')).toBeTruthy())

    fireEvent.click(nav('Multi-Currency'))
    await waitFor(() => expect(screen.getByText('STUB-multi-currency')).toBeTruthy())

    fireEvent.click(nav('Entities'))
    await waitFor(() => expect(screen.getByText('STUB-entities')).toBeTruthy())

    fireEvent.click(nav('Company Info'))
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())
  })

  it('keeps the ?tab= deep link in sync and honours an incoming one', async () => {
    renderPage('/accounting/configuration/company?tab=multi-currency')
    await waitFor(() => expect(screen.getByText('STUB-multi-currency')).toBeTruthy())

    fireEvent.click(nav('Fiscal Year'))
    await waitFor(() => expect(screen.getByTestId('loc').textContent).toContain('tab=fiscal'))
  })

  it('still serves ?tab=onboarding (deep-link-only section)', async () => {
    renderPage('/accounting/configuration/company?tab=onboarding')
    await waitFor(() => expect(screen.getByText('STUB-onboarding')).toBeTruthy())
  })

  it('hides the Country Plugin for Acme tenants', async () => {
    localStorage.setItem('tenant_id', '00000000-0000-0000-0000-000000000002')
    renderPage('/accounting/configuration/company?tab=country')
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())

    expect(screen.queryByText('Country Plugin')).toBeNull()
    expect(screen.queryByText('STUB-country-plugin')).toBeNull()
  })

  it('shows the Country Plugin for non-Acme tenants', async () => {
    localStorage.setItem('tenant_id', '11111111-2222-3333-4444-555555555555')
    renderPage('/accounting/configuration/company?tab=country')
    await waitFor(() => expect(screen.getByText('STUB-country-plugin')).toBeTruthy())
    expect(nav('Country Plugin')).toBeTruthy()
  })

  it('submits the company form from the card-footer save button', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())

    const saveButton = screen.getByText('Save Settings').closest('button')!
    expect(saveButton.getAttribute('form')).toBe('company-info-form')
    expect(saveButton.getAttribute('type')).toBe('submit')

    fireEvent.submit(document.getElementById('company-info-form')!)
    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    expect(String(apiPut.mock.calls[0][0])).toBe('/accounting/settings')
  })

  it('minimises the nav to an icon rail and remembers it', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: /minimise settings menu/i }))

    expect(screen.getByRole('button', { name: /expand settings menu/i })).toBeTruthy()
    expect(localStorage.getItem('settings-nav-collapsed')).toBe('1')
    // sections stay reachable while minimised
    expect(nav('Currency & Formats')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /expand settings menu/i }))
    expect(localStorage.getItem('settings-nav-collapsed')).toBe('0')
  })

  it('opens already minimised when that preference was stored', async () => {
    localStorage.setItem('settings-nav-collapsed', '1')
    renderPage()
    await waitFor(() => expect(screen.getByText('Company Information')).toBeTruthy())
    expect(screen.getByRole('button', { name: /expand settings menu/i })).toBeTruthy()
  })
})
