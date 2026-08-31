import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'

export type DocumentTextLineType = 'note' | 'section'

export function DocumentTextLineRow({ type, text, colSpan, onChange, onDelete, onDragStart, onDrop }: { type: DocumentTextLineType; text: string; colSpan: number; onChange: (text: string) => void; onDelete: () => void; onDragStart: () => void; onDrop: () => void }) {
  return <td colSpan={colSpan} className={type === 'section' ? 'bg-muted/60 px-3 py-3' : 'bg-muted/30 px-3 py-3'} onDragOver={(event) => event.preventDefault()} onDrop={onDrop}>
    <div className="flex items-center gap-2">
      <span draggable onDragStart={onDragStart} className="cursor-grab active:cursor-grabbing" title="Drag to reorder"><Icon icon="solar:hamburger-menu-linear" className="h-5 w-5 text-muted-foreground" /></span>
      <input className={type === 'section' ? 'flex-1 border-0 bg-transparent p-2 font-semibold outline-none' : 'flex-1 border-0 bg-transparent p-2 italic text-muted-foreground outline-none'} placeholder={type === 'section' ? 'Section title' : 'Note text'} value={text} onChange={(event) => onChange(event.target.value)} />
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
