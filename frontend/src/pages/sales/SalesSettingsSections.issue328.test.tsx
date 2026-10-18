// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { FollowupSettings } from './SalesSettingsSections'
import { followupLevelsError } from './salesSettingsTools'

// #328: Sales Settings → Payment Follow-ups saves the automatic switch and
// the follow-up levels (days overdue -> action, template).

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
        followup: {
          auto_send_enabled: false,
          levels: [{ days: 7, action: 'send', template: 'invoice.overdue' }],
          actions: ['send', 'manual'],
        },
      },
    },
  }))
})

afterEach(cleanup)

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <FollowupSettings />
    </QueryClientProvider>,
  )
}

describe('FollowupSettings (#328)', () => {
  it('turns automatic follow-ups on and adds a level', async () => {
    renderSection()

    const toggle = await screen.findByLabelText('Send follow-ups automatically')
    expect(toggle.getAttribute('aria-checked')).toBe('false')
    fireEvent.click(toggle)

    fireEvent.click(screen.getByRole('button', { name: /add level/i }))
    fireEvent.change(screen.getByLabelText('Level 2 days overdue'), { target: { value: '30' } })

    fireEvent.click(screen.getByRole('button', { name: /save follow-up settings/i }))

    await waitFor(() => {
      expect(apiPut).toHaveBeenCalledWith('/settings/sales', {
        followup: {
          auto_send_enabled: true,
          levels: [
            { days: 7, action: 'send', template: 'invoice.overdue' },
            { days: 30, action: 'send', template: 'invoice.overdue' },
          ],
        },
      })
    })
  })

  it('does not save levels that repeat a day count', async () => {
    renderSection()

    fireEvent.click(await screen.findByRole('button', { name: /add level/i }))
    fireEvent.change(screen.getByLabelText('Level 2 days overdue'), { target: { value: '7' } })

    expect(screen.getByText(/different number of days overdue/i)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: /save follow-up settings/i }))
    expect(apiPut).not.toHaveBeenCalled()
  })

  it('validates levels', () => {
    expect(followupLevelsError([])).toMatch(/at least one/i)
    expect(followupLevelsError([{ days: 0, action: 'send', template: '' }])).toMatch(/1 to 365/)
    expect(followupLevelsError([{ days: 7, action: 'send', template: '' }, { days: 30, action: 'manual', template: '' }])).toBeNull()
  })
})
