import { useBuilderStore, BuilderNode } from '@/store/websiteBuilderStore'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

function LayerItem({ node, depth = 0 }: { node: BuilderNode; depth?: number }) {
  const { selectedNodeId, selectNode, hoverNode, deleteNode } = useBuilderStore()
  const isSelected = selectedNodeId === node.id

  const getIcon = (type: string) => {
    switch (type) {
      case 'page': return 'solar:file-bold'
      case 'section': return 'solar:layers-bold'
      case 'container': return 'solar:widget-bold'
      case 'text': return 'solar:text-bold'
      case 'image': return 'solar:gallery-bold'
      case 'button': return 'solar:cursor-bold'
      default: return 'solar:box-bold'
    }
  }

  return (
    <div>
      <div
        className={cn(
          'flex items-center gap-2 py-1.5 px-2 cursor-pointer hover:bg-muted rounded-md transition-colors',
          isSelected && 'bg-primary/10 text-primary'
        )}
        style={{ paddingLeft: depth * 16 + 8 }}
        onClick={() => selectNode(node.id)}
        onMouseEnter={() => hoverNode(node.id)}
        onMouseLeave={() => hoverNode(null)}
      >
        {node.children.length > 0 && (
          <Icon icon="solar:alt-arrow-down-bold" className="h-3 w-3 text-muted-foreground" />
        )}
        {node.children.length === 0 && <div className="w-3" />}
        <Icon icon={getIcon(node.type)} className="h-4 w-4" />
        <span className="text-sm flex-1 truncate capitalize">{node.type}</span>
        {node.type !== 'page' && (
          <button
            className="opacity-0 group-hover:opacity-100 hover:text-destructive"
            onClick={(e) => {
              e.stopPropagation()
              deleteNode(node.id)
            }}
          >
            <Icon icon="solar:trash-bin-minimalistic-bold" className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {node.children.map((child) => (
        <LayerItem key={child.id} node={child} depth={depth + 1} />
      ))}
    </div>
  )
}

export function LayersPanel() {
  const { content } = useBuilderStore()

  return (
    <div className="p-2">
      <LayerItem node={content} />
    </div>
  )
}
