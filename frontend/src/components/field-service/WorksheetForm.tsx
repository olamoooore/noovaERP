import { useState } from 'react'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import SignaturePad from '@/components/signature/SignaturePad'

export interface WorksheetField {
  id: string
  label: string
  type: 'text' | 'number' | 'checkbox' | 'dropdown' | 'date' | 'photo' | 'signature'
  required?: boolean
  options?: string[]
  placeholder?: string
  sort_order?: number
}

export interface WorksheetFormProps {
  fields: WorksheetField[]
  values: Record<string, unknown>
  onChange: (fieldId: string, value: unknown) => void
  disabled?: boolean
}

export default function WorksheetForm({ fields, values, onChange, disabled = false }: WorksheetFormProps) {
  const sorted = [...fields].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))

  return (
    <div className="space-y-5">
      {sorted.map((field) => (
        <FieldRenderer
          key={field.id}
          field={field}
          value={values[field.id]}
          onChange={(v) => onChange(field.id, v)}
          disabled={disabled}
        />
      ))}
    </div>
  )
}

// ── Individual field renderer ─────────────────────────────────────────────────

function FieldRenderer({
  field,
  value,
  onChange,
  disabled,
}: {
  field: WorksheetField
  value: unknown
  onChange: (v: unknown) => void
  disabled: boolean
}) {
  const labelEl = (
    <Label htmlFor={field.id} className="font-medium">
      {field.label}
      {field.required && <span className="ml-1 text-red-500">*</span>}
    </Label>
  )

  switch (field.type) {
    case 'text':
      return (
        <div className="space-y-1">
          {labelEl}
          <Textarea
            id={field.id}
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            placeholder={field.placeholder ?? ''}
            disabled={disabled}
            rows={2}
          />
        </div>
      )

    case 'number':
      return (
        <div className="space-y-1">
          {labelEl}
          <Input
            id={field.id}
            type="number"
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            placeholder={field.placeholder ?? ''}
            disabled={disabled}
          />
        </div>
      )

    case 'checkbox':
      return (
        <div className="flex items-center gap-2">
          <Checkbox
            id={field.id}
            checked={Boolean(value)}
            onCheckedChange={(checked) => onChange(checked)}
            disabled={disabled}
          />
          <Label htmlFor={field.id} className="cursor-pointer">
            {field.label}
            {field.required && <span className="ml-1 text-red-500">*</span>}
          </Label>
        </div>
      )

    case 'dropdown':
      return (
        <div className="space-y-1">
          {labelEl}
          <Select
            value={(value as string) ?? ''}
            onValueChange={(v) => onChange(v)}
            disabled={disabled}
          >
            <SelectTrigger id={field.id}>
              <SelectValue placeholder={field.placeholder ?? 'Select…'} />
            </SelectTrigger>
            <SelectContent>
              {(field.options ?? []).map((opt) => (
                <SelectItem key={opt} value={opt}>
                  {opt}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )

    case 'date':
      return (
        <div className="space-y-1">
          {labelEl}
          <Input
            id={field.id}
            type="date"
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
            disabled={disabled}
          />
        </div>
      )

    case 'photo':
      return (
        <PhotoField
          field={field}
          value={value as string | null}
          onChange={onChange}
          disabled={disabled}
        />
      )

    case 'signature':
      return (
        <div className="space-y-1">
          {labelEl}
          <SignaturePad
            value={(value as string) ?? null}
            onChange={onChange}
            disabled={disabled}
            height={120}
          />
        </div>
      )

    default:
      return null
  }
}

// ── Photo upload helper ───────────────────────────────────────────────────────

function PhotoField({
  field,
  value,
  onChange,
  disabled,
}: {
  field: WorksheetField
  value: string | null
  onChange: (v: unknown) => void
  disabled: boolean
}) {
  const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = (ev) => onChange(ev.target?.result as string)
    reader.readAsDataURL(file)
  }

  return (
    <div className="space-y-1">
      <Label htmlFor={field.id} className="font-medium">
        {field.label}
        {field.required && <span className="ml-1 text-red-500">*</span>}
      </Label>
      {value ? (
        <div className="space-y-2">
          <img src={value} alt="captured" className="max-h-48 rounded-md border border-gray-200 object-contain" />
          {!disabled && (
            <button
              type="button"
              className="text-xs text-red-500 underline"
              onClick={() => onChange(null)}
            >
              Remove photo
            </button>
          )}
        </div>
      ) : (
        !disabled && (
          <Input
            id={field.id}
            type="file"
            accept="image/*"
            capture="environment"
            onChange={handleFile}
          />
        )
      )}
    </div>
  )
}
