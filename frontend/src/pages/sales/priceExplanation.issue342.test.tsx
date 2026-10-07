// @vitest-environment jsdom
/**
 * Issue #342 (SALES-DIFF D5): every quotation / sales order / invoice line
 * shows a price chip whose popover explains the price from the stored
 * pricing_trace — list, tier, FX rate and the manual override reason —
 * with cost/margin only for roles holding `pricelists.cost` (#338).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { useState } from 'react'
import { QuotationDetailPage } from './QuotationDetailPage'
import { SalesOrderDetailPage } from './SalesOrderDetailPage'
import { CustomerInvoiceDetail } from '@/pages/accounting/customers/CustomerInvoiceDetail'
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid'
import { emptySalesDocumentLine, type SalesDocumentLine } from '@/lib/salesDocumentLines'
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

const tierTrace = {
  source: 'pricelist',
  pricelist_code: 'VIP-USD',
  pricelist_name: 'VIP',
  pricelist_currency: 'USD',
  compute_type: 'percentage',
  base: 'list_price',
  percent: 10,
  min_quantity: 10,
  rate_used: 1,
  rate_date: '2026-10-01',
  document_currency: 'USD',
  base_currency: 'MYR',
  exchange_rate: 4.71,
}

const manualTrace = {
  source: 'manual',
  override_reason: 'Tender discount approved by GM',
  override_below_floor: true,
  override_floor_kind: 'below_cost',
  resolved_unit_price: 90,
}

const productLine = (overrides: Record<string, unknown> = {}) => ({
  id: 11,
  line_type: 'product',
  product_id: 7,
  product: { id: 7, name: 'Widget', sku: 'W-1' },
  description: 'Widget',
  quantity: 12,
  unit_price: 90,
  tax_rate: 0,
  tax_amount: 0,
  discount_amount: 0,
  line_total: 1080,
  quantity_allocated: 0,
  quantity_fulfilled: 0,
  quantity_delivered: 0,
  quantity_invoiced: 0,
  quantity_cancelled: 0,
  backorder_quantity: 0,
  is_backordered: false,
  pricing_trace: tierTrace,
  ...overrides,
})

const quotation = {
  id: 5,
  quotation_number: 'QT-2026-0005',
  status: 'sent',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  quotation_date: '2026-10-01',
  valid_until: '2026-12-01',
  subtotal: 1080,
  tax_amount: 0,
  discount_amount: 0,
  total: 1080,
  currency_code: 'USD',
  base_currency_code: 'MYR',
  exchange_rate: 4.71,
  version_number: 1,
  is_active_version: true,
  version_history: [],
  lines: [productLine({ margin_amount: 600, margin_percent: 55.56 })],
}

const order = {
  id: 5,
  sales_order_number: 'SO-2026-0005',
  customer_id: 1,
  customer: { id: 1, name: 'Klinik Ali' },
  order_date: '2026-10-01',
  status: 'confirmed',
  subtotal: 1080,
  tax_amount: 0,
  discount_amount: 0,
  total: 1080,
  currency_code: 'USD',
  base_currency_code: 'MYR',
  exchange_rate: 4.71,
  created_at: '2026-10-01T00:00:00Z',
  lines: [productLine({ pricing_trace: manualTrace })],
  invoices: [],
  delivery_orders: [],
}

const invoice = {
  id: 9,
  invoice_number: 'INV-2026-0009',
  customer_id: 1,
  customer: { id: 1, code: 'KA-1', name: 'Klinik Ali', email: '', phone: '', billing_address: '' },
  invoice_date: '2026-10-01',
  due_date: '2026-10-31',
  status: 'posted',
  subtotal: 1080,
  tax_amount: 0,
  discount_amount: 0,
  total: 1080,
  paid_amount: 0,
  balance: 1080,
  written_off_amount: 0,
  currency_code: 'USD',
  base_currency_code: 'MYR',
  exchange_rate: 4.71,
  notes: '',
  terms: '',
  created_at: '2026-10-01T09:00:00Z',
  lines: [productLine()],
  payments: [],
  delivery_orders: [],
  evidence: [],
  write_offs: [],
}

beforeEach(() => {
  useAuthStore.setState({ role: 'Admin', permissions: [], user: { id: 1, name: 'Admin', email: 'a@b.c' }, token: 't' } as never)
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/quotations/5') return { data: quotation }
    if (url === '/sales/orders/5') return { data: order }
    if (url === '/invoices/9') return { data: invoice }
    if (url === '/settings/sales/defaults') return { data: { data: { pricelist: { display: 'show_discount' } } } }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderAt(path: string, route: string, element: React.ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path={route} element={element} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function openExplanation(label: RegExp) {
  fireEvent.click(await screen.findByRole('button', { name: label }))
  return within(await screen.findByRole('list', { name: /price explanation/i }))
}

describe('Price explanation popover on document detail pages (#342)', () => {
  it('quotation detail explains list, tier and FX — and margin only for cost roles', async () => {
    renderAt('/quotations/5', '/quotations/:id', <QuotationDetailPage />)
    const popover = await openExplanation(/how this price was set: VIP-USD/i)
    expect(popover.getByText('Price list: VIP (VIP-USD)')).toBeTruthy()
    expect(popover.getByText('Rule: 10% off list price')).toBeTruthy()
    expect(popover.getByText('Quantity tier: qty ≥ 10')).toBeTruthy()
    expect(popover.getByText('FX: 1 USD = 4.71 MYR on 1 Oct 2026')).toBeTruthy()
    expect(popover.getByText(/^Margin:/)).toBeTruthy()
  })

  it('quotation detail hides margin from a role without pricelists.cost', async () => {
    useAuthStore.setState({ role: 'Sales', permissions: ['quotations.view', 'sales.view'] } as never)
    renderAt('/quotations/5', '/quotations/:id', <QuotationDetailPage />)
    const popover = await openExplanation(/how this price was set: VIP-USD/i)
    expect(popover.getByText('Quantity tier: qty ≥ 10')).toBeTruthy()
    expect(popover.queryByText(/margin/i)).toBeNull()
  })

  it('sales order detail flags a manual price with its recorded reason', async () => {
    useAuthStore.setState({ role: 'Sales', permissions: ['sales.view'] } as never)
    renderAt('/sales/orders/5', '/sales/orders/:id', <SalesOrderDetailPage />)
    const popover = await openExplanation(/how this price was set: manual/i)
    expect(popover.getByText('Manual price — typed on this line.')).toBeTruthy()
    expect(popover.getByText('Reason: Tender discount approved by GM')).toBeTruthy()
    // below-cost is a cost disclosure: a non-cost role sees the generic floor note
    expect(popover.getByText('Below the allowed price floor — override recorded with the reason above.')).toBeTruthy()
    expect(popover.queryByText(/below cost/i)).toBeNull()
  })

  it('invoice detail explains the line price from its trace', async () => {
    renderAt('/accounting/customers/bills/9', '/accounting/customers/bills/:id', <CustomerInvoiceDetail />)
    const popover = await openExplanation(/how this price was set: VIP-USD/i)
    expect(popover.getByText('Quantity tier: qty ≥ 10')).toBeTruthy()
    expect(popover.getByText('FX: 1 USD = 4.71 MYR on 1 Oct 2026')).toBeTruthy()
  })
})

function GridHarness({ initial }: { initial: SalesDocumentLine[] }) {
  const [lines, setLines] = useState<SalesDocumentLine[]>(initial)
  return <SalesDocumentLineGrid lines={lines} setLines={setLines} money={(value) => value.toFixed(2)} />
}

describe('Price explanation chip on the quotation / sales order line grid (#342)', () => {
  it('shows the chip for a priced line and explains a pending manual price with its reason', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <GridHarness
          initial={[
            { ...emptySalesDocumentLine(), product_id: '7', description: 'Widget', quantity: 12, unit_price: 90, price_mode: 'auto', pricing_trace: tierTrace },
            { ...emptySalesDocumentLine(), product_id: '8', description: 'Gadget', quantity: 1, unit_price: 40, price_mode: 'manual', override_reason: 'Price match', pricing_trace: tierTrace },
          ]}
        />
      </QueryClientProvider>,
    )
    const tier = await openExplanation(/how this price was set: VIP-USD/i)
    expect(tier.getByText('Quantity tier: qty ≥ 10')).toBeTruthy()
    cleanup()

    render(
      <QueryClientProvider client={qc}>
        <GridHarness
          initial={[
            { ...emptySalesDocumentLine(), product_id: '8', description: 'Gadget', quantity: 1, unit_price: 40, price_mode: 'manual', override_reason: 'Price match', pricing_trace: tierTrace },
          ]}
        />
      </QueryClientProvider>,
    )
    const manual = await openExplanation(/how this price was set: manual/i)
    expect(manual.getByText('Reason: Price match')).toBeTruthy()
  })
})
