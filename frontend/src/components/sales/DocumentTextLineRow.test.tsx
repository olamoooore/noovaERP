// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { DocumentTextLineRow } from './DocumentTextLineRow'

afterEach(cleanup)

/**
 * #1005: the shared section/note row rendered `<input-ignore>` (an unknown
 * element), so no text could ever be typed into an invoice note/section line.
 */
describe('DocumentTextLineRow (#1005)', () => {
  it('renders an editable note field and reports typed text', () => {
    const onChange = vi.fn()

    render(
      <table><tbody><tr>
        <DocumentTextLineRow type="note" text="" colSpan={1} onChange={onChange} onDelete={() => {}} onDragStart={() => {}} onDrop={() => {}} />
      </tr></tbody></table>,
    )

    const field = screen.getByPlaceholderText('Note text') as HTMLInputElement
    expect(field.tagName).toBe('INPUT')

    fireEvent.change(field, { target: { value: 'Delivered in two batches' } })
    expect(onChange).toHaveBeenCalledWith('Delivered in two batches')
  })

  it('renders an editable section title field', () => {
    const onChange = vi.fn()

    render(
      <table><tbody><tr>
        <DocumentTextLineRow type="section" text="Consumables" colSpan={1} total="$0.00" onChange={onChange} onDelete={() => {}} onDragStart={() => {}} onDrop={() => {}} />
      </tr></tbody></table>,
    )

    const field = screen.getByPlaceholderText('Section title') as HTMLInputElement
    expect(field.tagName).toBe('INPUT')
    expect(field.value).toBe('Consumables')

    fireEvent.change(field, { target: { value: 'Equipment' } })
    expect(onChange).toHaveBeenCalledWith('Equipment')
  })

  /**
   * The invoice page passes onHtmlChange, so the row renders the TipTap
   * RichTextEditor rather than the <input> fallback. Prove that path is
   * editable: the ProseMirror surface is contenteditable and text typed into
   * it (a DOM text mutation, which ProseMirror reads back as input) reaches
   * onHtmlChange.
   */
  it('renders an editable rich-text section field when onHtmlChange is passed', async () => {
    const onChange = vi.fn()
    const onHtmlChange = vi.fn()

    const { container } = render(
      <table><tbody><tr>
        <DocumentTextLineRow type="section" text="" html="" colSpan={1} total="$0.00" onChange={onChange} onHtmlChange={onHtmlChange} onDelete={() => {}} onDragStart={() => {}} onDrop={() => {}} />
      </tr></tbody></table>,
    )

    const surface = await waitFor(() => {
      const node = container.querySelector('[contenteditable="true"]')
      expect(node).not.toBeNull()
      return node as HTMLElement
    })
    expect(container.querySelector('input[placeholder="Section title"]')).toBeNull()

    const paragraph = surface.querySelector('p') ?? surface
    paragraph.textContent = 'Equipment'
    fireEvent.input(surface)

    await waitFor(() => {
      expect(onHtmlChange).toHaveBeenCalled()
    })
    expect(onHtmlChange.mock.calls.at(-1)?.[0]).toContain('Equipment')
  })
})
