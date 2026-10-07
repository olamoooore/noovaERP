import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { RichTextEditor } from '@/components/ui/RichTextEditor'

export type DocumentTextLineType = 'note' | 'section'

export type DocumentLine = { line_type?: 'product' | 'note' | 'section' }

/** Sum product rows after a section, stopping at the next section. Notes remain part of the section. */
export function getDocumentSectionTotal<T extends DocumentLine>(lines: T[], sectionIndex: number, getLineTotal: (line: T) => number): number {
  if (lines[sectionIndex]?.line_type !== 'section') return 0

  let total = 0
  for (let index = sectionIndex + 1; index < lines.length; index += 1) {
    const line = lines[index]
    if (line.line_type === 'section') break
    if ((line.line_type ?? 'product') === 'product') total += getLineTotal(line)
  }
  return total
}

export function DocumentTextLineRow({ type, text, html, colSpan, total, onChange, onHtmlChange, onDelete, onDragStart, onDrop }: { type: DocumentTextLineType; text: string; html?: string | null; colSpan: number; total?: string; onChange: (text: string) => void; onHtmlChange?: (html: string) => void; onDelete: () => void; onDragStart: () => void; onDrop: () => void }) {
  return <td colSpan={colSpan} className={type === 'section' ? 'bg-muted/60 px-3 py-3' : 'bg-muted/30 px-3 py-3'} onDragOver={(event) => { event.preventDefault(); event.stopPropagation() }} onDrop={(event) => { event.preventDefault(); event.stopPropagation(); onDrop() }}>
    <div className="flex items-center gap-2">
      <span draggable onDragStart={onDragStart} className="cursor-grab active:cursor-grabbing" title="Drag to reorder"><Icon icon="solar:hamburger-menu-linear" className="h-5 w-5 text-muted-foreground" /></span>
      {/* #1004: rich text (bold/italic/underline/colour) for section titles and notes. */}
      {onHtmlChange ? (
        <RichTextEditor
          compact
          value={html ?? ''}
          onChange={onHtmlChange}
          placeholder={type === 'section' ? 'Section title' : 'Note text'}
          className={type === 'section' ? 'flex-1 font-semibold' : 'flex-1 italic text-muted-foreground'}
        />
      ) : (
        <input className={type === 'section' ? 'flex-1 border-0 bg-transparent p-2 font-semibold outline-none' : 'flex-1 border-0 bg-transparent p-2 italic text-muted-foreground outline-none'} placeholder={type === 'section' ? 'Section title' : 'Note text'} value={text} onChange={(event) => onChange(event.target.value)} />
      )}
      {type === 'section' && total !== undefined && <span className="whitespace-nowrap px-2 text-right font-semibold tabular-nums">{total}</span>}
      <Button type="button" variant="ghost" size="sm" onClick={onDelete}><Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" /></Button>
    </div>
  </td>
}

export function reorderDocumentLines<T>(lines: T[], from: number, to: number): T[] {
  const next = [...lines]
  const [line] = next.splice(from, 1)
  next.splice(to, 0, line)
  return next
}
