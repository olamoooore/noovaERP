// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { PaymentDifferenceSettings } from './SalesSettingsSections'
import { writeOffReasonCode, writeOffReasonsError } from './salesSettingsTools'

// #494: Sales Settings → Payment Differences saves the write-off tolerance and
// the write-off reasons (label + expense account).

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

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/chart-of-accounts') {
      return {
        data: {
          data: [
            { id: 61, code: '6150', name: 'Bank charges', type: 'expense' },
            { id: 11, code: '1010', name: 'Cash', type: 'asset' },
          ],
        },
      }
    }
    return {
      data: {
        data: {
          payment: {
            write_off_tolerance: 0,
            write_off_reasons: [{ code: 'bank_charges', label: 'Bank charges', account_id: 61 }],
          },
        },
      },
    }
  })
})

afterEach(cleanup)

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <PaymentDifferenceSettings />
    </QueryClientProvider>,
  )
}

describe('PaymentDifferenceSettings (#494)', () => {
  it('saves the write-off tolerance', async () => {
    renderSection()

    const tolerance = await screen.findByLabelText('Write-off tolerance')
    fireEvent.change(tolerance, { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: /save payment difference settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { payment: { write_off_tolerance: 20 } })
    })
  })

  it('saves an edited reason with its code and account', async () => {
    renderSection()

    fireEvent.change(await screen.findByLabelText('Reason 1 label'), { target: { value: 'Bank fees' } })
    fireEvent.click(screen.getByRole('button', { name: /save payment difference settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        payment: { write_off_reasons: [{ code: 'bank_charges', label: 'Bank fees', account_id: 61 }] },
      })
    })
  })

  it('validates reasons before saving', () => {
    expect(writeOffReasonCode(' Bank charges (TT) ')).toBe('bank_charges_tt')
    expect(writeOffReasonsError([{ code: '', label: 'Rounding', account_id: null }])).toMatch(/expense account/)
    expect(writeOffReasonsError([
      { code: '', label: 'Rounding', account_id: 61 },
      { code: '', label: 'rounding', account_id: 62 },
    ])).toMatch(/share the name/)
    expect(writeOffReasonsError([{ code: '', label: 'Rounding', account_id: 61 }])).toBeNull()
  })
})
