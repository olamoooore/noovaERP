// @vitest-environment jsdom
import { describe, it, expect, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import {
  DeliveryOrderInvoicingProgressSummary,
  DeliveryOrderLineInvoicingChip,
  InvoiceDeliveryChip,
  InvoiceDeliveryProgressSummary,
  InvoiceLineDeliveryChip,
} from './DocumentProgressChip'
import { progressClassName, type DeliveryOrderInvoicingProgress, type InvoiceDeliveryProgress } from './documentProgress'

/**
 * Issue #688 (INV-UX2): "Delivered 6/10" on invoices and "Invoiced 10/10" on
 * delivery orders, per line and header, with links to the counterpart docs.
 */

afterEach(() => cleanup())

const invoiceProgress: InvoiceDeliveryProgress = {
  status: 'partially_delivered',
  quantity: 10,
  delivered_quantity: 6,
  lines: { '41': { quantity: 10, delivered_quantity: 6 } },
  delivery_orders: [{ id: 7, delivery_order_number: 'DO-0007', status: 'done' }],
}

const deliveryProgress: DeliveryOrderInvoicingProgress = {
  status: 'fully_invoiced',
  quantity: 10,
  invoiced_quantity: 10,
  lines: { '12': { quantity: 10, invoiced_quantity: 10 } },
  invoices: [{ id: 3, invoice_number: 'INV-0003', status: 'sent', lifecycle_status: 'posted' }],
}

describe('Issue #688 document progress chips', () => {
  it('shows "Delivered 6/10" with links to the delivery orders on an invoice', () => {
    render(
      <MemoryRouter>
        <InvoiceDeliveryProgressSummary progress={invoiceProgress} />
      </MemoryRouter>,
    )

    expect(screen.getByText('Delivered 6/10')).toBeTruthy()
    const link = screen.getByRole('link', { name: 'DO-0007' })
    expect(link.getAttribute('href')).toBe('/delivery-orders/7')
  })

  it('shows "Invoiced 10/10" with links to the invoices on a delivery order', () => {
    render(
      <MemoryRouter>
        <DeliveryOrderInvoicingProgressSummary progress={deliveryProgress} />
      </MemoryRouter>,
    )

    expect(screen.getByText('Invoiced 10/10')).toBeTruthy()
    const link = screen.getByRole('link', { name: 'INV-0003' })
    expect(link.getAttribute('href')).toBe('/invoices/3')
  })

  it('renders per-line chips keyed by line id', () => {
    render(
      <>
        <InvoiceLineDeliveryChip progress={invoiceProgress} lineId={41} />
        <DeliveryOrderLineInvoicingChip progress={deliveryProgress} lineId={12} />
        <InvoiceLineDeliveryChip progress={invoiceProgress} lineId={99} />
      </>,
    )

    expect(screen.getAllByText('Delivered 6/10')).toHaveLength(1)
    expect(screen.getByText('Invoiced 10/10')).toBeTruthy()
  })

  it('hides the chip when there is nothing to track', () => {
    const { container } = render(
      <MemoryRouter>
        <InvoiceDeliveryChip progress={{ status: 'not_tracked', quantity: 0, delivered_quantity: 0 }} />
        <InvoiceDeliveryProgressSummary progress={null} />
        <DeliveryOrderInvoicingProgressSummary
          progress={{ status: 'nothing_to_invoice', quantity: 0, invoiced_quantity: 0 }}
        />
      </MemoryRouter>,
    )

    expect(container.textContent).toBe('')
  })

  it('colours complete, partial and empty progress differently', () => {
    expect(progressClassName(10, 10)).toContain('green')
    expect(progressClassName(6, 10)).toContain('amber')
    expect(progressClassName(0, 10)).toContain('slate')
  })
})
