// @vitest-environment jsdom
// #347 (D10): global Ctrl/⌘+K command bar for keyboard-only document entry —
// create quotation / order / invoice / payment, open a document by number, go
// to a customer, and the #508 register-payment shortcut. Every command is
// filtered by the same permission the matching button / API route checks, and
// the shortcut never fires while typing in a field.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
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

import { CommandBar } from './CommandBar'
import { PaymentShortcutsDialog } from '@/components/payment/PaymentShortcutsDialog'
import { claimCommandBar } from '@/lib/keyboardShortcuts'

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

const postedInvoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: '', phone: '', billing_address: '' },
  invoice_date: '2026-02-01',
  due_date: '2026-02-28',
  status: 'posted',
  subtotal: 100,
  tax_amount: 0,
  discount_amount: 0,
  total: 100,
  paid_amount: 40,
  balance: 60,
  written_off_amount: 0,
  lines: [],
  payments: [],
}
const draftInvoice = { ...postedInvoice, id: 10, invoice_number: 'INV-2026-0010', status: 'draft', paid_amount: 0, balance: 100 }

const ALL_SALES_PERMISSIONS = [
  'quotations.view', 'quotations.write',
  'sales.view', 'sales.write',
  'invoices.view', 'invoices.write',
  'accounting.view', 'accounting.write',
  'customers.view',
]

function setPermissions(permissions: string[]) {
  useAuthStore.setState({
    role: 'Sales',
    user: { id: 2, name: 'Sam', email: 's@b.c' },
    token: 't',
    permissions,
  })
}

