// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
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
})
