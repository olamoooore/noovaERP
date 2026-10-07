// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CustomerSearchCombobox } from './CustomerSearchCombobox'

if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false, media: query, onchange: null,
    addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {}, dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} }
}
if (!HTMLElement.prototype.scrollIntoView) HTMLElement.prototype.scrollIntoView = () => {}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet } }))

beforeEach(() => {
  apiGet.mockReset()
})
afterEach(cleanup)

function renderPicker(onValueChange: (...args: any[]) => void = () => {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <CustomerSearchCombobox value="" onValueChange={onValueChange} />
    </QueryClientProvider>,
  )
}

describe('CustomerSearchCombobox', () => {
  it('#1002: a refused search (403) shows a load error, not "No customers found."', async () => {
    apiGet.mockImplementation(async () => {
      throw Object.assign(new Error('Request failed with status code 403'), {
        response: { status: 403, data: { message: 'Forbidden' } },
      })
    })

    renderPicker()
    fireEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByText(/Couldn't load customers — check your access or try again\./)).toBeTruthy()
    expect(screen.getByText(/403/)).toBeTruthy()
    expect(screen.queryByText('No customers found.')).toBeNull()
  })

  it('#1002: an empty successful search still says "No customers found."', async () => {
    apiGet.mockImplementation(async () => ({ data: { data: [] } }))

    renderPicker()
    fireEvent.click(screen.getByRole('combobox'))

    expect(await screen.findByText('No customers found.')).toBeTruthy()
  })

  it('#1007: picking a customer matched through one contact person passes that contact id', async () => {
    apiGet.mockImplementation(async () => ({
      data: {
        data: [
          {
            id: 60,
            name: 'Northpoint Medical Group',
            matched_contacts: ['Dr Elisa Koh'],
            matched_contact_ids: [601],
          },
        ],
      },
    }))
    const onValueChange = vi.fn()

    renderPicker(onValueChange)
    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.click(await screen.findByText('Northpoint Medical Group'))

    expect(onValueChange).toHaveBeenCalledWith(
      '60',
      expect.objectContaining({ id: 60 }),
      '601',
    )
  })
})
