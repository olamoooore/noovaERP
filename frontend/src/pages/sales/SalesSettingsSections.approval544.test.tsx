// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ApprovalSettings } from './SalesSettingsSections'

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

function settingsPayload(enforcement: Record<string, unknown> | undefined, defaultRequiresApproval = true) {
  return {
    data: {
      data: {
        invoice: {
          default_requires_approval: defaultRequiresApproval,
          approval_expiry_days: 7,
          reviewer_user_ids: [],
          reviewer_roles: [],
          available_reviewers: [],
          ...(enforcement ? { approval_enforcement: enforcement } : {}),
        },
        delivery_order: { default_requires_approval: false },
      },
    },
  }
}

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
})

afterEach(cleanup)

function renderSection() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <ApprovalSettings />
    </QueryClientProvider>,
  )
}

describe('ApprovalSettings posting-approval badge (#544)', () => {
  it('says "Enforced for all invoices" only when the server reports it', async () => {
    apiGet.mockImplementation(async () =>
      settingsPayload({ mode: 'all_invoices', policy_enabled: true, drafts_without_approval: 0 }),
    )

    renderSection()

    expect(await screen.findByText('Enforced for all invoices')).toBeTruthy()
    expect(screen.queryByText(/^Enforced$/)).toBeNull()
  })

  it('reports per-document enforcement with the number of drafts that bypass approval', async () => {
    apiGet.mockImplementation(async () =>
      settingsPayload({ mode: 'per_document', policy_enabled: true, drafts_without_approval: 3 }),
    )

    renderSection()

    expect(await screen.findByText('Per document — 3 drafts have approval switched off')).toBeTruthy()
    expect(screen.queryByText(/Enforced/)).toBeNull()
  })

  it('reports per-document enforcement when the tenant policy is off', async () => {
    apiGet.mockImplementation(async () =>
      settingsPayload({ mode: 'per_document', policy_enabled: false, drafts_without_approval: 0 }, false),
    )

    renderSection()

    expect(await screen.findByText('Per document — only invoices flagged “requires approval”')).toBeTruthy()
    expect(screen.queryByText(/Enforced/)).toBeNull()
  })

  it('never claims enforcement when the server did not report a state', async () => {
    apiGet.mockImplementation(async () => settingsPayload(undefined))

    renderSection()

    expect(await screen.findByText('Require approval by default')).toBeTruthy()
    expect(screen.queryByText(/Enforced/)).toBeNull()
  })
})
