import { useState } from 'react'
import { useDrag } from 'react-dnd'
import { ChevronUp, ChevronDown, Copy, Trash2, GripVertical } from 'lucide-react'
import { useTemplateEditorStore } from '@/store/templateEditorStore'
import type { Block, CaseInformationBlock, ContainerBlock, ColumnsBlock, TableBlock, TotalsBlock } from '@/types/template-builder'
import { DND, type BlockDragItem } from './dnd'
import { cn } from '@/lib/utils'

const TYPE_LABELS: Record<string, string> = {
  text: 'Text',
  image: 'Image',
  columns: 'Columns',
  table: 'Line Items',
  case_information: 'Case Information',
  totals: 'Totals',
  spacer: 'Spacer',
  divider: 'Divider',
  header: 'Header',
  footer: 'Footer',
  conditional: 'Conditional',
}

function tokenToHtml(html: string): string {
  // Highlight merge-field tokens visually in the editor only.
  return html.replace(/\{\{([^}]+)\}\}/g, (_m, inner) => `<span class="rounded bg-primary/10 px-1 text-primary">{{${inner}}}</span>`)
}

export function BlockNode({ block }: { block: Block }) {
  const selectedId = useTemplateEditorStore((s) => s.selectedBlockId)
  const selectBlock = useTemplateEditorStore((s) => s.selectBlock)
  const moveBlock = useTemplateEditorStore((s) => s.moveBlock)
  const duplicateBlock = useTemplateEditorStore((s) => s.duplicateBlock)
  const removeBlock = useTemplateEditorStore((s) => s.removeBlock)

  const [hovered, setHovered] = useState(false)
  const selected = selectedId === block.id

  const [{ isDragging }, dragRef] = useDrag<BlockDragItem, unknown, { isDragging: boolean }>(() => ({
    type: DND.BLOCK,
    item: { id: block.id },
    collect: (m) => ({ isDragging: m.isDragging() }),
  }))

  const stop = (e: React.MouseEvent) => {
    e.stopPropagation()
    selectBlock(block.id)
  }

  const renderInner = (): React.ReactNode => {
    switch (block.type) {
      case 'text':
        return (
          <div
            className="text-[11px] leading-snug"
            style={{ textAlign: block.align, fontSize: block.size, color: block.color, fontWeight: block.bold ? 700 : undefined }}
            dangerouslySetInnerHTML={{ __html: tokenToHtml(block.content || '<span class="text-muted-foreground">Empty text</span>') }}
          />
        )
      case 'image': {
        const url = block.url || ''
        const isToken = url.includes('{{')
        return (
          <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
            {!isToken && url ? (
              <img src={url} alt="" className="h-10 w-10 rounded border object-contain" />
            ) : (
              <div className="flex h-10 w-10 items-center justify-center rounded border bg-muted text-[8px]">IMG</div>
            )}
            <code className="truncate">{url || '(no url)'}</code>
          </div>
        )
      }
      case 'columns': {
        const c = block as ColumnsBlock
        return c.children.length ? (
          <div className="flex gap-2">
            {c.children.map((child) => (
              <div key={child.id} className="flex-1">
                <BlockNode block={child} />
              </div>
            ))}
          </div>
        ) : (
          <p className="text-[11px] italic text-muted-foreground">Empty columns — add blocks inside</p>
        )
      }
      case 'table': {
        const t = block as TableBlock
        return (
          <table className="w-full border-collapse text-[10px]">
            <thead>
              <tr>
                {t.columns.map((c, i) => (
                  <th key={i} className="border border-dashed border-muted-foreground/30 bg-muted/40 px-1.5 py-1 text-left">
                    {c.label || c.field}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {[0, 1].map((r) => (
                <tr key={r}>
                  {t.columns.map((c, i) => (
                    <td key={i} className="border border-dashed border-muted-foreground/20 px-1.5 py-1 text-muted-foreground">
                      {c.field === 'index' ? r + 1 : '…'}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        )
      }
      case 'totals': {
        const t = block as TotalsBlock
        return (
          <div className="ml-auto w-1/2 space-y-0.5 text-[11px]">
            {t.rows.map((r, i) => (
              <div key={i} className="flex justify-between">
                <span>{r.label}</span>
                <code className="text-muted-foreground">{r.field}</code>
              </div>
            ))}
          </div>
        )
      }
      case 'case_information': {
        const info = block as CaseInformationBlock
        return (
          <table className="w-full border-collapse text-[10px]">
            <thead>
              <tr><th colSpan={Math.max(info.rows.length, 1)} className="border bg-muted/60 px-2 py-1 text-left uppercase">{info.title || 'Case Information'}</th></tr>
              <tr>{info.rows.map((row, i) => <th key={i} className="border bg-muted/30 px-2 py-1 text-left">{row.label}</th>)}</tr>
            </thead>
            <tbody><tr>{info.rows.map((row, i) => <td key={i} className="border px-2 py-1 font-mono text-muted-foreground">{row.field}</td>)}</tr></tbody>
          </table>
        )
      }
      case 'spacer':
        return <div className="w-full border border-dashed border-muted-foreground/20 text-center text-[9px] text-muted-foreground" style={{ height: block.height || 12 }}>{block.height || 12}px</div>
      case 'divider':
        return <hr className="border-t border-muted-foreground/40" />
      case 'header':
      case 'footer':
      case 'conditional': {
        const c = block as ContainerBlock
        const label = block.type === 'conditional'
          ? `If ${block.condition || '…'}`
          : block.type === 'footer'
            ? 'Footer (last page bottom)'
            : 'Header (every page)'
        return (
          <div className="rounded border border-dashed border-primary/30 bg-primary/5">
            <div className="border-b border-primary/20 px-2 py-0.5 text-[9px] font-medium uppercase tracking-wide text-primary">{label}</div>
            <div className="space-y-1 p-1">
              {c.children.length ? (
                c.children.map((child) => <BlockNode key={child.id} block={child} />)
              ) : (
                <p className="text-[11px] italic text-muted-foreground">Empty — add blocks</p>
              )}
            </div>
          </div>
        )
      }
    }
  }

  return (
    <div
      onClick={stop}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      className={cn(
        'group relative rounded border px-2 py-1.5 transition',
        selected ? 'border-primary ring-1 ring-primary' : 'border-transparent hover:border-muted-foreground/30',
      )}
      style={{ opacity: isDragging ? 0.4 : 1 }}
    >
      <div
        ref={dragRef as unknown as React.Ref<HTMLDivElement>}
        title="Drag to move"
        className="absolute -left-[1px] top-0 z-10 flex cursor-grab items-center gap-0.5 rounded-br bg-primary px-1 py-0.5 text-[8px] font-semibold uppercase text-primary-foreground transition active:cursor-grabbing"
        style={{ opacity: selected || hovered ? 1 : 0 }}
        onClick={(e) => e.stopPropagation()}
      >
        <GripVertical className="h-2.5 w-2.5" />
        {TYPE_LABELS[block.type]}
      </div>

      {(selected || hovered) && (
        <div className="absolute right-1 top-1 z-10 flex items-center gap-0.5 rounded border bg-background shadow-sm">
          <ActionBtn title="Move up" onClick={(e) => { e.stopPropagation(); moveBlock(block.id, 'up') }}><ChevronUp className="h-3 w-3" /></ActionBtn>
          <ActionBtn title="Move down" onClick={(e) => { e.stopPropagation(); moveBlock(block.id, 'down') }}><ChevronDown className="h-3 w-3" /></ActionBtn>
          <ActionBtn title="Duplicate" onClick={(e) => { e.stopPropagation(); duplicateBlock(block.id) }}><Copy className="h-3 w-3" /></ActionBtn>
          <ActionBtn title="Delete" onClick={(e) => { e.stopPropagation(); removeBlock(block.id) }}><Trash2 className="h-3 w-3" /></ActionBtn>
        </div>
      )}

      {renderInner()}
    </div>
  )
}

function ActionBtn({ children, onClick, title }: { children: React.ReactNode; onClick: (e: React.MouseEvent) => void; title: string }) {
  return (
    <button onClick={onClick} title={title} className="p-1 text-muted-foreground hover:bg-accent hover:text-foreground">
      {children}
    </button>
  )
}
