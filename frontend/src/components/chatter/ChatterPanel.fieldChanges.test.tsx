// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ChatterPanel } from './ChatterPanel'

/**
 * Issue #634 (CHAT-UX1) — an edit's chatter History entry lists every tracked
 * field as "field: old → new" and groups line changes per line, naming the
 * user who saved.
 */
vi.mock('@/lib/chatterApi', () => {
  const logs = [
    {
      id: 11,
      event: 'updated',
      description: 'Updated quotation QT-0001',
      created_at: '2026-10-05T10:00:00Z',
      actor: { id: 1, name: 'Tracy Tracker' },
      properties: {
        fields: 'customer_id, valid_until',
        lines: 'line items replaced',
        changes: [
          { field: 'customer_id', label: 'Customer', old: 'Acme Before', new: 'Globex After' },
          { field: 'valid_until', label: 'Valid until', old: '2026-10-31', new: '2026-11-15' },
        ],
        line_changes: [
          {
            line: 2,
            action: 'changed',
            description: 'Tracked Widget A',
            changes: [
              { field: 'quantity', label: 'qty', old: '5', new: '8' },
              { field: 'unit_price', label: 'price', old: '120.00', new: '110.00' },
            ],
            summary: 'Line 2: qty 5 → 8, price 120.00 → 110.00',
          },
          {
            line: 3,
            action: 'added',
            description: 'Tracked Widget B',
            changes: [],
            summary: 'Line 3 added: Tracked Widget B (qty 1, price 50.00)',
          },
        ],
      },
    },
  ]

  const api = {
    list: vi.fn(async () => ({ logs, activities: [], attachments: [], messages: [] })),
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

async function openHistory() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={queryClient}>
      <ChatterPanel subjectType="App\\Models\\Quotation" subjectId={9} />
    </QueryClientProvider>,
  )
  fireEvent.click(screen.getByText('History'))
  await waitFor(() => expect(screen.getByText('Updated quotation QT-0001')).toBeTruthy())
}

describe('ChatterPanel field-change tracking (#634)', () => {
  it('renders header changes as "field: old → new" with the actor', async () => {
    await openHistory()

    const changes = screen.getByRole('list', { name: 'Field changes' })
    const rows = within(changes).getAllByRole('listitem').map((li) => li.textContent?.replace(/\s+/g, ' ').trim())
    expect(rows).toEqual(['Customer: Acme Before → Globex After', 'Valid until: 2026-10-31 → 2026-11-15'])
    expect(screen.getByText('Tracy Tracker')).toBeTruthy()
  })

  it('groups line changes per line', async () => {
    await openHistory()

    const lines = screen.getByRole('list', { name: 'Line changes' })
    const rows = within(lines).getAllByRole('listitem').map((li) => li.textContent?.trim())
    expect(rows).toEqual([
      'Line 2: qty 5 → 8, price 120.00 → 110.00',
      'Line 3 added: Tracked Widget B (qty 1, price 50.00)',
    ])
  })

  it('does not repeat the bare field-name list when the old → new list is present', async () => {
    await openHistory()

    expect(screen.queryByText('customer_id, valid_until')).toBeNull()
    expect(screen.queryByText('line items replaced')).toBeNull()
  })
})
