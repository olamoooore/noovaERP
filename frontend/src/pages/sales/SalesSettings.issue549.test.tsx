// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesSettingsPage } from './SalesSettingsPage'
import { InvoiceDefaultsSettings, DeliverySettings } from './SalesSettingsSections'
import { buildSalesSettingsExport, isChangedFromDefault, searchSalesSettings } from './salesSettingsTools'

// #549 (SET-UX5): search across settings, "changed from default" markers with
// a per-setting reset (defaults come from the server), and a JSON export.

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
vi.mock('@/pages/TemplateBuilder/TemplateListPage', () => ({
  default: () => <div>Template list</div>,
}))

const serverDefaults = {
  invoice: {
    allow_custom_number: true,
    field_profile: 'medical',
    default_requires_approval: false,
    approval_expiry_days: 7,
    auto_send_to_customer_on_post: true,
    allow_partial_payments: true,
    default_payment_terms_days: 30,
    default_notes: '',
    default_terms: 'Payment due within 30 days.',
  },
  delivery: { attach_pdf: true, cc_internal_email: '', document_type_on_send: 'final_invoice' },
}

function settings(overrides: { invoice?: Record<string, unknown>; delivery?: Record<string, unknown> } = {}) {
  return {
    cash_rounding: { enabled: false, method: 'nearest', precision: 0.05, account_id: null, methods: ['nearest'] },
    invoice: {
      ...serverDefaults.invoice,
      available_reviewers: [{ id: 7, name: 'Sam Seller', email: 'sam@example.test' }],
      approval_enforcement: { mode: 'per_document', policy_enabled: false, drafts_without_approval: 0 },
      reviewer_user_ids: [7],
      ...overrides.invoice,
    },
    portal: { acceptance_enabled: true, require_signature: true, acceptance_link_expiry_days: 30, payment_link_expiry_days: 14 },
    tax: { default_tax_rate: 0, prices_include_tax: false, default_tax_treatment: 'standard' },
    delivery: { ...serverDefaults.delivery, ...overrides.delivery },
    default_values: serverDefaults,
  }
}

function formatsPayload() {
  const f = (current: string) => ({ current, default: current, example: current, reset_period: 'yearly', next_sequence: 12 })
  return {
    invoice: f('INV-{YYYY}-{SEQ:4}'),
    quotation: f('QUO-{YYYY}-{SEQ:4}'),
    sales_order: f('SO-{YYYY}-{SEQ:4}'),
    delivery_order: f('DO-{YYYY}-{SEQ:4}'),
    credit_note: f('CN-{YYYY}-{SEQ:4}'),
    payment: f('PAY/{YYYY}/{MM}/{SEQ:3}'),
    date_format: 'Y-m-d',
  }
}

let current: ReturnType<typeof settings>

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  current = settings()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/settings/sales') return { data: { data: current } }
    if (url === '/settings/document-formats') return { data: formatsPayload() }
    return { data: { data: [] } }
  })
  apiPut.mockImplementation(async () => ({ data: {} }))
})

afterEach(cleanup)

