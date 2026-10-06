// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { NumberingSettings } from './SalesSettingsSections'

// #548 (SET-UX4): the numbering editor shows the NEXT number from the server,
// an explicit reset period and a forward-only next sequence value.

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

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, put: apiPut },
}))

function formatsPayload() {
  const f = (current: string, preview: string, reset_period: string, next_sequence: number) => ({
    current,
    default: current,
    example: preview,
    preview,
    reset_period,
    reset_period_explicit: false,
    next_sequence,
  })
  return {
    data: {
      invoice: f('INV-{YYYY}-{SEQ:4}', 'INV-2026-0009', 'yearly', 9),
      quotation: f('QUO-{YYYY}-{SEQ:4}', 'QUO-2026-0001', 'yearly', 1),
      sales_order: f('SO-{YYYY}-{SEQ:4}', 'SO-2026-0001', 'yearly', 1),
      delivery_order: f('DO-{YYYY}-{SEQ:4}', 'DO-2026-0001', 'yearly', 1),
      credit_note: f('CN-{YYYY}-{SEQ:4}', 'CN-2026-0001', 'yearly', 1),
      payment: f('PAY/{YYYY}/{MM}/{SEQ:3}', 'PAY/2026/10/001', 'monthly', 1),
      date_format: 'Y-m-d',
    },
  }
}

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <NumberingSettings />
    </QueryClientProvider>,
  )
}

describe('NumberingSettings — next-number preview and reset period (#548)', () => {
  beforeEach(() => {
    apiGet.mockImplementation((url: string, config?: { params?: Record<string, string> }) => {
      if (url === '/settings/document-formats') return Promise.resolve(formatsPayload())
      if (url === '/settings/document-formats/preview') {
        return Promise.resolve({ data: { valid: true, preview: `SERVER:${config?.params?.format}`, errors: [] } })
      }
      return Promise.resolve({ data: {} })
    })
    apiPut.mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('shows the next number reported by the server, not a client-side guess', async () => {
    renderSection()
    expect((await screen.findByTestId('numbering-preview-invoice')).textContent).toBe('INV-2026-0009')
    expect(screen.getByTestId('numbering-preview-payment').textContent).toBe('PAY/2026/10/001')
    expect(screen.getAllByText('Reset sequence')).toHaveLength(6)
  })

  it('asks the server to preview an edited format', async () => {
    renderSection()
    const input = (await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'SI-{YY}-{SEQ:5}' } })

    await waitFor(() =>
      expect(screen.getByTestId('numbering-preview-invoice').textContent).toBe('SERVER:SI-{YY}-{SEQ:5}'),
    )
    expect(apiGet).toHaveBeenCalledWith('/settings/document-formats/preview', {
      params: { type: 'invoice', format: 'SI-{YY}-{SEQ:5}', reset_period: 'yearly' },
    })
  })

  it('validates the format inline and does not preview an invalid one', async () => {
    renderSection()
    const input = (await screen.findByDisplayValue('INV-{YYYY}-{SEQ:4}')) as HTMLInputElement
    fireEvent.change(input, { target: { value: 'INV-{YYYY}' } })

    expect(await screen.findByText('The format must contain exactly one {SEQ:n} token.')).toBeTruthy()
    await new Promise((r) => setTimeout(r, 400))
    expect(apiGet).not.toHaveBeenCalledWith('/settings/document-formats/preview', expect.anything())
    expect(screen.getByTestId('numbering-preview-invoice').textContent).toBe('—')
  })

  it('inserts a token chip at the cursor', async () => {
    renderSection()
    const input = (await screen.findByDisplayValue('QUO-{YYYY}-{SEQ:4}')) as HTMLInputElement
    input.setSelectionRange(4, 4)
    const row = screen.getByTestId('numbering-row-quotation')
    fireEvent.click(Array.from(row.querySelectorAll('button')).find((b) => b.textContent === '{MM}')!)
    expect(screen.getByDisplayValue('QUO-{MM}{YYYY}-{SEQ:4}')).toBeTruthy()
  })

  it('only lets the next sequence value move forward, and saves it', async () => {
    renderSection()
    const next = (await screen.findByLabelText('Next sequence value', {
      selector: '#numbering-next-invoice',
    })) as HTMLInputElement

    fireEvent.change(next, { target: { value: '5' } })
    expect(await screen.findByText('Can only move forward: at least 9.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Save numbering' }))
    expect(apiPut).not.toHaveBeenCalled()

    fireEvent.change(next, { target: { value: '100' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save numbering' }))

    await waitFor(() => expect(apiPut).toHaveBeenCalled())
    const [url, body] = apiPut.mock.calls[0]
    expect(url).toBe('/settings/document-formats')
    expect(body.next_sequences).toEqual({ invoice: 100 })
    expect(body.invoice).toBe('INV-{YYYY}-{SEQ:4}')
  })
})
