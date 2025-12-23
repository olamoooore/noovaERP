import { useDrag } from 'react-dnd'
import { Icon } from '@iconify/react'

interface ComponentDef {
  type: string
  label: string
  icon: string
  category: 'layout' | 'content' | 'media'
}

const COMPONENTS: ComponentDef[] = [
  { type: 'section', label: 'Section', icon: 'solar:layers-bold', category: 'layout' },
  { type: 'container', label: 'Container', icon: 'solar:widget-bold', category: 'layout' },
  { type: 'text', label: 'Text', icon: 'solar:text-bold', category: 'content' },
  { type: 'image', label: 'Image', icon: 'solar:gallery-bold', category: 'media' },
  { type: 'button', label: 'Button', icon: 'solar:cursor-bold', category: 'content' },
]

function DraggableComponent({ component }: { component: ComponentDef }) {
  const [{ isDragging }, drag] = useDrag({
    type: 'component',
    item: { type: 'component', nodeType: component.type },
    collect: (monitor) => ({
      isDragging: monitor.isDragging(),
    }),
  })

  return (
    <div
      ref={drag}
      className={`flex flex-col items-center justify-center p-3 rounded-lg border bg-card hover:border-primary hover:bg-primary/5 cursor-grab transition-all ${isDragging ? 'opacity-50' : ''}`}
    >
      <Icon icon={component.icon} className="h-6 w-6 mb-1 text-muted-foreground" />
      <span className="text-xs">{component.label}</span>
    </div>
  )
}

export function ComponentPanel() {
  const layoutComponents = COMPONENTS.filter(c => c.category === 'layout')
  const contentComponents = COMPONENTS.filter(c => c.category === 'content')
  const mediaComponents = COMPONENTS.filter(c => c.category === 'media')

  return (
    <div className="p-4 space-y-6">
      <div>
        <h3 className="text-xs font-semibold text-muted-foreground uppercase mb-2">Layout</h3>
        <div className="grid grid-cols-2 gap-2">
          {layoutComponents.map((c) => (
            <DraggableComponent key={c.type} component={c} />
          ))}
        </div>
      </div>

      <div>
        <h3 className="text-xs font-semibold text-muted-foreground uppercase mb-2">Content</h3>
        <div className="grid grid-cols-2 gap-2">
          {contentComponents.map((c) => (
            <DraggableComponent key={c.type} component={c} />
          ))}
        </div>
      </div>

      <div>
        <h3 className="text-xs font-semibold text-muted-foreground uppercase mb-2">Media</h3>
        <div className="grid grid-cols-2 gap-2">
          {mediaComponents.map((c) => (
            <DraggableComponent key={c.type} component={c} />
          ))}
        </div>
      </div>
    </div>
  )
}
