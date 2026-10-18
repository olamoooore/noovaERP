// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AccountingEntityManagementPage from './AccountingEntityManagementPage'

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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const DATA = {
  groups: [{ id: 1, name: 'Group-1', code: 'GP-001', base_currency: 'SGD' }],
  entities: [{ id: 2, name: 'Acme', code: 'ENT-001', currency_code: 'SGD', country_code: 'SG' }],
  branches: [] as unknown[],
  books: [{ id: 3, name: 'Acme Entity Book', currency_code: 'SGD', book_type: 'general' }],
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={qc}>
      <AccountingEntityManagementPage embedded />
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/accounting/groups') return { data: DATA.groups }
    if (url === '/accounting/legal-entities') return { data: DATA.entities }
    if (url === '/accounting/branches') return { data: DATA.branches }
    if (url === '/accounting/entity-books') return { data: DATA.books }
    throw new Error(`unexpected GET ${url}`)
  })
})

afterEach(cleanup)

describe('AccountingEntityManagementPage (redesigned entity cards)', () => {
  it('renders all four sections with their descriptions and one Create action each', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Group-1')).toBeTruthy())

    expect(screen.getByText('Groups')).toBeTruthy()
    expect(screen.getByText('Top-level groupings used to consolidate entities.')).toBeTruthy()
    expect(screen.getByText('Legal Entities')).toBeTruthy()
    expect(screen.getByText('Companies that keep their own books.')).toBeTruthy()
    expect(screen.getByText('Branches')).toBeTruthy()
    expect(screen.getByText('Operating locations inside a legal entity.')).toBeTruthy()
    expect(screen.getByText('Entity Books')).toBeTruthy()
    expect(screen.getByText('Books of account kept per entity.')).toBeTruthy()

    expect(screen.getAllByRole('button', { name: /create/i })).toHaveLength(4)
  })

  it('renders each record with its metadata as separate badges', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Group-1')).toBeTruthy())

    expect(screen.getByText('Acme')).toBeTruthy()
    expect(screen.getByText('Acme Entity Book')).toBeTruthy()

    expect(screen.getByText('Code: GP-001')).toBeTruthy()
    expect(screen.getByText('Code: ENT-001')).toBeTruthy()
    expect(screen.getByText('Country: SG')).toBeTruthy()
    expect(screen.getByText('Type: general')).toBeTruthy()
    expect(screen.getAllByText('Currency: SGD').length).toBe(3)
  })

  it('falls back to a friendly empty state and a zero count', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('No branches configured.')).toBeTruthy())

    // three populated sections (1 each) and one empty (0)
    expect(screen.getAllByText('1').length).toBeGreaterThanOrEqual(3)
    expect(screen.getByText('0')).toBeTruthy()
  })

  it('opens the matching create dialog from a section', async () => {
    renderPage()
    await waitFor(() => expect(screen.getByText('Group-1')).toBeTruthy())

    fireEvent.click(screen.getAllByRole('button', { name: /create/i })[0])
    await waitFor(() => expect(screen.getByText('Create Group')).toBeTruthy())
  })
})
