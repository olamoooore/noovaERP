import { useDrag, useDrop, DndProvider } from 'react-dnd'
import { HTML5Backend } from 'react-dnd-html5-backend'
import { useBuilderStore, BuilderNode } from '@/store/websiteBuilderStore'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

const CANVAS_WIDTHS = {
  desktop: '100%',
  tablet: '768px',
  mobile: '375px',
}

interface DragItem {
  type: string
  id?: string
  nodeType?: string
}

function CanvasNode({ node, depth = 0 }: { node: BuilderNode; depth?: number }) {
  const { selectedNodeId, hoveredNodeId, selectNode, hoverNode, addNode, moveNode } = useBuilderStore()
  
  const isSelected = selectedNodeId === node.id
  const isHovered = hoveredNodeId === node.id

  const [{ isDragging }, drag] = useDrag({
    type: 'canvas-node',
    item: { type: 'canvas-node', id: node.id },
    collect: (monitor) => ({
      isDragging: monitor.isDragging(),
    }),
  })

  const [{ isOver }, drop] = useDrop({
    accept: ['component', 'canvas-node'],
    drop: (item: DragItem, monitor) => {
      if (!monitor.didDrop()) {
        if (item.id && item.id !== node.id) {
          // Moving existing node
          moveNode(item.id, node.id, node.children.length)
        } else if (item.nodeType) {
          // Adding new component
          const newNode: BuilderNode = {
            id: `${item.nodeType}-${Date.now()}`,
            type: item.nodeType,
            props: getDefaultProps(item.nodeType),
            children: [],
          }
          addNode(node.id, newNode)
        }
      }
    },
    collect: (monitor) => ({
      isOver: monitor.isOver({ shallow: true }),
    }),
  })

  const renderContent = () => {
    switch (node.type) {
      case 'page':
        return node.children.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 text-muted-foreground border-2 border-dashed rounded-lg m-8">
            <Icon icon="solar:add-circle-bold" className="h-8 w-8 mb-2" />
            <p>Drop components here</p>
          </div>
        ) : null
      case 'section':
        return (
          <div style={{ 
            backgroundColor: node.props.backgroundColor || 'transparent',
            padding: `${node.props.padding?.top || 40}px ${node.props.padding?.right || 20}px ${node.props.padding?.bottom || 40}px ${node.props.padding?.left || 20}px`,
          }}>
            {node.children.map(child => (
              <CanvasNode key={child.id} node={child} depth={depth + 1} />
            ))}
            {node.children.length === 0 && (
              <div className="flex items-center justify-center h-24 border-2 border-dashed border-muted-foreground/30 rounded">
                <span className="text-muted-foreground text-sm">Empty section</span>
              </div>
            )}
          </div>
        )
      case 'container':
        return (
          <div style={{ maxWidth: node.props.maxWidth || '1200px', margin: '0 auto', padding: '0 20px' }}>
            {node.children.map(child => (
              <CanvasNode key={child.id} node={child} depth={depth + 1} />
            ))}
          </div>
        )
      case 'text':
        return (
          <div 
            style={{ 
              fontSize: node.props.fontSize || 16, 
              textAlign: node.props.textAlign || 'left',
              color: node.props.color || 'inherit',
            }}
            dangerouslySetInnerHTML={{ __html: node.props.content || 'Text' }}
          />
        )
      case 'image':
        return node.props.src ? (
          <img 
            src={node.props.src} 
            alt={node.props.alt || ''} 
            style={{ maxWidth: '100%', height: 'auto' }}
          />
        ) : (
          <div className="flex items-center justify-center h-32 bg-muted rounded">
            <Icon icon="solar:gallery-bold" className="h-8 w-8 text-muted-foreground" />
          </div>
        )
      case 'button':
        return (
          <button 
            className="px-6 py-3 rounded-lg"
            style={{ 
              backgroundColor: node.props.backgroundColor || '#3b82f6',
              color: node.props.textColor || 'white',
            }}
          >
            {node.props.text || 'Button'}
          </button>
        )
      default:
        return (
          <div className="p-4 border rounded bg-muted/50">
            <span className="text-xs text-muted-foreground">{node.type}</span>
            {node.children.map(child => (
              <CanvasNode key={child.id} node={child} depth={depth + 1} />
            ))}
          </div>
        )
    }
  }

  if (node.type === 'page') {
    return (
      <div ref={drop} className={cn('min-h-[400px]', isOver && 'bg-primary/5')}>
        {node.children.map(child => (
          <CanvasNode key={child.id} node={child} depth={depth + 1} />
        ))}
        {node.children.length === 0 && renderContent()}
      </div>
    )
  }

  return (
    <div
      ref={(el) => {
        drag(el)
        drop(el)
      }}
      className={cn(
        'relative transition-all',
        isSelected && 'ring-2 ring-primary ring-offset-2',
        isHovered && !isSelected && 'ring-1 ring-primary/50',
        isOver && 'bg-primary/5',
        isDragging && 'opacity-50'
      )}
      onClick={(e) => {
        e.stopPropagation()
        selectNode(node.id)
      }}
      onMouseEnter={() => hoverNode(node.id)}
      onMouseLeave={() => hoverNode(null)}
    >
      {/* Node Type Label */}
      {(isSelected || isHovered) && (
        <div className="absolute -top-6 left-0 bg-primary text-primary-foreground text-xs px-2 py-0.5 rounded">
          {node.type}
        </div>
      )}
      {renderContent()}
    </div>
  )
}

function getDefaultProps(type: string): Record<string, any> {
  switch (type) {
    case 'section':
      return { backgroundColor: '#ffffff', padding: { top: 60, right: 20, bottom: 60, left: 20 } }
    case 'container':
      return { maxWidth: '1200px' }
    case 'text':
      return { content: '<p>Enter your text here</p>', fontSize: 16 }
    case 'image':
      return { src: '', alt: '' }
    case 'button':
      return { text: 'Click me', href: '#', backgroundColor: '#3b82f6', textColor: '#ffffff' }
    default:
      return {}
  }
}

export function Canvas() {
  const { content, previewMode, selectNode } = useBuilderStore()

  return (
    <DndProvider backend={HTML5Backend}>
      <div 
        className="min-h-full transition-all mx-auto bg-white shadow-lg rounded-lg overflow-hidden"
        style={{ width: CANVAS_WIDTHS[previewMode], maxWidth: '100%' }}
        onClick={() => selectNode(null)}
      >
        <CanvasNode node={content} />
      </div>
    </DndProvider>
  )
}
