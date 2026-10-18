// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SalesDefaultsSettings } from './SalesSettingsSections'

// jsdom polyfills Radix needs at render time
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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPut: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: { get: apiGet, put: apiPut },
}))

const availableReviewers = [
  { id: 7, name: 'Sam Seller', email: 'sam@example.test' },
  { id: 8, name: 'Sara Seller', email: 'sara@example.test' },
]

function settingsPayload(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        invoice: { available_reviewers: availableReviewers },
        defaults: { salesperson_mode: 'none', team_by_user: {} },
        pricelist: { display: 'show_discount' },
        ...overrides,
      },
    },
  }
}

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  apiPut.mockImplementation(async () => ({ data: {} }))
})

afterEach(cleanup)

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <SalesDefaultsSettings />
    </QueryClientProvider>,
  )
}

describe('SalesDefaultsSettings (#553)', () => {
  it('renders the tenant defaults and saves changes for both groups', async () => {
    apiGet.mockImplementation(async () => settingsPayload())

    renderSection()

    // Existing values render.
    expect(await screen.findByText('No default (leave blank)')).toBeTruthy()
    expect(screen.getByText('Show discount on lines')).toBeTruthy()

    // Assign Sam Seller a default team.
    const samTeam = (await screen.findByLabelText('Default team for Sam Seller')) as HTMLInputElement
    fireEvent.change(samTeam, { target: { value: 'Enterprise' } })

    // Switch the salesperson mode to current user.
    fireEvent.click(screen.getByText('No default (leave blank)'))
    fireEvent.click(await screen.findByText('Current user'))

    // Switch the pricelist display to net price.
    fireEvent.click(screen.getByText('Show discount on lines'))
    fireEvent.click(await screen.findByText('Net price only'))

    fireEvent.click(await screen.findByRole('button', { name: /save defaults/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        defaults: {
          salesperson_mode: 'current_user',
          team_by_user: { '7': 'Enterprise' },
        },
        pricelist: { display: 'net_price' },
      })
    })
  })

  it('prefills configured defaults without showing the save bar', async () => {
    apiGet.mockImplementation(async () =>
      settingsPayload({
        defaults: { salesperson_mode: 'current_user', team_by_user: { '7': 'Enterprise' } },
        pricelist: { display: 'net_price' },
      }),
    )

    renderSection()

    expect(await screen.findByText('Current user')).toBeTruthy()
    expect(screen.getByText('Net price only')).toBeTruthy()
    const samTeam = (await screen.findByLabelText('Default team for Sam Seller')) as HTMLInputElement
    expect(samTeam.value).toBe('Enterprise')
    expect(screen.queryByRole('button', { name: /save defaults/i })).toBeNull()
  })
})
