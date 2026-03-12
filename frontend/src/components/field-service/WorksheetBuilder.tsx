import { useState, useCallback } from 'react'
import { v4 as uuidv4 } from 'uuid'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { GripVertical, Trash2, Plus, X } from 'lucide-react'
import type { WorksheetField } from './WorksheetForm'

interface WorksheetBuilderProps {
  fields: WorksheetField[]
  onChange: (fields: WorksheetField[]) => void
}

const FIELD_TYPES: { value: WorksheetField['type']; label: string }[] = [
  { value: 'text',      label: 'Text (multi-line)' },
  { value: 'number',    label: 'Number' },
  { value: 'checkbox',  label: 'Checkbox (Yes/No)' },
  { value: 'dropdown',  label: 'Dropdown' },
  { value: 'date',      label: 'Date' },
  { value: 'photo',     label: 'Photo' },
  { value: 'signature', label: 'Signature' },
]

export default function WorksheetBuilder({ fields, onChange }: WorksheetBuilderProps) {
  const [dragging, setDragging] = useState<string | null>(null)
  const [dragOver, setDragOver] = useState<string | null>(null)

  const addField = () => {
    const newField: WorksheetField = {
      id:         uuidv4(),
      label:      '',
      type:       'text',
      required:   false,
      sort_order: fields.length,
    }
    onChange([...fields, newField])
  }

  const updateField = (id: string, patch: Partial<WorksheetField>) => {
    onChange(fields.map((f) => (f.id === id ? { ...f, ...patch } : f)))
  }

  const removeField = (id: string) => {
    onChange(fields.filter((f) => f.id !== id))
  }

  // ── Drag-and-drop reorder ──────────────────────────────────────────────────
  const handleDragStart = (id: string) => setDragging(id)
  const handleDragEnter = (id: string) => setDragOver(id)
  const handleDragEnd   = () => {
    if (dragging && dragOver && dragging !== dragOver) {
      const from = fields.findIndex((f) => f.id === dragging)
      const to   = fields.findIndex((f) => f.id === dragOver)
      const reordered = [...fields]
      const [moved] = reordered.splice(from, 1)
      reordered.splice(to, 0, moved)
      onChange(reordered.map((f, i) => ({ ...f, sort_order: i })))
    }
    setDragging(null)
    setDragOver(null)
  }

  return (
    <div className="space-y-3">
      {fields.length === 0 && (
        <div className="rounded-lg border-2 border-dashed border-gray-200 p-8 text-center text-sm text-gray-400">
          No fields yet — add one below.
        </div>
      )}

      {fields.map((field) => (
        <FieldRow
          key={field.id}
          field={field}
          isDragging={dragging === field.id}
          isDragOver={dragOver === field.id}
          onUpdate={(patch) => updateField(field.id, patch)}
          onRemove={() => removeField(field.id)}
          onDragStart={() => handleDragStart(field.id)}
          onDragEnter={() => handleDragEnter(field.id)}
          onDragEnd={handleDragEnd}
        />
      ))}

      <Button type="button" variant="outline" size="sm" onClick={addField} className="w-full">
        <Plus className="mr-2 h-4 w-4" />
        Add field
      </Button>
    </div>
  )
}

// ── Single field row ──────────────────────────────────────────────────────────

function FieldRow({
  field,
  isDragging,
  isDragOver,
  onUpdate,
  onRemove,
  onDragStart,
  onDragEnter,
  onDragEnd,
}: {
  field: WorksheetField
  isDragging: boolean
  isDragOver: boolean
  onUpdate: (patch: Partial<WorksheetField>) => void
  onRemove: () => void
  onDragStart: () => void
  onDragEnter: () => void
  onDragEnd: () => void
}) {
  const [showOptions, setShowOptions] = useState(false)
  const [newOption, setNewOption]     = useState('')

  const addOption = () => {
    if (!newOption.trim()) return
    onUpdate({ options: [...(field.options ?? []), newOption.trim()] })
    setNewOption('')
  }

  const removeOption = (opt: string) => {
    onUpdate({ options: (field.options ?? []).filter((o) => o !== opt) })
  }

  return (
    <div
      draggable
      onDragStart={onDragStart}
      onDragEnter={onDragEnter}
      onDragEnd={onDragEnd}
      onDragOver={(e) => e.preventDefault()}
      className={`group rounded-lg border bg-white p-4 shadow-sm transition-all
        ${isDragging  ? 'opacity-40 scale-95'          : ''}
        ${isDragOver  ? 'ring-2 ring-blue-400'          : ''}
      `}
    >
      <div className="flex items-start gap-3">
        {/* Drag handle */}
        <div className="mt-1 cursor-grab text-gray-300 group-hover:text-gray-500">
          <GripVertical className="h-5 w-5" />
        </div>

        <div className="flex-1 space-y-3">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {/* Label */}
            <div className="col-span-2 space-y-1">
              <Label className="text-xs">Label</Label>
              <Input
                value={field.label}
                onChange={(e) => onUpdate({ label: e.target.value })}
                placeholder="Field label"
                className="h-8"
              />
            </div>

            {/* Type */}
            <div className="space-y-1">
              <Label className="text-xs">Type</Label>
              <Select
                value={field.type}
                onValueChange={(v) => {
                  onUpdate({ type: v as WorksheetField['type'], options: undefined })
                  setShowOptions(false)
                }}
              >
                <SelectTrigger className="h-8 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FIELD_TYPES.map((t) => (
                    <SelectItem key={t.value} value={t.value} className="text-xs">
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Required toggle */}
            <div className="space-y-1">
              <Label className="text-xs">Required</Label>
              <div className="flex h-8 items-center">
                <Switch
                  checked={field.required ?? false}
                  onCheckedChange={(v) => onUpdate({ required: v })}
                />
              </div>
            </div>
          </div>

          {/* Dropdown options */}
          {field.type === 'dropdown' && (
            <div className="space-y-2">
              <div className="flex flex-wrap gap-1">
                {(field.options ?? []).map((opt) => (
                  <Badge key={opt} variant="secondary" className="gap-1 text-xs">
                    {opt}
                    <button type="button" onClick={() => removeOption(opt)}>
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                ))}
              </div>
              <div className="flex gap-2">
                <Input
                  value={newOption}
                  onChange={(e) => setNewOption(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addOption())}
                  placeholder="Add option…"
                  className="h-7 text-xs"
                />
                <Button type="button" variant="outline" size="sm" onClick={addOption} className="h-7 text-xs">
                  Add
                </Button>
              </div>
            </div>
          )}

          {/* Placeholder (text/number/dropdown) */}
          {['text', 'number', 'dropdown'].includes(field.type) && (
            <div className="space-y-1">
              <Label className="text-xs text-gray-400">Placeholder (optional)</Label>
              <Input
                value={field.placeholder ?? ''}
                onChange={(e) => onUpdate({ placeholder: e.target.value })}
                placeholder="Hint text shown inside the field…"
                className="h-7 text-xs"
              />
            </div>
          )}
        </div>

        {/* Remove */}
        <button
          type="button"
          onClick={onRemove}
          className="mt-1 rounded p-1 text-gray-300 hover:bg-red-50 hover:text-red-500 transition-colors"
        >
          <Trash2 className="h-4 w-4" />
        </button>
      </div>
    </div>
  )
}
