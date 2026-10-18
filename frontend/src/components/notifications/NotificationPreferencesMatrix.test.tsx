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
            channels: { in_app: true, mail: true, whatsapp: false },
            is_default: { in_app: true, mail: true, whatsapp: true },
            available: { in_app: true, mail: true, whatsapp: true },
          },
          {
            event: 'einvoice.status_changed',
            label: 'E-invoice rejected / status changed',
            channels: { in_app: false, mail: true, whatsapp: false },
            is_default: { in_app: true, mail: true, whatsapp: true },
            available: { in_app: false, mail: true, whatsapp: true },
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

describe('NotificationPreferencesCard matrix (#907)', () => {
  it('renders one column per channel including WhatsApp, with effective state and default markers', async () => {
    renderCard()

    await waitFor(() => expect(screen.getByText('Invoice posted (accounts)')).toBeTruthy())

    expect(screen.getByText('In-app')).toBeTruthy()
    expect(screen.getByText('Email')).toBeTruthy()
    expect(screen.getByText('WhatsApp')).toBeTruthy()

    const whatsapp = screen.getByRole('switch', { name: 'Invoice posted (accounts) WhatsApp' })
    expect(whatsapp.getAttribute('data-state')).toBe('unchecked')

    const invoiceInApp = screen.getByRole('switch', { name: 'Invoice posted (accounts) In-app' })
    expect(invoiceInApp.getAttribute('data-state')).toBe('checked')

    // Untouched cells are labelled as the role default so the pre-ticked
    // matrix is legible at a glance.
    expect(screen.getByTestId('default-invoice.posted-mail')).toBeTruthy()
    expect(screen.getByTestId('default-invoice.posted-whatsapp')).toBeTruthy()
  })

  it('saves a WhatsApp cell toggle through the same preferences API', async () => {
    renderCard()

    const whatsapp = await screen.findByRole('switch', { name: 'Invoice posted (accounts) WhatsApp' })
    fireEvent.click(whatsapp)

    await waitFor(() =>
      expect(api.put).toHaveBeenCalledWith('/notifications/preferences', {
        event: 'invoice.posted',
        channel: 'whatsapp',
        enabled: true,
      }),
    )
  })

  it('disables a channel the event does not deliver', async () => {
    renderCard()

    const einvoiceInApp = await screen.findByRole('switch', {
      name: 'E-invoice rejected / status changed In-app',
    })
    expect(einvoiceInApp.hasAttribute('disabled')).toBe(true)
  })
})
