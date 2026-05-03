import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import type { ReceiptSection } from '@/types/receipt-builder'
import { ColumnContainer } from './ColumnContainer'

interface SectionRowProps {
  section: ReceiptSection
}

export function SectionRow({ section }: SectionRowProps) {
  const {
    selectedSectionId,
    selectSection,
    removeSection,
    moveSection,
    duplicateSection,
    config
  } = useReceiptBuilderStore()

  const isSelected = selectedSectionId === section.id
  const isFirst = section.order === 0
  const isLast = config && section.order === config.sections.length - 1

  const handleRemove = (e: React.MouseEvent) => {
    e.stopPropagation()
    if (confirm(`Delete "${section.title ?? section.type}" section?`)) {
      removeSection(section.id)
    }
  }

  const handleMoveUp = (e: React.MouseEvent) => {
    e.stopPropagation()
    moveSection(section.id, 'up')
  }

  const handleMoveDown = (e: React.MouseEvent) => {
    e.stopPropagation()
    moveSection(section.id, 'down')
  }

  const handleDuplicate = (e: React.MouseEvent) => {
    e.stopPropagation()
    duplicateSection(section.id)
  }

  return (
    <div
      className={cn(
        'relative group transition-all cursor-pointer',
        isSelected && 'ring-2 ring-teal-500 ring-inset bg-teal-50/50'
      )}
      onClick={() => selectSection(section.id)}
      style={{
        minHeight: `${section.height}px`,
        borderTop: section.properties.borderTop ? '1px solid #e5e7eb' : 'none',
        borderBottom: section.properties.borderBottom ? '1px solid #e5e7eb' : 'none',
        padding: `${section.properties.padding || 0}px`,
        backgroundColor: section.properties.backgroundColor || 'transparent'
      }}
    >
      {/* Section Action Bar */}
      <div
        className={cn(
          'absolute top-0 right-0 flex flex-row items-center gap-0.5 px-1 py-0.5 bg-white/90 border border-gray-200 rounded-bl shadow-sm opacity-0 group-hover:opacity-100 transition-opacity z-20',
          isSelected && 'opacity-100 bg-teal-50/90 border-teal-300'
        )}
        onClick={(e) => e.stopPropagation()}
      >
        <span className="text-[10px] text-gray-500 px-1 font-medium capitalize">
          {section.title ?? section.type}
        </span>
        <div className="w-px h-4 bg-gray-200 mx-0.5" />

        <Button size="icon" variant="ghost" className="h-6 w-6"
          onClick={handleMoveUp} disabled={isFirst} title="Move up">
          <Icon icon="solar:alt-arrow-up-linear" className="w-3 h-3" />
        </Button>

        <Button size="icon" variant="ghost" className="h-6 w-6"
          onClick={handleMoveDown} disabled={!!isLast} title="Move down">
          <Icon icon="solar:alt-arrow-down-linear" className="w-3 h-3" />
        </Button>

        <Button size="icon" variant="ghost" className="h-6 w-6"
          onClick={handleDuplicate} title="Duplicate section">
          <Icon icon="solar:copy-linear" className="w-3 h-3" />
        </Button>

        <Button size="icon" variant="ghost"
          className="h-6 w-6 text-red-500 hover:text-red-700 hover:bg-red-50"
          onClick={handleRemove} title="Delete section">
          <Icon icon="solar:trash-bin-trash-linear" className="w-3 h-3" />
        </Button>
      </div>

      {/* Column Grid */}
      <div
        className="grid gap-2"
        style={{
          gridTemplateColumns: section.columns.map(col => `${col.width}%`).join(' ')
        }}
      >
        {section.columns.map((column) => (
          <ColumnContainer
            key={column.id}
            sectionId={section.id}
            column={column}
          />
        ))}
      </div>
    </div>
  )
}
