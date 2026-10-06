// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import chatterApi from '@/lib/chatterApi'
import { ChatterPanel } from './ChatterPanel'

/**
 * Issue #636 (CHAT-UX3) — the chatter separates the customer thread from
 * internal notes: two composer modes, All / Customer / Internal / System
 * filter, and inbound replies threaded under their message.
 */
vi.mock('@/lib/chatterApi', () => {
  const messages = [
    {
      id: 1,
      body: 'Can you confirm the delivery date?',
      created_at: '2026-10-05T10:00:00Z',
      actor: { id: 1, name: 'Ada Admin' },
      category: 'customer',
      direction: 'outbound',
      channel: 'email',
      recipient: 'buyer@example.com',
      parent_id: null,
    },
    {
      id: 2,
      body: 'Delivery on Friday works.',
      created_at: '2026-10-05T12:00:00Z',
      actor: { id: 0, name: 'Cus Tomer' },
      category: 'customer',
      direction: 'inbound',
      channel: 'email',
      parent_id: 1,
    },
    {
      id: 3,
      body: 'Margin is thin, hold the discount.',
      created_at: '2026-10-05T11:00:00Z',
      actor: { id: 1, name: 'Ada Admin' },
      category: 'internal',
      is_internal_note: true,
      parent_id: null,
    },
    {
      id: 4,
      body: 'Quotation sent automatically.',
      created_at: '2026-10-05T09:00:00Z',
      actor: null,
      category: 'system',
      parent_id: null,
    },
  ]

  const api = {
    list: vi.fn(async () => ({ logs: [], activities: [], attachments: [], messages })),
    listFollowers: vi.fn(async () => ({ followers: [], partnerContacts: [] })),
    addMessage: vi.fn(async () => ({})),
    addActivity: vi.fn(),
    markActivityDone: vi.fn(),
    uploadAttachment: vi.fn(),
    deleteAttachment: vi.fn(),
    follow: vi.fn(),
    unfollow: vi.fn(),
  }

  return { default: api }
})

beforeEach(() => vi.mocked(chatterApi.addMessage).mockClear())
afterEach(cleanup)

async function openMessages() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <ChatterPanel subjectType="App\\Models\\Quotation" subjectId={9} />
    </QueryClientProvider>,
  )
  fireEvent.click(screen.getByText('Messages'))
  await waitFor(() => expect(screen.getByText('Margin is thin, hold the discount.')).toBeTruthy())
}

describe('ChatterPanel customer thread vs internal notes (#636)', () => {
  it('filters All / Customer / Internal / System', async () => {
    await openMessages()
    const filters = screen.getByRole('group', { name: 'Message filter' })

    fireEvent.click(within(filters).getByRole('button', { name: /Internal/ }))
    expect(screen.getByText('Margin is thin, hold the discount.')).toBeTruthy()
    expect(screen.queryByText('Can you confirm the delivery date?')).toBeNull()
    expect(screen.queryByText('Quotation sent automatically.')).toBeNull()

    fireEvent.click(within(filters).getByRole('button', { name: /Customer/ }))
    expect(screen.getByText('Can you confirm the delivery date?')).toBeTruthy()
    expect(screen.getByText('Delivery on Friday works.')).toBeTruthy()
    expect(screen.queryByText('Margin is thin, hold the discount.')).toBeNull()

    fireEvent.click(within(filters).getByRole('button', { name: /System/ }))
    expect(screen.getByText('Quotation sent automatically.')).toBeTruthy()
    expect(screen.queryByText('Delivery on Friday works.')).toBeNull()

    fireEvent.click(within(filters).getByRole('button', { name: /All/ }))
    expect(screen.getByText('Quotation sent automatically.')).toBeTruthy()
    expect(screen.getByText('Margin is thin, hold the discount.')).toBeTruthy()
  })

  it('threads an inbound reply under the message it answers', async () => {
    await openMessages()

    const parent = screen.getByText('Can you confirm the delivery date?').closest('[data-message-id]') as HTMLElement
    expect(parent.getAttribute('data-message-id')).toBe('1')
    expect(within(parent).getByText('Delivery on Friday works.')).toBeTruthy()
  })

  it('defaults the composer to an internal note', async () => {
    await openMessages()

    fireEvent.change(screen.getByPlaceholderText(/internal note/i), { target: { value: 'just for us' } })
    fireEvent.click(screen.getByRole('button', { name: 'Log' }))

    await waitFor(() => expect(chatterApi.addMessage).toHaveBeenCalled())
    expect(vi.mocked(chatterApi.addMessage).mock.calls[0][2]).toEqual({ body: 'just for us', mode: 'note' })
  })

  it('sends "Message customer" with the chosen channel', async () => {
    await openMessages()

    fireEvent.click(screen.getByRole('button', { name: 'Message customer' }))
    fireEvent.change(screen.getByLabelText('Channel'), { target: { value: 'whatsapp' } })
    fireEvent.change(screen.getByPlaceholderText(/customer/i), { target: { value: 'Hello there' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send' }))

    await waitFor(() => expect(chatterApi.addMessage).toHaveBeenCalled())
    expect(vi.mocked(chatterApi.addMessage).mock.calls[0][2]).toEqual({
      body: 'Hello there',
      mode: 'customer',
      channel: 'whatsapp',
    })
  })
})
