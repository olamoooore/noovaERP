import { useDrop } from 'react-dnd'
import { useTemplateEditorStore } from '@/store/templateEditorStore'
import { BlockNode } from './BlockNode'
import { DND, type BlockDragItem, type NewBlockDragItem } from './dnd'
import { cn } from '@/lib/utils'

function DropZone({ index }: { index: number }) {
  const addBlock = useTemplateEditorStore((s) => s.addBlock)
  const moveBlockTo = useTemplateEditorStore((s) => s.moveBlockTo)

  const [{ isOver, canDrop }, dropRef] = useDrop<NewBlockDragItem | BlockDragItem, unknown, { isOver: boolean; canDrop: boolean }>(() => ({
    accept: [DND.NEW_BLOCK, DND.BLOCK],
    drop: (item) => {
      if ('blockType' in item) addBlock(item.blockType, null, index)
      else moveBlockTo(item.id, null, index)
    },
    collect: (m) => ({ isOver: m.isOver(), canDrop: m.canDrop() }),
  }))

  return (
    <div
      ref={dropRef as unknown as React.Ref<HTMLDivElement>}
      className={cn(
        'mx-[-4px] flex items-center justify-center rounded transition-all',
        isOver && canDrop ? 'h-8 bg-primary/15 ring-1 ring-primary/40' : 'h-2',
      )}
    >
      {isOver && canDrop && <span className="text-[9px] font-medium text-primary">Drop here</span>}
    </div>
  )
}

export function DocumentCanvas() {
  const template = useTemplateEditorStore((s) => s.template)
  const selectBlock = useTemplateEditorStore((s) => s.selectBlock)
  const addBlock = useTemplateEditorStore((s) => s.addBlock)

  if (!template) return null

  const blocks = template.layout_config?.blocks ?? []
  const isLandscape = template.orientation === 'landscape'

  return (
    <div className="flex h-full flex-col bg-muted/40">
      <div className="flex items-center justify-between border-b bg-background px-3 py-1.5">
        <span className="text-xs font-medium text-muted-foreground">
          {template.format} · {template.orientation}
        </span>
        <span className="text-[10px] text-muted-foreground">{blocks.length} block{blocks.length === 1 ? '' : 's'}</span>
      </div>

      <div className="flex-1 overflow-auto p-6" onClick={() => selectBlock(null)}>
        <div className="mx-auto" onClick={(e) => e.stopPropagation()}>
          <div
            className="mx-auto bg-white p-10 shadow-md"
            style={{
              width: isLandscape ? '842px' : '595px',
              minHeight: isLandscape ? '595px' : '842px',
              maxWidth: '100%',
            }}
          >
            {blocks.length === 0 ? (
              <button
                onClick={() => addBlock('text')}
                className="flex w-full items-center justify-center rounded border-2 border-dashed py-16 text-sm text-muted-foreground hover:border-primary hover:text-primary"
              >
                Drag a block here, or click to add your first block
              </button>
            ) : (
              <div className="space-y-0.5">
                <DropZone index={0} />
                {blocks.map((b, i) => (
                  <div key={b.id}>
                    <BlockNode block={b} />
                    <DropZone index={i + 1} />
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
