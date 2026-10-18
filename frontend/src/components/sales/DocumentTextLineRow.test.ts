import { describe, expect, it } from 'vitest'
import { getDocumentSectionTotal, reorderDocumentLines } from './DocumentTextLineRow'

type Line = { line_type?: 'product' | 'note' | 'section'; amount: number }

describe('getDocumentSectionTotal', () => {
  const total = (line: Line) => line.amount

  it('sums products until the next section and skips notes', () => {
    const lines: Line[] = [
      { line_type: 'section', amount: 0 },
      { line_type: 'product', amount: 100 },
      { line_type: 'note', amount: 999 },
      { line_type: 'product', amount: 100 },
      { line_type: 'section', amount: 0 },
      { line_type: 'product', amount: 250 },
    ]

    expect(getDocumentSectionTotal(lines, 0, total)).toBe(200)
    expect(getDocumentSectionTotal(lines, 4, total)).toBe(250)
  })

  it('does not total an ordinary product row', () => {
    expect(getDocumentSectionTotal([{ line_type: 'product', amount: 100 }], 0, total)).toBe(0)
  })

  it('moves section and note rows between product rows', () => {
    const lines = ['Section', 'Note', 'Product 1', 'Product 2']

    expect(reorderDocumentLines(lines, 0, 3)).toEqual(['Note', 'Product 1', 'Product 2', 'Section'])
    expect(reorderDocumentLines(lines, 1, 3)).toEqual(['Section', 'Product 1', 'Product 2', 'Note'])
  })
})