beforeEach(() => {
  setPermissions(ALL_SALES_PERMISSIONS)
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    const search = String(config?.params?.search ?? '')
    if (url === '/invoices') {
      const rows = [postedInvoice, draftInvoice].filter((row) => row.invoice_number.includes(search.toUpperCase()))
      return { data: { data: rows, current_page: 1, last_page: 1, total: rows.length } }
    }
    if (url === '/quotations') {
      return { data: { data: search.toUpperCase().startsWith('QT') ? [{ id: 4, quotation_number: 'QT-2026-0004', customer: { name: 'Klinik Ali' } }] : [] } }
    }
    if (url === '/sales/orders') {
      return { data: { data: search.toUpperCase().startsWith('SO') ? [{ id: 5, sales_order_number: 'SO-2026-0005', customer: { name: 'Klinik Ali' } }] : [] } }
    }
    if (url === '/customers') {
      return { data: { data: search.toLowerCase().includes('klinik') ? [{ id: 1, code: 'KA-1', name: 'Klinik Ali' }] : [] } }
    }
    if (url === '/invoices/9') return { data: postedInvoice }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function LocationProbe() {
  const location = useLocation()
  return <div data-testid="location">{location.pathname}</div>
}

function renderBar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/dashboard']}>
        <CommandBar />
        <input aria-label="Some field" />
        <Routes>
          <Route path="*" element={<LocationProbe />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const openBar = async () => {
  fireEvent.keyDown(document.body, { key: 'k', ctrlKey: true })
  return screen.findByRole('dialog')
}

const typeCommand = (dialog: HTMLElement, text: string) => {
  const input = within(dialog).getByPlaceholderText(/type a command/i)
  fireEvent.change(input, { target: { value: text } })
  return input
}

const location = () => screen.getByTestId('location').textContent

describe('CommandBar — Ctrl/⌘+K (#347 D10)', () => {
  it('Ctrl+K and ⌘+K open the command bar', async () => {
    renderBar()
    const dialog = await openBar()
    expect(within(dialog).getByText('Command bar')).toBeTruthy()
    fireEvent.keyDown(within(dialog).getByPlaceholderText(/type a command/i), { key: 'Escape' })
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    fireEvent.keyDown(document.body, { key: 'K', metaKey: true })
    expect(await screen.findByRole('dialog')).toBeTruthy()
  })

  it('does not open while typing in a text input', async () => {
    renderBar()
    const field = screen.getByLabelText('Some field')
    field.focus()
    fireEvent.keyDown(field, { key: 'k', ctrlKey: true })
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  it('defers to a page that has its own command bar (invoice form)', async () => {
    renderBar()
    const release = claimCommandBar()
    try {
      fireEvent.keyDown(document.body, { key: 'k', ctrlKey: true })
      await new Promise((r) => setTimeout(r, 20))
      expect(screen.queryByRole('dialog')).toBeNull()
    } finally {
      release()
    }
    expect(await openBar()).toBeTruthy()
  })

  it('create commands navigate to the matching create page, keyboard only', async () => {
    renderBar()
    let dialog = await openBar()
    let input = typeCommand(dialog, 'new quotation')
    fireEvent.keyDown(input, { key: 'Enter' })
    await waitFor(() => expect(location()).toBe('/quotations/create'))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

    dialog = await openBar()
    input = typeCommand(dialog, 'new order')
    fireEvent.keyDown(input, { key: 'Enter' })
    await waitFor(() => expect(location()).toBe('/sales/orders/create'))

    dialog = await openBar()
    input = typeCommand(dialog, 'new invoice')
    fireEvent.keyDown(input, { key: 'Enter' })
    await waitFor(() => expect(location()).toBe('/invoices/create'))
  })

  it('create commands are hidden without the write permission the route checks', async () => {
    setPermissions(['quotations.view', 'sales.view', 'invoices.view', 'accounting.view'])
    renderBar()
    const dialog = await openBar()
    expect(within(dialog).queryByText(/new quotation/i)).toBeNull()
    expect(within(dialog).queryByText(/new sales order/i)).toBeNull()
    expect(within(dialog).queryByText(/new invoice/i)).toBeNull()
    expect(within(dialog).queryByText(/register payment/i)).toBeNull()
    expect(within(dialog).queryByText(/go to customer/i)).toBeNull()
  })

  it('opens a document by number from the list endpoints', async () => {
    renderBar()
    const dialog = await openBar()
    typeCommand(dialog, 'open INV-2026-0009')
    const hit = await within(dialog).findByText('INV-2026-0009')
    expect(apiGet).toHaveBeenCalledWith('/invoices', expect.objectContaining({ params: expect.objectContaining({ search: 'INV-2026-0009' }) }))
    fireEvent.click(hit)
    await waitFor(() => expect(location()).toBe('/invoices/9'))
  })

  it('opens a sales order or quotation by number', async () => {
    renderBar()
    let dialog = await openBar()
    typeCommand(dialog, 'SO-2026-0005')
    fireEvent.click(await within(dialog).findByText('SO-2026-0005'))
    await waitFor(() => expect(location()).toBe('/sales/orders/5'))

    dialog = await openBar()
    typeCommand(dialog, 'open QT-2026-0004')
    fireEvent.click(await within(dialog).findByText('QT-2026-0004'))
    await waitFor(() => expect(location()).toBe('/quotations/4'))
  })

  it('only searches the document lists the user may view', async () => {
    setPermissions(['invoices.view'])
    renderBar()
    const dialog = await openBar()
    typeCommand(dialog, 'open INV-2026-0009')
    await within(dialog).findByText('INV-2026-0009')
    const urls = apiGet.mock.calls.map((call) => call[0])
    expect(urls).not.toContain('/quotations')
    expect(urls).not.toContain('/sales/orders')
    expect(urls).not.toContain('/customers')
  })

  it('goes to a customer', async () => {
    renderBar()
    const dialog = await openBar()
    typeCommand(dialog, 'customer klinik')
    fireEvent.click(await within(dialog).findByText('Klinik Ali'))
    await waitFor(() => expect(location()).toBe('/customers/1'))
  })

  it('pay <invoice> opens the #508 register-payment dialog prefilled for a payable invoice', async () => {
    renderBar()
    const dialog = await openBar()
    typeCommand(dialog, 'pay INV-2026-00')
    const hit = await within(dialog).findByText('INV-2026-0009')
    // Draft invoices are not payable — same rule as the row "P" shortcut.
    expect(within(dialog).queryByText('INV-2026-0010')).toBeNull()
    fireEvent.click(hit)

    const payment = await screen.findByRole('dialog', { name: /register payment/i })
    const amount = within(payment).getByLabelText(/^amount/i) as HTMLInputElement
    await waitFor(() => expect(amount.value).toBe('60'))
    expect(location()).toBe('/dashboard')
  })

  it('pay is not offered without accounting.write', async () => {
    setPermissions(['invoices.view', 'accounting.view'])
    renderBar()
    const dialog = await openBar()
    typeCommand(dialog, 'pay INV-2026-0009')
    await new Promise((r) => setTimeout(r, 400))
    expect(within(dialog).queryByText(/register payment/i)).toBeNull()
    expect(screen.queryByRole('dialog', { name: /register payment/i })).toBeNull()
  })
})

describe('"?" shortcuts overlay lists the command bar (#347)', () => {
  it('shows Ctrl+K alongside the payment shortcuts', () => {
    render(<PaymentShortcutsDialog open onOpenChange={() => {}} />)
    const overlay = screen.getByRole('dialog')
    expect(within(overlay).getByText(/open the command bar/i)).toBeTruthy()
    expect(within(overlay).getByText(/register a payment for the focused invoice row/i)).toBeTruthy()
  })
})
