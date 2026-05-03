import { useReceiptBuilderStore } from '@/store/receiptBuilderStore'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import { Button } from '@/components/ui/button'
import { Icon } from '@iconify/react'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { DynamicField, ColumnContent, DynamicFieldType } from '@/types/receipt-builder'
import { getFieldDefinition } from '@/lib/receiptTemplates'

export function PropertyPanel() {
  const {
    config,
    selectedSectionId,
    selectedColumnId,
    selectedContentId,
    updateSection,
    updateColumn,
    updateContent,
    addColumn,
    removeColumn
  } = useReceiptBuilderStore()

  if (!config || !selectedSectionId) return null

  const section = config.sections.find(s => s.id === selectedSectionId)
  if (!section) return null

  const column = selectedColumnId
    ? section.columns.find(c => c.id === selectedColumnId)
    : null

  // Find content across all columns
  const content = selectedContentId
    ? section.columns.flatMap(c => c.content).find(c => c.id === selectedContentId)
    : null
  const contentColumn = selectedContentId
    ? section.columns.find(c => c.content.some(ct => ct.id === selectedContentId))
    : null

  // Content properties panel
  if (content && contentColumn) {
    // Determine if this content originated from a field
    const fieldType: DynamicFieldType | undefined =
      content.type === 'field'
        ? (content.value as DynamicField).fieldType
        : content.originalFieldType

    const isFieldOrigin = !!fieldType
    const fieldDef = fieldType ? getFieldDefinition(fieldType) : null

    // Current text value (if already overridden)
    const currentTextValue = content.type === 'text' ? (content.value as string) : ''

    const handleFieldValueChange = (e: React.ChangeEvent<HTMLInputElement>) => {
      const val = e.target.value
      if (val.trim()) {
        // Convert to text, preserving the original field type for future reference
        updateContent(selectedSectionId, contentColumn.id, content.id, {
          type: 'text',
          value: val,
          originalFieldType: fieldType
        })
      } else {
        // Revert to field placeholder when cleared
        updateContent(selectedSectionId, contentColumn.id, content.id, {
          type: 'field',
          value: { fieldType: fieldType! },
          originalFieldType: undefined
        })
      }
    }

    return (
      <div className="space-y-4">
        <h3 className="text-sm font-semibold">
          {isFieldOrigin ? (fieldDef?.label ?? fieldType) : 'Text'} Properties
        </h3>

        <div className="space-y-3">
          {/* Field origin: friendly labeled input */}
          {isFieldOrigin && fieldDef && (
            <div className="p-3 bg-blue-50 rounded-lg border border-blue-100">
              <Label className="text-xs font-medium text-blue-700 block mb-1">
                {fieldDef.label}
              </Label>
              <Input
                defaultValue={currentTextValue}
                key={content.id}
                onChange={handleFieldValueChange}
                placeholder={`Enter ${fieldDef.label.toLowerCase()}...`}
                className="mt-1 text-sm"
              />
              <p className="text-[10px] text-blue-400 mt-1">
                Leave empty to use live data at print time.
              </p>
            </div>
          )}

          {/* Plain text content */}
          {!isFieldOrigin && content.type === 'text' && (
            <div>
              <Label className="text-xs">Text Content</Label>
              <Input
                value={content.value as string}
                onChange={(e) => updateContent(selectedSectionId, contentColumn.id, content.id, {
                  value: e.target.value
                })}
                className="mt-1"
              />
            </div>
          )}

          <div>
            <Label className="text-xs">Font Size</Label>
            <Select
              value={content.style.fontSize}
              onValueChange={(value: any) => updateContent(selectedSectionId, contentColumn.id, content.id, {
                style: { ...content.style, fontSize: value }
              })}
            >
              <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="small">Small</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="large">Large</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label className="text-xs">Font Weight</Label>
            <Select
              value={content.style.fontWeight}
              onValueChange={(value: any) => updateContent(selectedSectionId, contentColumn.id, content.id, {
                style: { ...content.style, fontWeight: value }
              })}
            >
              <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="normal">Normal</SelectItem>
                <SelectItem value="bold">Bold</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label className="text-xs">Alignment</Label>
            <Select
              value={contentColumn.alignment}
              onValueChange={(value: any) => updateColumn(selectedSectionId, contentColumn.id, {
                alignment: value
              })}
            >
              <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="left">Left</SelectItem>
                <SelectItem value="center">Center</SelectItem>
                <SelectItem value="right">Right</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>
    )
  }

  // Column properties panel
  if (column) {
    return (
      <div className="space-y-4">
        <h3 className="text-sm font-semibold">Column Properties</h3>
        <div className="space-y-3">
          <div>
            <Label className="text-xs">Width (%)</Label>
            <Input
              type="number"
              value={column.width}
              onChange={(e) => updateColumn(selectedSectionId, column.id, {
                width: parseFloat(e.target.value) || 0
              })}
              className="mt-1"
              min={10}
              max={100}
            />
          </div>
          <div>
            <Label className="text-xs">Alignment</Label>
            <Select
              value={column.alignment}
              onValueChange={(value: any) => updateColumn(selectedSectionId, column.id, { alignment: value })}
            >
              <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="left">Left</SelectItem>
                <SelectItem value="center">Center</SelectItem>
                <SelectItem value="right">Right</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="pt-2">
            <Button variant="destructive" size="sm" className="w-full"
              onClick={() => removeColumn(selectedSectionId, column.id)}>
              <Icon icon="solar:trash-bin-trash-bold-duotone" className="w-4 h-4 mr-2" />
              Remove Column
            </Button>
          </div>
        </div>
      </div>
    )
  }

  // Section properties panel
  return (
    <div className="space-y-4">
      <h3 className="text-sm font-semibold">Section Properties</h3>
      <div className="space-y-4">
        <div>
          <Label className="text-xs">Section Type</Label>
          <p className="text-sm font-medium capitalize mt-1">{section.title ?? section.type}</p>
        </div>

        <div>
          <Label className="text-xs">Height (px)</Label>
          <div className="mt-2">
            <Slider
              value={[section.height]}
              onValueChange={([value]) => updateSection(selectedSectionId, { height: value })}
              min={40}
              max={300}
              step={10}
            />
            <p className="text-xs text-muted-foreground mt-1">{section.height}px</p>
          </div>
        </div>

        <div>
          <Label className="text-xs">Padding (px)</Label>
          <Input
            type="number"
            value={section.properties.padding || 0}
            onChange={(e) => updateSection(selectedSectionId, {
              properties: { ...section.properties, padding: parseInt(e.target.value) || 0 }
            })}
            className="mt-1"
            min={0}
            max={50}
          />
        </div>

        <div className="flex items-center justify-between">
          <Label className="text-xs">Border Top</Label>
          <Switch
            checked={section.properties.borderTop || false}
            onCheckedChange={(checked) => updateSection(selectedSectionId, {
              properties: { ...section.properties, borderTop: checked }
            })}
          />
        </div>

        <div className="flex items-center justify-between">
          <Label className="text-xs">Border Bottom</Label>
          <Switch
            checked={section.properties.borderBottom || false}
            onCheckedChange={(checked) => updateSection(selectedSectionId, {
              properties: { ...section.properties, borderBottom: checked }
            })}
          />
        </div>

        <div className="pt-2 border-t">
          <Label className="text-xs mb-2 block">Columns</Label>
          <Button
            variant="outline"
            size="sm"
            className="w-full"
            onClick={() => addColumn(selectedSectionId)}
            disabled={section.columns.length >= 3}
          >
            <Icon icon="solar:add-circle-bold-duotone" className="w-4 h-4 mr-1" />
            Add Column
          </Button>
          <p className="text-xs text-muted-foreground mt-2">
            {section.columns.length} / 3 columns
          </p>
        </div>
      </div>
    </div>
  )
}
