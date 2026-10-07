// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProductSearchCombobox } from './ProductSearchCombobox'

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
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const inlineProducts = Array.from({ length: 15 }, (_, index) => ({
  id: index + 1,
  name: index === 0 ? 'Disposable Hysteroscope' : `Product ${index + 1}`,
  sku: `SKU-${index + 1}`,
  short_description: index === 0 ? 'HTx60' : `Model-${index + 1}`,
  stock_quantity: 5,
}))

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockImplementation(async (url: string, config?: { params?: Record<string, any> }) => {
    if (url === '/products') {
      return { data: { data: inlineProducts, total: 42, last_page: 3, current_page: config?.params?.page ?? 1 } }
    }
    if (url.startsWith('/products/')) return { data: { data: inlineProducts[0] } }
    if (url === '/brands') return { data: { data: [] } }
    if (url === '/product-categories') return { data: [] }
    return { data: { data: [] } }
  })
})

afterEach(cleanup)

function renderPicker(onValueChange = vi.fn(), activeOnly?: boolean) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <ProductSearchCombobox value="" onValueChange={onValueChange} activeOnly={activeOnly} />
    </QueryClientProvider>,
  )
  return onValueChange
}

describe('ProductSearchCombobox (#1003)', () => {
  it('caps the inline list at 15 and renders name + short description', async () => {
    renderPicker()

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.change(screen.getByPlaceholderText('Search name, SKU, barcode, lot or serial...'), {
      target: { value: 'HTx60' },
    })

    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/products', { params: expect.objectContaining({ per_page: 15, search: 'HTx60' }) }))
    expect(await screen.findByText('Disposable Hysteroscope')).toBeTruthy()
    // Short description is rendered in the row (not name + SKU only).
    expect(screen.getAllByText('HTx60').length).toBeGreaterThan(0)
  })

  it('offers "Show more" when more than 15 match, keeps the search text, and picks from the catalog', async () => {
    const onValueChange = renderPicker()

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.change(screen.getByPlaceholderText('Search name, SKU, barcode, lot or serial...'), {
      target: { value: 'HTx60' },
    })

    const showMore = await screen.findByText(/Show more \(42 results\)/, {}, { timeout: 3000 })
    fireEvent.click(showMore)

    const dialog = await screen.findByRole('dialog')
    const catalogSearch = screen.getByPlaceholderText(/Search name, short description, SKU, item code/) as HTMLInputElement
    // The typed search text follows into the catalog picker.
    await waitFor(() => expect(catalogSearch.value).toBe('HTx60'))

    const selectButtons = await screen.findAllByRole('button', { name: 'Select' })
    fireEvent.click(selectButtons[0])

    await waitFor(() => expect(onValueChange).toHaveBeenCalledWith('1', expect.objectContaining({ id: 1 })))
    expect(dialog).toBeTruthy()
  })

  it('lists active products only by default, in the inline list and the catalog', async () => {
    renderPicker()

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.change(screen.getByPlaceholderText('Search name, SKU, barcode, lot or serial...'), {
      target: { value: 'HTx60' },
    })
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/products', { params: expect.objectContaining({ search: 'HTx60', is_active: 'true' }) }))

    fireEvent.click(await screen.findByText(/Show more \(42 results\)/, {}, { timeout: 3000 }))
    await screen.findByRole('dialog')
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/products', { params: expect.objectContaining({ page: 1, is_active: 'true' }) }))
  })

  it('keeps inactive products findable when activeOnly is false (report filters)', async () => {
    renderPicker(vi.fn(), false)

    fireEvent.click(screen.getByRole('combobox'))
    fireEvent.change(screen.getByPlaceholderText('Search name, SKU, barcode, lot or serial...'), {
      target: { value: 'HTx60' },
    })
    await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/products', { params: expect.objectContaining({ search: 'HTx60' }) }))
    for (const [, config] of apiGet.mock.calls.filter(([url]) => url === '/products')) {
      expect(config?.params?.is_active).toBeUndefined()
    }
  })
})
