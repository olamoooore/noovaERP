// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import CreateVendorDebitNoteDialog from './CreateVendorDebitNoteDialog'
import CreateVendorCreditNoteDialog from './CreateVendorCreditNoteDialog'
import CreateCreditNoteDialog from './CreateCreditNoteDialog'

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
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
  },
}))
vi.mock('@/hooks/useVendorDebitNotes', () => ({
  useCreateVendorDebitNote: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))
vi.mock('@/hooks/useVendorCreditNotes', () => ({
  useCreateVendorCreditNote: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))
vi.mock('@/hooks/useCustomerCreditNotes', () => ({
  useCreateCreditNote: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// palette classes the hand-rolled dialogs used before this fix
const HARDCODED = [
  'bg-white',
  'bg-black',
  'bg-opacity-50',
  'bg-gray-50',
  'text-gray-900',
  'text-gray-700',
  'text-gray-600',
  'text-gray-500',
  'border-gray-300',
  'border-gray-200',
  'bg-blue-600',
  'text-blue-600',
  'bg-red-50',
  'text-red-700',
]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async () => ({ data: { data: [] } }))
})

afterEach(cleanup)

function renderDialog(node: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={qc}>{node}</QueryClientProvider>)
}

function expectSharedThemedDialog(container: HTMLElement) {
  const dialog = screen.getByRole('dialog')

  // 1. Portaled out of the page → the overlay can cover the whole viewport
  //    instead of being clipped to a page container.
  expect(container.contains(dialog)).toBe(false)
  expect(document.body.contains(dialog)).toBe(true)

  // 2. The shared overlay is what dims the page…
  expect(document.querySelector('.backdrop-blur-sm')).toBeTruthy()
  // …and the hand-rolled half-screen black overlay is gone
  expect(document.querySelector('.bg-opacity-50')).toBeNull()

  // 3. No hardcoded palette colours left in the dialog markup
  const html = dialog.outerHTML
  for (const cls of HARDCODED) {
    expect(html, `dialog should not use ${cls}`).not.toContain(cls)
  }
}

describe('debit / credit note dialogs', () => {
  it('Create Debit Note renders as a portaled, themed dialog', async () => {
    const { container } = renderDialog(
      <CreateVendorDebitNoteDialog onClose={() => {}} onSuccess={() => {}} />,
    )
    await waitFor(() => expect(screen.getByRole('dialog')).toBeTruthy())
    expect(screen.getByText('Create a debit note for a vendor bill')).toBeTruthy()
    expectSharedThemedDialog(container)
  })

  it('Create Credit Note (vendor) renders as a portaled, themed dialog', async () => {
    const { container } = renderDialog(
      <CreateVendorCreditNoteDialog onClose={() => {}} onSuccess={() => {}} />,
    )
    await waitFor(() => expect(screen.getByRole('dialog')).toBeTruthy())
    expect(screen.getByText('Create a credit note for a vendor bill')).toBeTruthy()
    expectSharedThemedDialog(container)
  })

  it('Create Customer Credit Note renders as a portaled, themed dialog', async () => {
    const { container } = renderDialog(
      <CreateCreditNoteDialog onClose={() => {}} onSuccess={() => {}} />,
    )
    await waitFor(() => expect(screen.getByRole('dialog')).toBeTruthy())
    expect(screen.getByText('Create a credit note for a customer invoice')).toBeTruthy()
    expectSharedThemedDialog(container)
  })
})
