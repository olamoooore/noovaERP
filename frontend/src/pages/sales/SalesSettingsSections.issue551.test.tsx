// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesOrderSettings } from './SalesSettingsSections'

// #551: Sales Settings → Sales Orders saves the pro-forma invoice toggle.

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
        sales_order: {
          reviewer_user_ids: [],
          reviewer_roles: [],
          auto_lock_on_confirm: false,
          default_invoicing_policy: 'ordered_qty',
          proforma_enabled: true,
        },
      },
    },
  }))
})

afterEach(cleanup)

describe('SalesOrderSettings (#551)', () => {
  it('turns the pro-forma invoice off', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <SalesOrderSettings />
      </QueryClientProvider>,
    )

    const toggle = await screen.findByLabelText('Pro-forma invoice')
    expect(toggle.getAttribute('aria-checked')).toBe('true')
    fireEvent.click(toggle)

    fireEvent.click(screen.getByRole('button', { name: /save sales order settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', { sales_order: { proforma_enabled: false } })
    })
  })
})
