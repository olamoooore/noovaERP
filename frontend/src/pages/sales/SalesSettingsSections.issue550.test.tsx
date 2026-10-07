// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { QuotationSettings } from './SalesSettingsSections'

// #550: Sales Settings → Quotations saves the online acceptance requirements.

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
  apiGet.mockImplementation(async () => ({
    data: {
      data: {
        quotation: {
          reviewer_user_ids: [],
          reviewer_roles: [],
          default_validity_days: 30,
          default_terms: '',
          require_signature: false,
          require_payment: false,
          prepayment_percent: 100,
        },
      },
    },
  }))
})

afterEach(cleanup)

describe('QuotationSettings (#550)', () => {
  it('saves require signature, require payment and the prepayment percent', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <QuotationSettings />
      </QueryClientProvider>,
    )

    fireEvent.click(await screen.findByLabelText('Require online signature'))
    expect(screen.queryByLabelText('Prepayment percent')).toBeNull()
    fireEvent.click(screen.getByLabelText('Require online payment'))
    fireEvent.change(screen.getByLabelText('Prepayment percent'), { target: { value: '30' } })

    fireEvent.click(screen.getByRole('button', { name: /save quotation settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        quotation: { require_signature: true, require_payment: true, prepayment_percent: 30 },
      })
    })
  })
})
