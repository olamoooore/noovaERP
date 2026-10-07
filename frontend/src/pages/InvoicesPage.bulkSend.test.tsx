// @vitest-environment jsdom
// #329: Bulk Send (queued, per-invoice result) and the zip download of the
// selected invoices' documents from the invoice list.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'

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
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

import { InvoicesPage } from './InvoicesPage'

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

const invoice = (id: number, number: string) => ({
  id,
  invoice_number: number,
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: '', phone: '', billing_address: '' },
  invoice_date: '2026-02-01',
  due_date: '2026-02-28',
  status: 'posted',
  total: 100,
  paid_amount: 0,
  balance: 100,
  created_at: '2026-02-01T09:00:00Z',
})

beforeEach(() => {
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions: ['invoices.view', 'invoices.send', 'document.view'],
  })
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices') {
      return { data: { data: [invoice(1, 'INV-0001'), invoice(2, 'INV-0002')], current_page: 1, last_page: 1, total: 2 } }
    }
    if (url === '/invoices/bulk-download') return { data: new Blob(['zip']) }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async () => ({
    data: {
      data: {
        queued: 1,
        skipped: 1,
        results: [
          { id: 1, invoice_number: 'INV-0001', outcome: 'queued' },
          { id: 2, invoice_number: 'INV-0002', outcome: 'skipped', reason: 'No recipient email available for invoice delivery.' },
        ],
      },
    },
  }))
  window.URL.createObjectURL = vi.fn(() => 'blob:zip')
  window.URL.revokeObjectURL = vi.fn()
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/invoices']}>
        <Routes>
          <Route path="/invoices" element={<InvoicesPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function selectBothRows() {
  for (const number of ['INV-0001', 'INV-0002']) {
    const row = (await screen.findByText(number)).closest('tr') as HTMLElement
    fireEvent.click(within(row).getByRole('checkbox'))
  }
}

describe('InvoicesPage — Bulk Send and zip download (#329)', () => {
  it('queues the selected invoices through /invoices/bulk-send after confirmation', async () => {
    renderPage()
    await selectBothRows()

    fireEvent.click(screen.getByRole('button', { name: /^send \(2\)/i }))
    fireEvent.click(await screen.findByRole('button', { name: /send invoices/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/invoices/bulk-send', { ids: [1, 2] }))
  })

  it('downloads the selected invoices as a zip', async () => {
    renderPage()
    await selectBothRows()

    fireEvent.click(screen.getByRole('button', { name: /download zip \(2\)/i }))

    await waitFor(() =>
      expect(apiGet).toHaveBeenCalledWith('/invoices/bulk-download', { params: { ids: [1, 2] }, responseType: 'blob' }),
    )
  })

  it('hides Send without invoices.send', async () => {
    useAuthStore.setState({ permissions: ['invoices.view', 'document.view'] })
    renderPage()
    await selectBothRows()

    expect(screen.queryByRole('button', { name: /^send \(/i })).toBeNull()
    expect(screen.getByRole('button', { name: /download zip/i })).toBeTruthy()
  })
})
