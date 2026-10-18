// @vitest-environment jsdom
// #825 (EINV-CORE UX8): recording a customer refund on an MY entity must warn
// that a refund note will be issued to LHDN, with a preview of the document.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { PaymentCorrectionDialog } from './PaymentCorrectionDialog'

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

const payment = {
  id: 42,
  payment_number: 'PAY-2026-0042',
  amount: 250,
  currency: 'MYR',
  customer: { id: 1, name: 'Klinik Ali' },
  invoice: { id: 5, invoice_number: 'INV-2026-0005' },
}

const MY_TENANT_SETTINGS = {
  data: { tenant_country_code: 'MY', country_plugin_enabled: true },
}
const MY_CONFIG = {
  data: {
    success: true,
    data: {
      country_code: 'MY',
      is_enabled: true,
      credentials: { auto_submit: true },
    },
  },
}

function renderDialog(action: 'reverse' | 'refund' | 'unapply') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <PaymentCorrectionDialog
        payment={payment}
        action={action}
        open
        onOpenChange={() => {}}
        onSuccess={() => {}}
      />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/tenant/country-plugin-settings') return MY_TENANT_SETTINGS
    if (url === '/accounting/compliance/config/MY') return MY_CONFIG
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

describe('PaymentCorrectionDialog — refund note issuance (#825 UX8)', () => {
  it('warns that a refund note will be issued to LHDN for an MY entity, with a preview', async () => {
    renderDialog('refund')

    expect(await screen.findByText('Refund note will be issued to LHDN')).toBeTruthy()

    const preview = within(screen.getByTestId('refund-note-preview'))
    expect(preview.getByText('Refund Note (LHDN type 04)')).toBeTruthy()
    expect(preview.getByText('INV-2026-0005')).toBeTruthy()
    expect(preview.getByText('Klinik Ali')).toBeTruthy()
    expect(preview.getByText(/250\.00/)).toBeTruthy()
  })

  it('does not warn on reverse/unapply — those are not refund notes', () => {
    renderDialog('reverse')

    expect(screen.queryByText('Refund note will be issued to LHDN')).toBeNull()
    expect(screen.queryByTestId('refund-note-preview')).toBeNull()
  })

  it('does not warn when the entity is not MY', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/accounting/tenant/country-plugin-settings') {
        return { data: { tenant_country_code: 'SG', country_plugin_enabled: true } }
      }
      return { data: { data: [] } }
    })

    renderDialog('refund')

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/accounting/tenant/country-plugin-settings')
    })
    expect(screen.queryByText('Refund note will be issued to LHDN')).toBeNull()
  })

  it('does not warn when the MY country plugin auto-submit is off', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url === '/accounting/tenant/country-plugin-settings') return MY_TENANT_SETTINGS
      if (url === '/accounting/compliance/config/MY') {
        return {
          data: {
            success: true,
            data: {
              country_code: 'MY',
              is_enabled: true,
              credentials: { auto_submit: false },
            },
          },
        }
      }
      return { data: { data: [] } }
    })

    renderDialog('refund')

    await waitFor(() => {
      expect(apiGet).toHaveBeenCalledWith('/accounting/compliance/config/MY')
    })
    expect(screen.queryByText('Refund note will be issued to LHDN')).toBeNull()
  })
})
