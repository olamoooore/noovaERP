// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { NotificationPreferencesCard } from './NotificationPreferencesCard'
import api from '@/lib/api'

vi.mock('@/lib/api', () => {
  const api = {
    get: vi.fn(async () => ({
      data: {
        data: [
          {
            event: 'invoice.posted',
            label: 'Invoice posted (accounts)',
            channels: { in_app: true, mail: true },
          },
          {
            event: 'sales.credit_limit_exceeded',
            label: 'Credit limit exceeded',
            channels: { in_app: true, mail: false },
          },
        ],
      },
    })),
    put: vi.fn(async () => ({ data: { message: 'Preference saved.' } })),
  }

  return { default: api }
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function renderCard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <NotificationPreferencesCard />
    </QueryClientProvider>,
  )
}

describe('NotificationPreferencesCard (#903)', () => {
  it('renders the sales events with one switch per channel, reflecting saved state', async () => {
    renderCard()

    await waitFor(() => expect(screen.getByText('Invoice posted (accounts)')).toBeTruthy())
    expect(screen.getByText('Credit limit exceeded')).toBeTruthy()

    const invoiceInApp = screen.getByRole('switch', { name: 'Invoice posted (accounts) In-app' })
    const invoiceMail = screen.getByRole('switch', { name: 'Invoice posted (accounts) Email' })
    const creditMail = screen.getByRole('switch', { name: 'Credit limit exceeded Email' })

    expect(invoiceInApp.getAttribute('data-state')).toBe('checked')
    expect(invoiceMail.getAttribute('data-state')).toBe('checked')
    expect(creditMail.getAttribute('data-state')).toBe('unchecked')
  })

  it('saves a channel mute through the preferences API', async () => {
    renderCard()

    const mailSwitch = await screen.findByRole('switch', { name: 'Invoice posted (accounts) Email' })
    fireEvent.click(mailSwitch)

    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('/notifications/preferences', {
        event: 'invoice.posted',
        channel: 'mail',
        enabled: false,
      }),
    )
  })
})
