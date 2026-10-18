// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { EditorShell } from './EditorShell'
import { useTemplateEditorStore } from '@/store/templateEditorStore'

/**
 * Issue #620 (TPL-UX3): the editor preview offers recent real documents of
 * the template's type ("Preview with INV-2026-0412") and renders the draft
 * against the chosen one.
 */

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const TEMPLATE = {
  id: 'tpl-1',
  name: 'Invoice Layout',
  document_type: 'invoice',
  mode: 'block',
  status: 'draft',
  published_version: 0,
  format: 'A4',
  orientation: 'portrait',
  default_font: 'DejaVu Sans',
  css: null,
  metadata: null,
  layout_config: { blocks: [{ id: 'b1', type: 'text', content: '{{ invoice.number }}' }] },
}

beforeEach(() => {
  useTemplateEditorStore.getState().reset()
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/template-builder/templates/tpl-1') return { data: { data: TEMPLATE } }
    if (url === '/template-builder/templates/tpl-1/preview-documents') {
      return {
        data: {
          document_type: 'invoice',
          data: [{ id: '412', number: 'INV-2026-0412', date: '2026-09-30', label: 'INV-2026-0412 · 2026-09-30' }],
        },
      }
    }
    return { data: { data: [] } }
  })
  apiPost.mockResolvedValue({ data: { html: '<p>rendered</p>', warnings: [] } })
})

afterEach(() => cleanup())

function renderShell() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <EditorShell templateId="tpl-1" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('EditorShell — preview with a real document (#620)', () => {
  it('lists recent documents and renders the draft against the selected one', async () => {
    renderShell()

    fireEvent.click(await screen.findByRole('button', { name: /preview/i }))

    const picker = (await screen.findByLabelText('Preview document')) as HTMLSelectElement
    await waitFor(() => expect(screen.getByRole('option', { name: 'INV-2026-0412 · 2026-09-30' })).toBeTruthy())

    fireEvent.change(picker, { target: { value: '412' } })

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith(
        '/template-builder/templates/tpl-1/preview',
        expect.objectContaining({ document_id: '412', document_type: 'invoice' }),
      ),
    )
    expect(await screen.findByText('Preview with INV-2026-0412')).toBeTruthy()
  })

  it('keeps sample data when no document is chosen', async () => {
    renderShell()

    fireEvent.click(await screen.findByRole('button', { name: /preview/i }))
    fireEvent.click(await screen.findByRole('button', { name: /refresh/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    const payload = apiPost.mock.calls[0][1] as Record<string, unknown>
    expect(payload).not.toHaveProperty('document_id')
  })
})
