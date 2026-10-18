// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProductCatalogDialog } from './ProductCatalogDialog'

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

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))
vi.mock('@/lib/api', () => ({ default: { get: apiGet } }))

const pageOne = [{ id: 1, name: 'Cotton Yarn', sku: 'CY-1', price: '10', category: { name: 'Yarn' } }]
const pageTwo = [{ id: 2, name: 'Silk Thread', sku: 'ST-2', price: '25', category: { name: 'Thread' } }]

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, unknown> }) => {
    if (url === '/product-categories') return { data: { data: [{ id: 3, name: 'Yarn' }] } }
    if (url === '/products') {
      const page = Number(config?.params?.page ?? 1)
      return {
        data: {
          data: page === 2 ? pageTwo : pageOne,
          current_page: page,
          last_page: 2,
          per_page: 50,
          total: 51,
          from: page === 2 ? 51 : 1,
          to: page === 2 ? 51 : 50,
        },
      }
    }
    return { data: { data: [] } }
  })
})
afterEach(cleanup)

const productCalls = () =>
  apiGet.mock.calls
    .filter(([url]) => url === '/products')
    .map(([, config]) => (config?.params ?? {}) as Record<string, unknown>)

function renderDialog(onAdd = vi.fn()) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ProductCatalogDialog onAdd={onAdd} />
    </QueryClientProvider>,
  )
  return onAdd
}

describe('ProductCatalogDialog server-side search (#301)', () => {
  it('fetches nothing until opened, then loads one server page', async () => {
    renderDialog()
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(productCalls()).toHaveLength(0)

    fireEvent.click(screen.getByRole('button', { name: /product catalog/i }))
    expect(await screen.findByText('Cotton Yarn')).toBeTruthy()

    const calls = productCalls()
    expect(calls).toHaveLength(1)
    expect(Number(calls[0].page)).toBe(1)
    expect(Number(calls[0].per_page)).toBeLessThanOrEqual(100)
  })

  it('debounces the search box into one server query', async () => {
    renderDialog()
    fireEvent.click(screen.getByRole('button', { name: /product catalog/i }))
    expect(await screen.findByText('Cotton Yarn')).toBeTruthy()

    const search = screen.getByPlaceholderText('Search products...')
    fireEvent.change(search, { target: { value: 's' } })
    fireEvent.change(search, { target: { value: 'si' } })
    fireEvent.change(search, { target: { value: 'silk' } })

    await waitFor(() => expect(productCalls().some((params) => params.search === 'silk')).toBe(true), { timeout: 2000 })
    const searched = productCalls().filter((params) => params.search)
    expect(searched.map((params) => params.search)).toEqual(['silk'])
  })

  it('pages through the server and keeps picks across pages', async () => {
    const onAdd = renderDialog()
    fireEvent.click(screen.getByRole('button', { name: /product catalog/i }))
    expect(await screen.findByText('Cotton Yarn')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Add one Cotton Yarn' }))

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }))
    expect(await screen.findByText('Silk Thread')).toBeTruthy()
    expect(productCalls().some((params) => Number(params.page) === 2)).toBe(true)
    fireEvent.click(screen.getByRole('button', { name: 'Add one Silk Thread' }))

    fireEvent.click(screen.getByRole('button', { name: /add selected products/i }))
    expect(onAdd).toHaveBeenCalledTimes(1)
    const picked = onAdd.mock.calls[0][0] as Array<{ product: { id: number }; quantity: number }>
    expect(picked.map((item) => [item.product.id, item.quantity])).toEqual([[1, 1], [2, 1]])
  })
})
