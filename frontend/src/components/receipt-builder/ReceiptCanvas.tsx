import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { PAPER_WIDTHS } from '@/lib/receiptTemplates'
import { SectionRow } from './SectionRow'
import { cn } from '@/lib/utils'

export function ReceiptCanvas() {
  const { config, addSection } = useReceiptBuilderStore()

  if (!config) return null

  const paperWidthPx = PAPER_WIDTHS[config.paperWidth].pixels

  return (
    <div className="flex justify-center">
      <div className="space-y-4">
        {/* Paper Info */}
        <div className="text-center text-sm text-gray-500">
          <p>Paper Width: {config.paperWidth} ({paperWidthPx}px)</p>
          <p className="text-xs mt-1">Click sections to edit • Double-click titles to rename</p>
        </div>

        {/* Receipt Paper */}
        <Card
          className="bg-white shadow-2xl relative"
          style={{ width: `${paperWidthPx}px` }}
        >
          {/* Sections */}
          {config.sections.length === 0 ? (
            <div className="py-24 text-center">
              <Icon
                icon="solar:document-add-bold-duotone"
                className="w-16 h-16 mx-auto mb-4 text-muted-foreground"
              />
              <p className="text-sm text-muted-foreground mb-4">
                No sections yet. Add your first section to get started.
              </p>
              <Button onClick={() => addSection('header')}>
                <Icon icon="solar:add-circle-bold-duotone" className="w-4 h-4 mr-2" />
                Add Header Section
              </Button>
            </div>
          ) : (
            <div className="divide-y">
              {config.sections
                .sort((a, b) => a.order - b.order)
                .map((section) => (
                  <SectionRow key={section.id} section={section} />
                ))}
            </div>
          )}

          {/* Add Section Button */}
          {config.sections.length > 0 && (
            <div className="p-4 border-t bg-muted/30">
              <Button
                variant="outline"
                className="w-full"
                onClick={() => addSection('footer')}
              >
                <Icon icon="solar:add-circle-bold-duotone" className="w-4 h-4 mr-2" />
                Add Section
              </Button>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}
