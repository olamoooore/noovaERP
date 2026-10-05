// @vitest-environment jsdom
/**
 * #866 (RPT-L10N UX4): every financial report carries a sticky scope header
 * with the legal entity and the reporting currency, and every amount column
 * names its currency. #855 made the API refuse to sum different base
 * currencies without an entity; this suite drives the real page and asserts
 * the header selects the entity, the request is scoped, the column names the
 * currency, and exports carry the same header.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import api from '@/lib/api'
import reportApi from '@/lib/reportApi'
import BalanceSheetPage from './BalanceSheetPage'

// jsdom polyfills Radix needs at render time (same as the other smoke suites).
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

vi.mock('@/lib/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

vi.mock('@/lib/reportApi', () => ({
  default: {
    getBalanceSheet: vi.fn(),
  },
}))

const exportProps: Array<Record<string, unknown>> = []
vi.mock('@/components/reports/ReportExportToolbar', () => ({
  ReportExportToolbar: (props: Record<string, unknown>) => {
    exportProps.push(props)
    return <div data-testid="report-export-toolbar" />
  },
}))

const mockedGet = vi.mocked(api.get)
const mockedBalanceSheet = vi.mocked(reportApi.getBalanceSheet)

const MY_ENTITY = {
  id: 1, name: 'MY Entity', code: 'MY-1', group_id: 1, base_currency: 'MYR', status: 'active',
}
const SG_ENTITY = {
  id: 2, name: 'SG Entity', code: 'SG-1', group_id: 1, base_currency: 'SGD', status: 'active',
}

const BALANCE_SHEET = {
  assets: { current: [], non_current: [], total: 1250 },
  liabilities: { current: [], non_current: [], total: 0 },
  equity: { groups: [], accounts: [], total: 1250 },
  total_assets: 1250,
  total_liabilities: 0,
  total_equity: 1250,
  total_liabilities_and_equity: 1250,
  balance_check: 0,
  working_capital: 1250,
}

function stubApi(entities: Array<Record<string, unknown>>) {
  mockedGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/legal-entities') {
      return { data: entities } as never
    }
    if (url === '/accounting/settings/fiscal-year') {
      return { data: { data: { fiscal_year_start_month: 1, fiscal_year_start_day: 1 } } } as never
    }
    throw new Error(`unexpected GET ${url}`)
  })
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <BalanceSheetPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function selectEntity(label: string) {
  const trigger = await screen.findByRole('combobox', { name: /legal entity/i })
  fireEvent.click(trigger)
  fireEvent.click(await screen.findByText(label))
}

beforeEach(() => {
  localStorage.clear()
  exportProps.length = 0
  mockedGet.mockReset()
  mockedBalanceSheet.mockReset()
  mockedBalanceSheet.mockResolvedValue(BALANCE_SHEET as never)
})

afterEach(cleanup)

describe('BalanceSheetPage report scope header (#866)', () => {
  it('requires an entity when the tenant mixes base currencies, then scopes and labels the report', async () => {
    stubApi([MY_ENTITY, SG_ENTITY])
    const { container } = renderPage()

    await screen.findByTestId('report-scope-header')
    await screen.findByText(/MYR, SGD/)
    expect(screen.getByText(/select a legal entity/i)).toBeTruthy()

    // Amounts in different base currencies are never summed: no report call
    // is made until the user picks an entity.
    await waitFor(() => expect(screen.getByTestId('report-scope-currency').textContent).toBe('Not set'))
    expect(mockedBalanceSheet).not.toHaveBeenCalled()

    await selectEntity('SG Entity · SGD')

    await waitFor(() =>
      expect(mockedBalanceSheet).toHaveBeenCalledWith(
        expect.objectContaining({ legal_entity_id: 2 }),
      ),
    )
    await waitFor(() => expect(screen.getByText('Amount (SGD)')).toBeTruthy())
    await waitFor(() => expect(container.textContent).toContain('S$1,250.00'))
  })

  it('runs unfiltered and labels amounts when every entity shares one base currency', async () => {
    stubApi([MY_ENTITY, { ...SG_ENTITY, base_currency: 'MYR' }])
    const { container } = renderPage()

    await waitFor(() => expect(mockedBalanceSheet).toHaveBeenCalled())

    const filters = mockedBalanceSheet.mock.calls[0][0] as Record<string, unknown>
    expect(filters.legal_entity_id).toBeUndefined()
    await waitFor(() => expect(screen.getByText('Amount (MYR)')).toBeTruthy())
    await waitFor(() =>
      expect((container.textContent ?? '').replace(/\u00a0/g, ' ')).toContain('MYR 1,250.00'),
    )
  })

  it('carries the selected entity and currency into exports', async () => {
    stubApi([MY_ENTITY, SG_ENTITY])
    renderPage()

    await screen.findByTestId('report-scope-header')
    await selectEntity('SG Entity · SGD')

    await waitFor(() => {
      const subtitle = exportProps.map((props) => String(props.subtitle ?? '')).join(' | ')
      expect(subtitle).toContain('SG Entity')
      expect(subtitle).toContain('SGD')
    })
  })
})
