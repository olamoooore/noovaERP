import { useState } from 'react'
import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
  DropdownMenuLabel,
} from '@/components/ui/dropdown-menu'
import type { ReceiptColumn, DynamicFieldType } from '@/types/receipt-builder'
import { ContentItem } from './ContentItem'
import { FIELD_DEFINITIONS } from '@/lib/receiptTemplates'

interface ColumnContainerProps {
  sectionId: string
  column: ReceiptColumn
}

export function ColumnContainer({ sectionId, column }: ColumnContainerProps) {
  const { selectedColumnId, selectColumn, addContent, config } = useReceiptBuilderStore()
  const [isAddMenuOpen, setIsAddMenuOpen] = useState(false)

  const isSelected = selectedColumnId === column.id

  // Get section type to filter available fields
  const section = config?.sections.find(s => s.id === sectionId)
  const availableFields = section
    ? FIELD_DEFINITIONS.filter(f => f.availableIn.includes(section.type))
    : []

  const handleSelect = (e: React.MouseEvent) => {
    e.stopPropagation()
    selectColumn(column.id)
  }

  const handleAddText = () => {
    addContent(sectionId, column.id, {
      type: 'text',
      value: 'New text',
      style: {
        fontSize: 'medium',
        fontWeight: 'normal'
      }
    })
    setIsAddMenuOpen(false)
  }

  const handleAddField = (fieldType: DynamicFieldType) => {
    addContent(sectionId, column.id, {
      type: 'field',
      value: { fieldType },
      style: {
        fontSize: 'medium',
        fontWeight: 'normal'
      }
    })
    setIsAddMenuOpen(false)
  }

  const handleAddSeparator = () => {
    addContent(sectionId, column.id, {
      type: 'separator',
      value: '',
      style: {
        fontSize: 'medium',
        fontWeight: 'normal'
      }
    })
    setIsAddMenuOpen(false)
  }

  return (
    <div
      className={cn(
        'min-h-[40px] p-2 rounded transition-all cursor-pointer',
        isSelected && 'ring-2 ring-blue-500 bg-blue-50',
        !isSelected && 'hover:bg-muted/50'
      )}
      onClick={handleSelect}
      style={{ textAlign: column.alignment }}
    >
      {/* Content Items */}
      {(column.content ?? []).length > 0 ? (
        <div className="space-y-1">
          {(column.content ?? []).map((content) => (
            <ContentItem
              key={content.id}
              sectionId={sectionId}
              columnId={column.id}
              content={content}
            />
          ))}
        </div>
      ) : (
        <div className="text-center py-4">
          <DropdownMenu open={isAddMenuOpen} onOpenChange={setIsAddMenuOpen}>
            <DropdownMenuTrigger asChild>
              <Button
                size="sm"
                variant="ghost"
                onClick={(e) => e.stopPropagation()}
                className="text-xs"
              >
                <Icon icon="solar:add-circle-linear" className="w-3 h-3 mr-1" />
                Add Content
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="center" className="w-56">
              <DropdownMenuLabel>Content Type</DropdownMenuLabel>
              <DropdownMenuSeparator />
              
              <DropdownMenuItem onClick={handleAddText}>
                <Icon icon="solar:text-bold-duotone" className="w-4 h-4 mr-2" />
                Text
              </DropdownMenuItem>

              {availableFields.length > 0 && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuLabel className="text-xs">Dynamic Fields</DropdownMenuLabel>
                  {availableFields.map((field) => (
                    <DropdownMenuItem
                      key={field.fieldType}
                      onClick={() => handleAddField(field.fieldType)}
                    >
                      <Icon icon="solar:code-bold-duotone" className="w-4 h-4 mr-2 text-blue-500" />
                      {field.label}
                    </DropdownMenuItem>
                  ))}
                </>
              )}

              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={handleAddSeparator}>
                <Icon icon="solar:minus-circle-bold-duotone" className="w-4 h-4 mr-2" />
                Separator Line
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      )}

      {/* Add Content Button (when column has content) */}
      {isSelected && (column.content ?? []).length > 0 && (
        <DropdownMenu open={isAddMenuOpen} onOpenChange={setIsAddMenuOpen}>
          <DropdownMenuTrigger asChild>
            <Button
              size="sm"
              variant="ghost"
              onClick={(e) => e.stopPropagation()}
              className="w-full mt-2 text-xs"
            >
              <Icon icon="solar:add-circle-linear" className="w-3 h-3 mr-1" />
              Add Content
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="center" className="w-56">
            <DropdownMenuLabel>Content Type</DropdownMenuLabel>
            <DropdownMenuSeparator />
            
            <DropdownMenuItem onClick={handleAddText}>
              <Icon icon="solar:text-bold-duotone" className="w-4 h-4 mr-2" />
              Text
            </DropdownMenuItem>

            {availableFields.length > 0 && (
              <>
                <DropdownMenuSeparator />
                <DropdownMenuLabel className="text-xs">Dynamic Fields</DropdownMenuLabel>
                {availableFields.map((field) => (
                  <DropdownMenuItem
                    key={field.fieldType}
                    onClick={() => handleAddField(field.fieldType)}
                  >
                    <Icon icon="solar:code-bold-duotone" className="w-4 h-4 mr-2 text-blue-500" />
                    {field.label}
                  </DropdownMenuItem>
                ))}
              </>
            )}

            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={handleAddSeparator}>
              <Icon icon="solar:minus-circle-bold-duotone" className="w-4 h-4 mr-2" />
              Separator Line
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  )
}
