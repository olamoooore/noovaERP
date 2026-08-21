import { useDrag } from 'react-dnd'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { useTemplateEditorStore } from '@/store/templateEditorStore'
import type { Block, BlockType } from '@/types/template-builder'
import { DND, type NewBlockDragItem } from './dnd'
import {
  Type, Table2, Calculator, Columns2, Image as ImageIcon,
  Minus, Square, PanelTop, PanelBottom, GitBranch, GripVertical, ClipboardList,
} from 'lucide-react'

interface BlockDef {
  type: BlockType
  label: string
  hint: string
  icon: React.ComponentType<{ className?: string }>
}

const BLOCKS: BlockDef[] = [
  { type: 'text', label: 'Text', hint: 'Rich text with merge fields', icon: Type },
  { type: 'table', label: 'Line Items', hint: 'Table bound to document lines', icon: Table2 },
  { type: 'case_information', label: 'Case Information', hint: 'Case details in a two-column table', icon: ClipboardList },
  { type: 'totals', label: 'Totals', hint: 'Subtotal / tax / total rows', icon: Calculator },
  { type: 'columns', label: 'Columns', hint: 'Side-by-side layout', icon: Columns2 },
  { type: 'image', label: 'Image / Logo', hint: 'Logo or signature', icon: ImageIcon },
  { type: 'divider', label: 'Divider', hint: 'Horizontal rule', icon: Minus },
  { type: 'spacer', label: 'Spacer', hint: 'Vertical whitespace', icon: Square },
  { type: 'header', label: 'Header', hint: 'Repeats at the top of every page', icon: PanelTop },
  { type: 'footer', label: 'Footer', hint: 'Anchored at the bottom of the final page', icon: PanelBottom },
  { type: 'conditional', label: 'Conditional', hint: 'Show children when condition is true', icon: GitBranch },
]

function findBlock(blocks: Block[], id: string | null): Block | null {
  if (!id) return null
  for (const b of blocks) {
    if (b.id === id) return b
    if ('children' in b && b.children.length) {
      const f = findBlock(b.children, id)
      if (f) return f
    }
  }
  return null
}

function isContainer(block: Block | null): block is Block & { children: Block[] } {
  return !!block && 'children' in block
}

function PaletteItem({ def }: { def: BlockDef }) {
  const addBlock = useTemplateEditorStore((s) => s.addBlock)
  const selectedId = useTemplateEditorStore((s) => s.selectedBlockId)
  const template = useTemplateEditorStore((s) => s.template)

  const [{ isDragging }, dragRef] = useDrag<NewBlockDragItem, unknown, { isDragging: boolean }>(() => ({
    type: DND.NEW_BLOCK,
    item: { blockType: def.type },
    collect: (m) => ({ isDragging: m.isDragging() }),
  }))

  const handleAdd = () => {
    const selected = findBlock(template?.layout_config?.blocks ?? [], selectedId)
    const parentId = isContainer(selected) ? selected.id : null
    addBlock(def.type, parentId, -1)
  }

  const Icon = def.icon
  return (
    <button
      ref={dragRef as unknown as React.Ref<HTMLButtonElement>}
      onClick={handleAdd}
      title={def.hint}
      className="group flex cursor-grab flex-col items-start gap-1 rounded-md border bg-background p-2 text-left transition hover:border-primary hover:bg-accent active:cursor-grabbing"
      style={{ opacity: isDragging ? 0.4 : 1 }}
    >
      <div className="flex w-full items-center justify-between">
        <Icon className="h-4 w-4 text-muted-foreground group-hover:text-primary" />
        <GripVertical className="h-3 w-3 text-muted-foreground/40 opacity-0 group-hover:opacity-100" />
      </div>
      <span className="text-xs font-medium">{def.label}</span>
    </button>
  )
}

export function BlocksPalette() {
  return (
    <div className="flex h-full flex-col">
      <div className="border-b px-3 py-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Blocks</h3>
      </div>
      <ScrollArea className="flex-1">
        <div className="grid grid-cols-2 gap-2 p-3">
          {BLOCKS.map((b) => (
            <PaletteItem key={b.type} def={b} />
          ))}
        </div>
      </ScrollArea>
      <div className="border-t px-3 py-2 text-[10px] text-muted-foreground">
        Drag onto the canvas, or click to add. Select a Columns/Container block first to add inside it.
      </div>
    </div>
  )
}
