// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import VendorDebitNotesPage from './VendorDebitNotesPage'

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

vi.mock('@/hooks/useVendorDebitNotes', () => ({
  useVendorDebitNotes: () => ({ data: { data: [], meta: {} }, isLoading: false, error: null }),
  useDeleteVendorDebitNote: () => ({ mutate: vi.fn() }),
}))
vi.mock('./components/CreateVendorDebitNoteDialog', () => ({ default: () => null }))
vi.mock('./components/EditVendorDebitNoteDialog', () => ({ default: () => null }))
vi.mock('./components/VendorDebitNotePostButton', () => ({ default: () => null }))

function commonAncestor(a: Element, b: Element): Element | null {
  let node: Element | null = a.parentElement
  while (node && !node.contains(b)) node = node.parentElement
  return node
}

beforeEach(() => {
  localStorage.clear()
})

afterEach(cleanup)

describe('VendorDebitNotesPage toolbar', () => {
  it('keeps search, status filter and the New Debit Note action in one row', async () => {
    render(
      <MemoryRouter>
        <VendorDebitNotesPage />
      </MemoryRouter>,
    )

    await waitFor(() =>
      expect(
        screen.getByPlaceholderText(/search by debit note number or vendor/i),
      ).toBeTruthy(),
    )

    const search = screen.getByPlaceholderText(/search by debit note number or vendor/i)
    const status = screen.getByRole('combobox', { name: /filter by status/i })
    const create = screen.getByRole('button', { name: /new debit note/i })

    const container = commonAncestor(search, create)
    expect(container).toBeTruthy()
    // the status filter shares that same action container
    expect(container!.contains(status)).toBe(true)
    // …and it is the action container, not the page header (which owns the h1)
    expect(container!.querySelector('h1')).toBeNull()
    // no leftover standalone filter card between title and table
    expect(container!.closest('.rounded-xl')).toBeNull()
  })
})
