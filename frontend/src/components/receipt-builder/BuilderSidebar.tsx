import { useState, useEffect } from 'react'
import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import { SECTION_TEMPLATES } from '@/lib/receiptTemplates'
import { PropertyPanel } from './PropertyPanel'
import { cn } from '@/lib/utils'
import type { SectionType } from '@/types/receipt-builder'

type Tab = 'templates' | 'properties'

export function BuilderSidebar() {
  const [activeTab, setActiveTab] = useState<Tab>('templates')
  const { addSection, selectedSectionId, selectedContentId } = useReceiptBuilderStore()

  // Auto-switch to properties tab when content or section is selected
  useEffect(() => {
    if (selectedContentId) {
      setActiveTab('properties')
    }
  }, [selectedContentId])

  const handleAddSection = (type: SectionType) => {
    addSection(type)
  }

  return (
    <div className="w-80 border-r bg-background flex flex-col">
      {/* Tabs */}
      <div className="border-b">
        <div className="flex">
          <button
            onClick={() => setActiveTab('templates')}
            className={cn(
              'flex-1 px-4 py-3 text-sm font-medium border-b-2 transition-colors',
              activeTab === 'templates'
                ? 'border-primary text-primary'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )}
          >
            <Icon icon="solar:widget-add-bold-duotone" className="w-4 h-4 inline mr-2" />
            Templates
          </button>
          <button
            onClick={() => setActiveTab('properties')}
            className={cn(
              'flex-1 px-4 py-3 text-sm font-medium border-b-2 transition-colors',
              activeTab === 'properties'
                ? 'border-primary text-primary'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )}
          >
            <Icon icon="solar:settings-bold-duotone" className="w-4 h-4 inline mr-2" />
            Properties
          </button>
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-auto p-4">
        {activeTab === 'templates' && (
          <div className="space-y-3">
            <div className="mb-4">
              <h3 className="text-sm font-semibold mb-2">Add Section</h3>
              <p className="text-xs text-muted-foreground">
                Click a template to add it to your receipt
              </p>
            </div>

            {SECTION_TEMPLATES.map((template) => (
              <Card
                key={template.type}
                className="p-3 cursor-pointer hover:bg-accent transition-colors"
                onClick={() => handleAddSection(template.type)}
              >
                <div className="flex items-start gap-3">
                  <div className="p-2 rounded-lg bg-primary/10">
                    <Icon icon={template.icon} className="w-5 h-5 text-primary" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <h4 className="text-sm font-medium">{template.label}</h4>
                    <p className="text-xs text-muted-foreground mt-0.5">
                      {template.description}
                    </p>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )}

        {activeTab === 'properties' && (
          <div>
            {selectedSectionId ? (
              <PropertyPanel />
            ) : (
              <div className="text-center py-12">
                <Icon
                  icon="solar:settings-minimalistic-bold-duotone"
                  className="w-12 h-12 mx-auto mb-3 text-muted-foreground"
                />
                <p className="text-sm text-muted-foreground">
                  Select a section to edit its properties
                </p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
