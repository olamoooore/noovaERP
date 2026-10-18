import { describe, expect, it } from 'vitest'
import {
  focLineKeyForId,
  focPayloadFields,
  keepFocLinesUnderSource,
  moveSalesDocumentLineWithFoc,
  removeSalesDocumentLineWithFoc,
} from './salesDocumentLines'

/**
 * #1006: the invoice editor keeps a bonus FOC line's flag and its link to the
 * paid source line through reorders, deletes and the save payload.
 */
type Line = { description: string; is_foc?: boolean; line_key?: string; source_line_key?: string | null }

const paid = (id: number, description: string): Line => ({ description, is_foc: false, line_key: focLineKeyForId(id), source_line_key: null })
const foc = (id: number, sourceId: number, description: string): Line => ({
  description,
  is_foc: true,
  line_key: focLineKeyForId(id),
  source_line_key: focLineKeyForId(sourceId),
})

const names = (lines: Line[]) => lines.map((line) => line.description)

describe('#1006 FOC line pairing in the invoice editor', () => {
  it('sends is_foc and the index of the paid source line within the submitted lines', () => {
    const submitted = [paid(1, 'Other'), paid(2, 'Paid A'), foc(3, 2, 'FOC A'), paid(4, 'Paid B')]

    expect(submitted.map((line) => focPayloadFields(submitted, line))).toEqual([
      {},
      {},
      { is_foc: true, source_line_index: 1 },
      {},
    ])
  })

  it('still flags a FOC line whose source is not sent (the server then rejects or pairs it)', () => {
    const submitted = [foc(3, 99, 'FOC orphan')]
    expect(focPayloadFields(submitted, submitted[0])).toEqual({ is_foc: true })
  })

  it('deleting a paid line deletes its FOC line(s) too', () => {
    const lines = [paid(1, 'Other'), paid(2, 'Paid A'), foc(3, 2, 'FOC A'), paid(4, 'Paid B'), foc(5, 4, 'FOC B')]

    expect(names(removeSalesDocumentLineWithFoc(lines, 1))).toEqual(['Other', 'Paid B', 'FOC B'])
    // Deleting the FOC line alone keeps the paid line.
    expect(names(removeSalesDocumentLineWithFoc(lines, 2))).toEqual(['Other', 'Paid A', 'Paid B', 'FOC B'])
  })

  it('drag reorder keeps each FOC line directly under its paid line', () => {
    // Paid A dragged to the end: its FOC line was left behind.
    const dragged = [paid(1, 'Other'), foc(3, 2, 'FOC A'), paid(4, 'Paid B'), paid(2, 'Paid A')]

    expect(names(keepFocLinesUnderSource(dragged))).toEqual(['Other', 'Paid B', 'Paid A', 'FOC A'])
  })

  it('moving up/down moves the paid line together with its FOC line', () => {
    const lines = [paid(1, 'Other'), paid(2, 'Paid A'), foc(3, 2, 'FOC A'), paid(4, 'Paid B')]

    expect(names(moveSalesDocumentLineWithFoc(lines, 1, 'down'))).toEqual(['Other', 'Paid B', 'Paid A', 'FOC A'])
    expect(names(moveSalesDocumentLineWithFoc(lines, 1, 'up'))).toEqual(['Paid A', 'FOC A', 'Other', 'Paid B'])
    // Moving the FOC line moves its whole block.
    expect(names(moveSalesDocumentLineWithFoc(lines, 2, 'up'))).toEqual(['Paid A', 'FOC A', 'Other', 'Paid B'])
  })
})
