import { Icon } from '@iconify/react'
import { clsx } from 'clsx'
import type { Block, ContainerBlock, ColumnsBlock } from '@/types/template-builder'
import { useTemplateEditorStore } from '@/store/templateEditorStore'

const ICONS: Record<string, string> = {
  text: 'mdi:format-text',
  image: 'mdi:image',
  columns: 'mdi:view-column-outline',
  table: 'mdi:table',
  totals: 'mdi:calculator-variant',
  spacer: 'mdi:arrow-expand-vertical',
  divider: 'mdi:minus',
  header: 'mdi:page-layout-header',
  footer: 'mdi:page-layout-footer',
  conditional: 'mdi:code-not-equal-variant',
}

function hasChildren(b: Block): b is ContainerBlock | ColumnsBlock {
  return 'children' in b
}

interface TreeNodeProps {
  block: Block
  depth: number
}

function TreeNode({ block, depth }: TreeNodeProps) {
  const selectedId = useTemplateEditorStore((s) => s.selectedBlockId)
  const select = useTemplateEditorStore((s) => s.selectBlock)
  const remove = useTemplateEditorStore((s) => s.removeBlock)
  const duplicate = useTemplateEditorStore((s) => s.duplicateBlock)
  const copy = useTemplateEditorStore((s) => s.copyBlock)
  const paste = useTemplateEditorStore((s) => s.pasteBlock)
  const selected = selectedId === block.id

  return (
    <div>
      <div
        className={clsx(
          'group flex items-center gap-1 rounded px-1.5 py-1 text-xs cursor-pointer',
          selected ? 'bg-blue-100 text-blue-900' : 'hover:bg-gray-100',
        )}
        style={{ paddingLeft: depth * 12 + 6 }}
        onClick={() => select(block.id)}
      >
        <Icon icon={ICONS[block.type] ?? 'mdi:cube-outline'} className="h-3.5 w-3.5 shrink-0 text-gray-500" />
        <span className="truncate flex-1">
          {block.type}
          {'content' in block && block.content ? ` — ${String(block.content).slice(0, 24)}` : ''}
        </span>
        <div className="hidden group-hover:flex items-center gap-0.5">
          <button title="Copy" className="p-0.5 hover:bg-gray-200 rounded" onClick={(e) => { e.stopPropagation(); copy(block.id) }}>
            <Icon icon="mdi:content-copy" className="h-3 w-3" />
          </button>
          <button title="Paste below" className="p-0.5 hover:bg-gray-200 rounded" onClick={(e) => { e.stopPropagation(); paste(null) }}>
            <Icon icon="mdi:content-paste" className="h-3 w-3" />
          </button>
          <button title="Duplicate" className="p-0.5 hover:bg-gray-200 rounded" onClick={(e) => { e.stopPropagation(); duplicate(block.id) }}>
            <Icon icon="mdi:content-duplicate" className="h-3 w-3" />
          </button>
          <button title="Delete" className="p-0.5 hover:bg-red-100 rounded text-red-600" onClick={(e) => { e.stopPropagation(); remove(block.id) }}>
            <Icon icon="mdi:trash-can-outline" className="h-3 w-3" />
          </button>
        </div>
      </div>
      {hasChildren(block) && block.children.length > 0 && (
        <div>
          {block.children.map((child) => (
            <TreeNode key={child.id} block={child} depth={depth + 1} />
          ))}
        </div>
      )}
    </div>
  )
}

export function StructureTree() {
  const blocks = useTemplateEditorStore((s) => s.template?.layout_config?.blocks ?? [])
  const undo = useTemplateEditorStore((s) => s.undo)
  const redo = useTemplateEditorStore((s) => s.redo)

  if (blocks.length === 0) {
    return (
      <div className="text-xs text-gray-400 italic p-3">No blocks yet — drag from the palette.</div>
    )
  }

  return (
    <div className="flex flex-col h-full">
      <div className="flex items-center justify-between px-2 py-1.5 border-b">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Structure</span>
        <div className="flex gap-1">
          <button title="Undo" onClick={undo} className="p-1 rounded hover:bg-gray-100">
            <Icon icon="mdi:undo" className="h-3.5 w-3.5" />
          </button>
          <button title="Redo" onClick={redo} className="p-1 rounded hover:bg-gray-100">
            <Icon icon="mdi:redo" className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
      <div className="flex-1 overflow-auto py-1">
        {blocks.map((b) => (
          <TreeNode key={b.id} block={b} depth={0} />
        ))}
      </div>
    </div>
  )
}
