// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ChatterPanel } from './ChatterPanel'

vi.mock('@/lib/chatterApi', () => {
  const sample = {
    id: 41,
    body: 'Heads up @Sam Sales, please review. @nobody stays plain.',
    mentions: [{ id: 7, name: 'Sam Sales', email: 'sam.sales@acme.test' }],
    created_at: '2026-10-05T10:00:00Z',
    actor: { id: 1, name: 'Ada Admin', email: 'ada.admin@acme.test' },
  }

  const api = {
    list: vi.fn(async () => ({
      logs: [],
      activities: [],
      attachments: [],
      messages: [sample],
    })),
    listFollowers: vi.fn(async () => ({ followers: [], partnerContacts: [] })),
    addMessage: vi.fn(),
    addActivity: vi.fn(),
    markActivityDone: vi.fn(),
    uploadAttachment: vi.fn(),
    deleteAttachment: vi.fn(),
    follow: vi.fn(),
    unfollow: vi.fn(),
  }

  return { default: api }
})

afterEach(cleanup)

function renderPanel() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <ChatterPanel subjectType="App\\Models\\Quotation" subjectId={1} />
    </QueryClientProvider>,
  )
}

describe('ChatterPanel mentions (#635)', () => {
  it('renders a resolved mention as a chip instead of plain body text', async () => {
    renderPanel()

    fireEvent.click(screen.getByText('Messages'))

    await waitFor(() => expect(screen.getByText(/please review/)).toBeTruthy())

    const chip = screen.getByText('@Sam Sales')
    expect(chip.tagName).toBe('SPAN')
    expect(chip.className).toContain('mention')

    // The unknown token is not highlighted — it stays inside the message body.
    expect(screen.getByText(/@nobody stays plain/)).toBeTruthy()
  })
})