function renderWith(node: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

describe('changed-from-default markers (#549)', () => {
  it('marks only settings whose saved value differs from the server default', async () => {
    current = settings({ invoice: { default_payment_terms_days: 45, default_terms: 'Net 7.' } })
    renderWith(<InvoiceDefaultsSettings />)

    expect(await screen.findByRole('button', { name: 'Reset Default payment terms to default' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Reset Default terms to default' })).toBeTruthy()
    expect(screen.getAllByTestId('changed-from-default')).toHaveLength(2)
    // Untouched settings carry no marker.
    expect(screen.queryByRole('button', { name: 'Reset Allow partial payments to default' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Reset Default notes to default' })).toBeNull()
  })

  it('shows no markers when the server reports no defaults', async () => {
    current = { ...settings({ invoice: { default_payment_terms_days: 45 } }), default_values: undefined as never }
    renderWith(<InvoiceDefaultsSettings />)

    await screen.findByText('Default payment terms')
    expect(screen.queryAllByTestId('changed-from-default')).toHaveLength(0)
  })

  it('reset saves the server default for that one setting through PUT /settings/sales', async () => {
    current = settings({ invoice: { default_payment_terms_days: 45 } })
    renderWith(<InvoiceDefaultsSettings />)

    fireEvent.click(await screen.findByRole('button', { name: 'Reset Default payment terms to default' }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { invoice: { default_payment_terms_days: 30 } })
    })
    expect(apiPut).toHaveBeenCalledTimes(1)
  })

  it('reset keeps other unsaved edits in the section', async () => {
    current = settings({ delivery: { cc_internal_email: 'books@example.test' } })
    renderWith(<DeliverySettings />)

    const cc = (await screen.findByDisplayValue('books@example.test')) as HTMLInputElement
    expect(cc).toBeTruthy()
    // An unrelated unsaved edit elsewhere in the section…
    fireEvent.click(screen.getByText('Final invoice'))
    fireEvent.click(await screen.findByText('Acceptance copy'))
    await screen.findByText('Unsaved changes')

    fireEvent.click(screen.getByRole('button', { name: 'Reset CC internal email to default' }))
    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { delivery: { cc_internal_email: '' } })
    })
    // …survives the reset.
    expect(screen.getByText('Unsaved changes')).toBeTruthy()
  })
})

describe('settings search (#549)', () => {
  it('filters settings across sections', () => {
    const titles = searchSalesSettings('expiry').map((r) => `${r.sectionTitle} › ${r.title}`)
    expect(titles).toEqual(
      expect.arrayContaining([
        'Approval Workflow › Approval expiry',
        'Payments & Portal › Acceptance link validity',
        'Payments & Portal › Payment link validity',
      ]),
    )
    expect(searchSalesSettings('rounding step').map((r) => r.title)).toEqual(['Rounding step'])
    expect(searchSalesSettings('   ')).toEqual([])
  })

  it('the page search box lists matches from other sections and opens the picked one', async () => {
    renderWith(<SalesSettingsPage />)
    await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')

    fireEvent.change(screen.getByLabelText('Search settings'), { target: { value: 'signature' } })
    const list = screen.getByRole('listbox', { name: 'Matching settings' })
    const option = within(list).getByText('Require digital signature')
    expect(within(list).getByText('Payments & Portal')).toBeTruthy()

    fireEvent.click(option)

    expect(await screen.findByText('Payments & Customer Portal')).toBeTruthy()
    await waitFor(() => {
      const row = screen.getByText('Require digital signature').closest('[data-highlighted]')
      expect(row?.getAttribute('data-highlighted')).toBe('true')
    })
    expect(screen.queryByRole('listbox', { name: 'Matching settings' })).toBeNull()
  })

  it('says so when nothing matches', async () => {
    renderWith(<SalesSettingsPage />)
    await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')
    fireEvent.change(screen.getByLabelText('Search settings'), { target: { value: 'zzz-nothing' } })
    expect(screen.getByText(/No settings match/)).toBeTruthy()
  })
})

describe('export settings (#549)', () => {
  it('exports saved settings and numbering formats without directory data, computed state or defaults', () => {
    const payload = buildSalesSettingsExport(
      settings() as unknown as Record<string, unknown>,
      formatsPayload(),
      new Date('2026-10-06T00:00:00Z'),
    )

    expect(payload.exported_at).toBe('2026-10-06T00:00:00.000Z')
    const sales = payload.sales_settings as Record<string, Record<string, unknown>>
    expect(sales.default_values).toBeUndefined()
    expect(sales.invoice.available_reviewers).toBeUndefined()
    expect(sales.invoice.approval_enforcement).toBeUndefined()
    expect(sales.cash_rounding.methods).toBeUndefined()
    expect(sales.invoice.reviewer_user_ids).toEqual([7])
    expect(sales.invoice.default_terms).toBe('Payment due within 30 days.')
    expect(payload.document_numbering).toMatchObject({
      invoice: { format: 'INV-{YYYY}-{SEQ:4}', reset_period: 'yearly' },
      date_format: 'Y-m-d',
    })
    expect(JSON.stringify(payload.document_numbering)).not.toContain('next_sequence')
  })

  it('the Export settings button downloads the JSON file', async () => {
    const createObjectURL = vi.fn(() => 'blob:export')
    const revokeObjectURL = vi.fn()
    Object.assign(URL, { createObjectURL, revokeObjectURL })
    const clicks: string[] = []
    const origClick = HTMLAnchorElement.prototype.click
    HTMLAnchorElement.prototype.click = function (this: HTMLAnchorElement) {
      clicks.push(this.download)
    }

    try {
      renderWith(<SalesSettingsPage />)
      fireEvent.click(await screen.findByRole('button', { name: /export settings/i }))

      await waitFor(() => expect(clicks).toHaveLength(1))
      expect(clicks[0]).toMatch(/^sales-settings-\d{4}-\d{2}-\d{2}\.json$/)
      const blob = (createObjectURL.mock.calls[0] as unknown as [Blob])[0]
      const exported = JSON.parse(await blob.text())
      expect(exported.sales_settings.invoice.available_reviewers).toBeUndefined()
      expect(exported.document_numbering.invoice.format).toBe('INV-{YYYY}-{SEQ:4}')
    } finally {
      HTMLAnchorElement.prototype.click = origClick
    }
  })
})

describe('isChangedFromDefault', () => {
  it('treats blank and null text as equal and compares numbers numerically', () => {
    expect(isChangedFromDefault(null, '')).toBe(false)
    expect(isChangedFromDefault('  ', '')).toBe(false)
    expect(isChangedFromDefault('7', 7)).toBe(false)
    expect(isChangedFromDefault(8, 7)).toBe(true)
    expect(isChangedFromDefault(false, true)).toBe(true)
    expect(isChangedFromDefault('x', undefined)).toBe(false)
  })
})
