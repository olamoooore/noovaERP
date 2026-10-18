// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { PrintSendMenu } from './PrintSendMenu'
import { PRINT_SEND_CHANNELS } from './documentSendChannels'
import type { DocumentTemplateType } from './DocumentTemplateDialog'

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

const DOCUMENT_TYPES: DocumentTemplateType[] = [
  'invoice',
  'quotation',
  'delivery_order',
  'sales_order',
  'credit_note',
  'receipt',
  'customer_statement',
]

const RESOLUTION = {
  outcome: 'RESOLVED',
  template_id: 'tpl-1',
  template_name: 'Standard Document',
  rule: { scope_type: 'entity', scope_value: '3' },
  rule_label: 'entity rule',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url.startsWith('/documents/')) {
      return { data: { data: RESOLUTION } }
    }
    if (url.startsWith('/template-builder/templates')) {
      return {
        data: {
          data: [
            { id: 'tpl-1', name: 'Standard Document' },
            { id: 'tpl-2', name: 'Alternative Layout' },
          ],
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderMenu(props: Partial<React.ComponentProps<typeof PrintSendMenu>> = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <PrintSendMenu
        documentType="invoice"
        pdfPath="/invoices/9/pdf"
        filename="invoice-INV-9.pdf"
        title="Invoice"
        resolutionPath="/documents/invoice/resolution?document_id=9"
        {...props}
      />
    </QueryClientProvider>,
  )
}

async function openMenu() {
  fireEvent.pointerDown(screen.getByRole('button', { name: /print \/ send/i }), { button: 0, ctrlKey: false })
  await screen.findByText('Preview')
}

describe('PrintSendMenu (#618)', () => {
  it('shows the resolver template and rule as the Using label', async () => {
    renderMenu()
    await openMenu()

    expect(await screen.findByText('Using: Standard Document (entity rule)')).toBeTruthy()
  })

  it.each(DOCUMENT_TYPES)(
    'shows Preview, Download PDF, Print and Choose template for %s',
    async (documentType) => {
      renderMenu({ documentType, onEmail: vi.fn(), onWhatsApp: vi.fn() })
      await openMenu()

      expect(screen.getByText('Preview')).toBeTruthy()
      expect(screen.getByText('Download PDF')).toBeTruthy()
      expect(screen.getByText('Print')).toBeTruthy()
      expect(screen.getByText('Choose template')).toBeTruthy()
    },
  )

  it.each(DOCUMENT_TYPES)(
    'hides the send actions %s has no endpoint for, even when a handler is passed',
    async (documentType) => {
      const { email, whatsapp } = PRINT_SEND_CHANNELS[documentType]
      renderMenu({ documentType, onEmail: vi.fn(), onWhatsApp: vi.fn() })
      await openMenu()

      if (email) {
        expect(screen.getByText('Email')).toBeTruthy()
      } else {
        expect(screen.queryByText('Email')).toBeNull()
      }
      if (whatsapp) {
        expect(screen.getByText('WhatsApp')).toBeTruthy()
      } else {
        expect(screen.queryByText('WhatsApp')).toBeNull()
      }
    },
  )

  it('invokes the page email and WhatsApp handlers', async () => {
    const onEmail = vi.fn()
    const onWhatsApp = vi.fn()
    renderMenu({ onEmail, onWhatsApp })
    await openMenu()

    fireEvent.click(screen.getByText('Email'))
    expect(onEmail).toHaveBeenCalledTimes(1)

    await openMenu()
    fireEvent.click(screen.getByText('WhatsApp'))
    expect(onWhatsApp).toHaveBeenCalledTimes(1)
  })

  it('previews the document from the given endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:preview'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)

    renderMenu()
    await openMenu()
    await screen.findByText('Using: Standard Document (entity rule)')

    fireEvent.click(screen.getByText('Preview'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/invoices/9/pdf',
        expect.objectContaining({
          params: { template: 'tpl-1' },
          responseType: 'blob',
        }),
      )
    })
    expect(open).toHaveBeenCalled()
  })

  it('prints the document from the given endpoint with the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:print'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })

    renderMenu()
    await openMenu()
    await screen.findByText('Using: Standard Document (entity rule)')

    fireEvent.click(screen.getByText('Print'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/invoices/9/pdf',
        expect.objectContaining({
          params: { template: 'tpl-1' },
          responseType: 'blob',
        }),
      )
    })
  })

  it('downloads the PDF from the given endpoint defaulted to the resolved template', async () => {
    Object.defineProperty(window.URL, 'createObjectURL', { value: vi.fn(() => 'blob:download'), configurable: true })
    Object.defineProperty(window.URL, 'revokeObjectURL', { value: vi.fn(), configurable: true })

    renderMenu()
    await openMenu()
    await screen.findByText('Using: Standard Document (entity rule)')

    fireEvent.click(screen.getByText('Download PDF'))

    expect(await screen.findByText('Download Invoice PDF')).toBeTruthy()
    // The dialog opens on the resolver's pick, not the tenant-wide default.
    expect(await screen.findByText('Standard Document')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: /download default/i }))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith(
        '/invoices/9/pdf',
        expect.objectContaining({
          params: { template: 'tpl-1' },
          responseType: 'blob',
        }),
      )
    })
  })

  it('opens the shared dialog straight on the template chooser', async () => {
    renderMenu()
    await openMenu()

    fireEvent.click(screen.getByText('Choose template'))

    expect(await screen.findByText('Select another template before downloading.')).toBeTruthy()
  })
})

describe('PrintSendMenu — documents from the same record (#405)', () => {
  const documents = [
    {
      documentType: 'sales_proforma_invoice' as const,
      label: 'Pro-forma invoice',
      pdfPath: '/sales/orders/9/proforma/pdf',
      filename: 'proforma.pdf',
      title: 'Pro-forma Invoice',
      resolutionPath: '/documents/sales_proforma_invoice/resolution?document_id=9',
    },
  ]

  it('lists no document picker when only one document is given', async () => {
    renderMenu()
    await openMenu()

    expect(screen.queryByText('Document')).toBeNull()
  })

  it('keeps send actions on the primary document only', async () => {
    renderMenu({ label: 'Invoice', documents, onEmail: vi.fn(), onWhatsApp: vi.fn() })
    await openMenu()
    expect(screen.getByText('Email')).toBeTruthy()

    fireEvent.click(screen.getByText('Pro-forma invoice'))

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/documents/sales_proforma_invoice/resolution?document_id=9')
    })
    expect(screen.queryByText('Email')).toBeNull()
    expect(screen.queryByText('WhatsApp')).toBeNull()
  })
})
