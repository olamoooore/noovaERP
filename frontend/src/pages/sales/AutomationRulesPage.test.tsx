// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AutomationRulesPage } from './AutomationRulesPage'

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

const { apiGet, apiPost, apiPut, apiDelete } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  apiDelete: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: apiPut, delete: apiDelete },
}))

const RULE = {
  id: 1,
  name: 'Overdue chaser',
  event_name: 'invoice.overdue.v1',
  legal_entity_id: 3,
  conditions: [{ field: 'days_overdue', operator: 'gte', value: 30 }],
  actions: [
    { type: 'notify', user_ids: [7], roles: [], account_owner: true },
    { type: 'hold_customer', reason: '30+ days' },
  ],
  is_active: true,
  version: 2,
  updated_at: '2026-10-05T10:00:00Z',
}

const CATALOG = {
  events: [
    {
      event_name: 'invoice.posted.v1',
      version: '1.0.0',
      description: 'Invoice posted',
      payload_fields: { event_id: 'uuid', total: 'decimal:2' },
      condition_fields: ['event_id', 'total', 'customer_group', 'days_overdue'],
    },
    {
      event_name: 'invoice.overdue.v1',
      version: '1.0.0',
      description: 'Invoice overdue',
      payload_fields: { event_id: 'uuid', total: 'decimal:2', due_date: 'date|null' },
      condition_fields: ['event_id', 'total', 'days_overdue'],
    },
  ],
  operators: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'contains', 'is_null', 'is_not_null'],
  action_types: ['notify', 'whatsapp_customer', 'create_task', 'hold_customer', 'webhook'],
  virtual_fields: { customer_group: 'Customer group', days_overdue: 'Days overdue' },
}

const EXECUTIONS = {
  current_page: 1,
  data: [
    {
      id: 11,
      rule_id: 1,
      rule: { id: 1, name: 'Overdue chaser' },
      event_name: 'invoice.overdue.v1',
      status: 'partial',
      actions: [
        { type: 'notify', status: 'success' },
        { type: 'webhook', status: 'failed', error: 'HTTP 500' },
      ],
      error: 'webhook: HTTP 500',
      created_at: '2026-10-05T10:05:00Z',
    },
  ],
  total: 1,
}

const DRY_RUN = {
  rule_id: 1,
  rule_version: 2,
  event_name: 'invoice.overdue.v1',
  matched: true,
  conditions: [
    { index: 0, field: 'days_overdue', operator: 'gte', expected: 30, actual: 45, matched: true },
  ],
  actions: [
    { type: 'hold_customer', would_execute: true, description: 'Put the customer on hold.' },
  ],
  event: { document_number: 'INV-42' },
  side_effects: false,
}

const VERSIONS = [
  {
    id: 2,
    version: 2,
    name: 'Overdue chaser',
    event_name: 'invoice.overdue.v1',
    conditions: RULE.conditions,
    actions: RULE.actions,
    is_active: true,
    created_at: '2026-10-05T10:00:00Z',
  },
  {
    id: 1,
    version: 1,
    name: 'Overdue chaser',
    event_name: 'invoice.overdue.v1',
    conditions: [],
    actions: [{ type: 'create_task', summary: 'Call' }],
    is_active: true,
    created_at: '2026-10-04T10:00:00Z',
  },
]

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiPut.mockReset()
  apiDelete.mockReset()

  apiGet.mockImplementation(async (url: string) => {
    if (url === '/sales/automation/catalog') return { data: { data: CATALOG } }
    if (url === '/sales/automation/rules') return { data: { data: [RULE] } }
    if (url === '/sales/automation/executions') return { data: EXECUTIONS }
    if (url.includes('/versions')) return { data: { data: VERSIONS } }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async () => ({ data: { data: DRY_RUN } }))
  apiPut.mockImplementation(async () => ({ data: { data: RULE } }))
})

afterEach(cleanup)

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <AutomationRulesPage />
    </QueryClientProvider>,
  )
}

describe('AutomationRulesPage (#913)', () => {
  it('lists rules and shows the execution log with per-action status', async () => {
    renderPage()

    expect(await screen.findByText('Overdue chaser')).toBeTruthy()
    expect(screen.getByText('invoice.overdue.v1')).toBeTruthy()
    expect(screen.getByText('days_overdue gte 30')).toBeTruthy()

    fireEvent.mouseDown(screen.getByRole('tab', { name: /execution log/i }))

    expect(await screen.findByText('partial')).toBeTruthy()
    expect(screen.getByText('webhook: HTTP 500')).toBeTruthy()
  })

  it('builds a rule with condition and action rows and saves it', async () => {
    renderPage()

    const newRule = await screen.findByRole('button', { name: /new rule/i })
    await waitFor(() => expect((newRule as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(newRule)

    const nameInput = await screen.findByLabelText('Rule name')
    fireEvent.change(nameInput, { target: { value: 'Rule from UI' } })

    fireEvent.click(screen.getByRole('button', { name: /add condition/i }))
    fireEvent.change(screen.getByLabelText('Condition value 1'), { target: { value: 'INV-1' } })

    fireEvent.change(screen.getByLabelText('Task summary 1'), { target: { value: 'Collect now' } })

    fireEvent.click(screen.getByRole('button', { name: /create rule/i }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith(
        '/sales/automation/rules',
        expect.objectContaining({
          name: 'Rule from UI',
          event_name: 'invoice.posted.v1',
          conditions: [{ field: 'event_id', operator: 'eq', value: 'INV-1' }],
          actions: [expect.objectContaining({ type: 'create_task', summary: 'Collect now' })],
        }),
      )
    })
  })

  it('dry-runs a rule and shows the condition evaluation and action preview', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /dry run/i }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith('/sales/automation/rules/1/dry-run', {})
    })

    expect(await screen.findByText('Matched')).toBeTruthy()
    expect(screen.getByText(/no side effects/i)).toBeTruthy()
    expect(screen.getByText('45')).toBeTruthy()
    expect(screen.getByText('Put the customer on hold.')).toBeTruthy()
  })

  it('shows version history snapshots', async () => {
    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /history/i }))

    expect(await screen.findByTestId('automation-version-2')).toBeTruthy()
    expect(screen.getByTestId('automation-version-1')).toBeTruthy()
  })
})
