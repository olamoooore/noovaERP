import { useBuilderStore, BuilderNode } from '@/store/websiteBuilderStore'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'

export function PropertiesPanel() {
  const { content, selectedNodeId, updateNode, deleteNode } = useBuilderStore()

  const findNode = (root: BuilderNode, id: string): BuilderNode | null => {
    if (root.id === id) return root
    for (const child of root.children) {
      const found = findNode(child, id)
      if (found) return found
    }
    return null
  }

  const selectedNode = selectedNodeId ? findNode(content, selectedNodeId) : null

  if (!selectedNode) {
    return (
      <div className="p-4 text-center text-muted-foreground">
        <Icon icon="solar:cursor-bold" className="h-8 w-8 mx-auto mb-2 opacity-30" />
        <p className="text-sm">Select an element to edit</p>
      </div>
    )
  }

  const handleChange = (key: string, value: any) => {
    updateNode(selectedNode.id, { [key]: value })
  }

  const handleNestedChange = (parentKey: string, key: string, value: any) => {
    const current = selectedNode.props[parentKey] || {}
    updateNode(selectedNode.id, { [parentKey]: { ...current, [key]: value } })
  }

  return (
    <div className="p-4 space-y-6">
      <div className="flex items-center justify-between">
        <h3 className="font-semibold capitalize">{selectedNode.type}</h3>
        <Button 
          variant="ghost" 
          size="icon" 
          className="h-7 w-7 text-destructive"
          onClick={() => deleteNode(selectedNode.id)}
        >
          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
        </Button>
      </div>

      {/* Type-specific properties */}
      {selectedNode.type === 'text' && (
        <>
          <div className="space-y-2">
            <Label>Content</Label>
            <Textarea
              value={selectedNode.props.content || ''}
              onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => handleChange('content', e.target.value)}
              rows={4}
            />
          </div>
          <div className="space-y-2">
            <Label>Font Size (px)</Label>
            <Input
              type="number"
              value={selectedNode.props.fontSize || 16}
              onChange={(e) => handleChange('fontSize', parseInt(e.target.value))}
            />
          </div>
          <div className="space-y-2">
            <Label>Text Align</Label>
            <div className="flex gap-1">
              {['left', 'center', 'right'].map((align) => (
                <Button
                  key={align}
                  variant={selectedNode.props.textAlign === align ? 'secondary' : 'outline'}
                  size="sm"
                  onClick={() => handleChange('textAlign', align)}
                >
                  {align}
                </Button>
              ))}
            </div>
          </div>
          <div className="space-y-2">
            <Label>Color</Label>
            <Input
              type="color"
              value={selectedNode.props.color || '#000000'}
              onChange={(e) => handleChange('color', e.target.value)}
            />
          </div>
        </>
      )}

      {selectedNode.type === 'button' && (
        <>
          <div className="space-y-2">
            <Label>Button Text</Label>
            <Input
              value={selectedNode.props.text || ''}
              onChange={(e) => handleChange('text', e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label>Link URL</Label>
            <Input
              value={selectedNode.props.href || ''}
              onChange={(e) => handleChange('href', e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label>Background Color</Label>
            <Input
              type="color"
              value={selectedNode.props.backgroundColor || '#3b82f6'}
              onChange={(e) => handleChange('backgroundColor', e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label>Text Color</Label>
            <Input
              type="color"
              value={selectedNode.props.textColor || '#ffffff'}
              onChange={(e) => handleChange('textColor', e.target.value)}
            />
          </div>
        </>
      )}

      {selectedNode.type === 'image' && (
        <>
          <div className="space-y-2">
            <Label>Image URL</Label>
            <Input
              value={selectedNode.props.src || ''}
              onChange={(e) => handleChange('src', e.target.value)}
              placeholder="https://..."
            />
          </div>
          <div className="space-y-2">
            <Label>Alt Text</Label>
            <Input
              value={selectedNode.props.alt || ''}
              onChange={(e) => handleChange('alt', e.target.value)}
            />
          </div>
        </>
      )}

      {selectedNode.type === 'section' && (
        <>
          <div className="space-y-2">
            <Label>Background Color</Label>
            <Input
              type="color"
              value={selectedNode.props.backgroundColor || '#ffffff'}
              onChange={(e) => handleChange('backgroundColor', e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label>Padding Top (px)</Label>
            <Input
              type="number"
              value={selectedNode.props.padding?.top || 40}
              onChange={(e) => handleNestedChange('padding', 'top', parseInt(e.target.value))}
            />
          </div>
          <div className="space-y-2">
            <Label>Padding Bottom (px)</Label>
            <Input
              type="number"
              value={selectedNode.props.padding?.bottom || 40}
              onChange={(e) => handleNestedChange('padding', 'bottom', parseInt(e.target.value))}
            />
          </div>
        </>
      )}

      {selectedNode.type === 'container' && (
        <div className="space-y-2">
          <Label>Max Width</Label>
          <Input
            value={selectedNode.props.maxWidth || '1200px'}
            onChange={(e) => handleChange('maxWidth', e.target.value)}
          />
        </div>
      )}
    </div>
  )
}
