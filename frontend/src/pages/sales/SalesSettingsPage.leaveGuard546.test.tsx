// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesSettingsPage } from './SalesSettingsPage'

// #546 (SET-UX2): switching settings section must not silently discard edits.

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

function formatsPayload() {
  const f = (current: string) => ({ current, default: current, example: current })
  return {
    data: {
      invoice: f('INV-{YYYY}-{SEQ:4}'),
      quotation: f('QUO-{YYYY}-{SEQ:4}'),
      sales_order: f('SO-{YYYY}-{SEQ:4}'),
      delivery_order: f('DO-{YYYY}-{SEQ:4}'),
      credit_note: f('CN-{YYYY}-{SEQ:4}'),
      payment: f('PAY/{YYYY}/{MM}/{SEQ:3}'),
      date_format: 'Y-m-d',
    },
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SalesSettingsPage />
    </QueryClientProvider>,
  )
}

async function editInvoiceFormat() {
  const input = (await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')) as HTMLInputElement
  fireEvent.change(input, { target: { value: 'SI-{YYYY}-{SEQ:5}' } })
  await screen.findByText('Unsaved changes')
}

function sectionButton(title: string) {
  const nav = screen.getByRole('navigation')
  return within(nav).getByText(title).closest('button') as HTMLButtonElement
}

describe('SalesSettingsPage — unsaved changes on section switch (#546)', () => {
  beforeEach(() => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/settings/document-formats') return Promise.resolve(formatsPayload())
      if (url === '/chart-of-accounts') return Promise.resolve({ data: { data: [] } })
      return Promise.resolve({ data: { data: {} } })
    })
    apiPut.mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('switches section immediately when nothing is unsaved', async () => {
    renderPage()
    await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')
    fireEvent.click(sectionButton('Cash Rounding'))
    expect(screen.queryByText('Discard unsaved changes?')).toBeNull()
    await waitFor(() => expect(screen.queryByDisplayValue('INV-{YYYY}-{SEQ:4}')).toBeNull())
  })

  it('marks the dirty section in the nav and asks before leaving it', async () => {
    renderPage()
    await editInvoiceFormat()

    expect(sectionButton('Document Numbering').getAttribute('data-dirty')).toBe('true')

    fireEvent.click(sectionButton('Cash Rounding'))
    expect(await screen.findByText('Discard unsaved changes?')).toBeTruthy()

    // Keep editing: the edit survives and the section did not change.
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }))
    await waitFor(() => expect(screen.queryByText('Discard unsaved changes?')).toBeNull())
    expect(screen.getByDisplayValue('SI-{YYYY}-{SEQ:5}')).toBeTruthy()
  })

  it('discards and switches only after the user confirms', async () => {
    renderPage()
    await editInvoiceFormat()

    fireEvent.click(sectionButton('Cash Rounding'))
    fireEvent.click(await screen.findByRole('button', { name: 'Discard and switch' }))

    await waitFor(() => expect(screen.queryByDisplayValue('SI-{YYYY}-{SEQ:5}')).toBeNull())
    expect(sectionButton('Document Numbering').getAttribute('data-dirty')).toBeNull()

    // A later switch with no edits does not prompt again.
    fireEvent.click(sectionButton('Delivery & Sending'))
    expect(screen.queryByText('Discard unsaved changes?')).toBeNull()
  })

  it('warns the browser before unload while a section has unsaved changes', async () => {
    renderPage()
    await editInvoiceFormat()
    const evt = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(evt)
    expect(evt.defaultPrevented).toBe(true)
  })
})
