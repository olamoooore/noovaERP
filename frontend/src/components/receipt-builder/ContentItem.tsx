import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import type { ColumnContent, DynamicField } from '@/types/receipt-builder'
import { getFieldDefinition } from '@/lib/receiptTemplates'

interface ContentItemProps {
  sectionId: string
  columnId: string
  content: ColumnContent
}

export function ContentItem({ sectionId, columnId, content }: ContentItemProps) {
  const {
    selectedContentId,
    selectContent,
    selectSection,
    removeContent,
    moveContent,
  } = useReceiptBuilderStore()

  const isSelected = selectedContentId === content.id

  const handleRemove = (e: React.MouseEvent) => {
    e.stopPropagation()
    removeContent(sectionId, columnId, content.id)
  }

  const handleMoveUp = (e: React.MouseEvent) => {
    e.stopPropagation()
    moveContent(sectionId, columnId, content.id, 'up')
  }

  const handleMoveDown = (e: React.MouseEvent) => {
    e.stopPropagation()
    moveContent(sectionId, columnId, content.id, 'down')
  }

  const renderContent = () => {
    if (content.type === 'text') {
      return <span>{content.value as string}</span>
    }

    if (content.type === 'field') {
      const field = content.value as DynamicField
      const fieldDef = getFieldDefinition(field.fieldType)
      const label = fieldDef?.label || field.fieldType
      const preview = field.format ? field.format.replace('{value}', label) : label
      return <span className="text-blue-600">{preview}</span>
    }

    if (content.type === 'image') {
      return (
        <div className="inline-flex items-center gap-1 text-purple-600">
          <Icon icon="solar:gallery-bold-duotone" className="w-4 h-4" />
          <span>Image</span>
        </div>
      )
    }

    if (content.type === 'separator') {
      return <div className="border-t border-gray-300 my-1" />
    }

    return null
  }

  return (
    <div
      className={cn(
        'relative group/item px-2 py-1 rounded transition-all cursor-pointer',
        isSelected ? 'ring-2 ring-green-500 bg-green-50' : 'hover:bg-gray-50'
      )}
      onClick={(e) => { e.stopPropagation(); selectSection(sectionId); selectContent(content.id) }}
      style={{
        fontSize: content.style.fontSize === 'small' ? '0.75rem' :
                 content.style.fontSize === 'large' ? '1.125rem' : '0.875rem',
        fontWeight: content.style.fontWeight,
        textTransform: content.style.textTransform || 'none',
        marginTop: content.style.marginTop || 0,
        marginBottom: content.style.marginBottom || 0
      }}
    >
      {renderContent()}

      {/* Action bar — move up/down/delete only */}
      {isSelected && (
        <div
          className="absolute top-0 right-0 flex items-center bg-white/95 border border-green-300 rounded-bl shadow-sm z-10"
          onClick={(e) => e.stopPropagation()}
        >
          <Button size="icon" variant="ghost" className="h-5 w-5" onClick={handleMoveUp} title="Move up">
            <Icon icon="solar:alt-arrow-up-linear" className="w-2.5 h-2.5" />
          </Button>
          <Button size="icon" variant="ghost" className="h-5 w-5" onClick={handleMoveDown} title="Move down">
            <Icon icon="solar:alt-arrow-down-linear" className="w-2.5 h-2.5" />
          </Button>
          <Button
            size="icon" variant="ghost"
            className="h-5 w-5 text-red-500 hover:text-red-700 hover:bg-red-50"
            onClick={handleRemove} title="Remove"
          >
            <Icon icon="solar:trash-bin-trash-linear" className="w-2.5 h-2.5" />
          </Button>
        </div>
      )}
    </div>
  )
}
