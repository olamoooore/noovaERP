import { useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'

export interface ImportTemplateColumn {
  name: string
  required?: boolean
  description: string
}

export interface ImportRowIssue {
  row: number
  message: string
}

/**
 * Shared building blocks for every CSV import page (products, customers,
 * vendors, invoices, payments, journals, assets, …). Keeping the shell here
 * means all import pages stay visually identical and a fix lands everywhere.
 */

export function ImportPageHeader({
  title,
  subtitle,
  backTo,
  backLabel = 'Back',
}: {
  title: string
  subtitle: string
  backTo: string
  backLabel?: string
}) {
  return (
    <div>
      <HeaderActionButton variant="outline" icon="solar:alt-arrow-left-bold-duotone" asChild className="mb-3">
        <Link to={backTo}>{backLabel}</Link>
      </HeaderActionButton>
      <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
      <p className="mt-1 text-muted-foreground">{subtitle}</p>
    </div>
  )
}

export function ImportStepCard({
  step,
  title,
  description,
  icon,
  tone = 'bg-primary/10',
  glyph = 'text-primary',
  children,
}: {
  step: number
  title: string
  description: string
  icon: string
  tone?: string
  glyph?: string
  children: ReactNode
}) {
  return (
    <Card className="overflow-hidden rounded-xl">
      <CardHeader className="border-b">
        <div className="flex items-center gap-3">
          <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tone}`}>
            <Icon icon={icon} className={`h-5 w-5 ${glyph}`} />
          </div>
          <div>
            <CardTitle className="text-base">Step {step}: {title}</CardTitle>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4 p-4">{children}</CardContent>
    </Card>
  )
}

export function ImportFieldTable({ columns, hint = 'Required fields are marked with an asterisk (*).' }: { columns: ImportTemplateColumn[]; hint?: string }) {
  return (
    <div>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">Template Fields</h3>
          <p className="text-xs text-muted-foreground">{hint}</p>
        </div>
        <Badge variant="outline" className="border-transparent bg-muted font-normal text-muted-foreground">
          {columns.length} fields
        </Badge>
      </div>
      <div className="max-h-[400px] overflow-y-auto rounded-xl border border-slate-200/80 dark:border-slate-800">
        <Table>
          <TableHeader>
            <TableRow className="bg-muted/50">
              <TableHead className="w-[220px]">Field</TableHead>
              <TableHead>Description</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {columns.map((column) => (
              <TableRow key={column.name}>
                <TableCell className="font-mono text-xs font-medium">
                  {column.name}
                  {column.required && <span className="ml-1 text-destructive">*</span>}
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">{column.description}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}

export function ImportDropzone({
  selectedFile,
  onFile,
  onClear,
  browseLabel = 'Browse Files',
  accept = '.csv,text/csv',
  allowedExtensions = ['.csv'],
}: {
  selectedFile: File | null
  onFile: (file: File) => void
  onClear: () => void
  browseLabel?: string
  accept?: string
  allowedExtensions?: string[]
}) {
  const { toast } = useToast()
  const [dragActive, setDragActive] = useState(false)
  const inputRef = useRef<HTMLInputElement | null>(null)

  const acceptFile = (file?: File | null) => {
    if (!file) return
    const name = file.name.toLowerCase()
    if (!(file.type === 'text/csv' || allowedExtensions.some((extension) => name.endsWith(extension)))) {
      toast({
        title: 'Invalid file type',
        description: 'Please upload a CSV file.',
        variant: 'destructive',
      })
      return
    }
    onFile(file)
  }

  return (
    <div
      className={`flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors ${
        dragActive ? 'border-primary bg-primary/5' : 'border-slate-300 dark:border-slate-700'
      } ${selectedFile ? 'bg-muted/40' : ''}`}
      onDragEnter={(event) => { event.preventDefault(); event.stopPropagation(); setDragActive(true) }}
      onDragOver={(event) => { event.preventDefault(); event.stopPropagation(); setDragActive(true) }}
      onDragLeave={(event) => { event.preventDefault(); event.stopPropagation(); setDragActive(false) }}
      onDrop={(event) => {
        event.preventDefault()
        event.stopPropagation()
        setDragActive(false)
        acceptFile(event.dataTransfer.files?.[0])
      }}
    >
      <input
        ref={inputRef}
        type="file"
        accept={accept}
        className="hidden"
        onChange={(event) => {
          acceptFile(event.target.files?.[0])
          event.currentTarget.value = ''
        }}
      />

      {selectedFile ? (
        <>
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
            <Icon icon="solar:check-circle-bold-duotone" className="h-6 w-6" />
          </div>
          <div>
            <p className="text-sm font-medium">{selectedFile.name}</p>
            <p className="text-xs text-muted-foreground">{(selectedFile.size / 1024).toFixed(2)} KB</p>
          </div>
          <div className="flex flex-wrap items-center justify-center gap-2">
            <HeaderActionButton variant="outline" icon="solar:folder-open-bold-duotone" onClick={() => inputRef.current?.click()}>
              Change File
            </HeaderActionButton>
            <HeaderActionButton variant="outline" icon="solar:trash-bin-trash-bold-duotone" onClick={onClear}>
              Remove
            </HeaderActionButton>
          </div>
        </>
      ) : (
        <>
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-muted">
            <Icon icon="solar:cloud-upload-bold-duotone" className="h-6 w-6 text-muted-foreground" />
          </div>
          <div>
            <p className="text-sm font-medium">Drag and drop your CSV file here</p>
            <p className="text-xs text-muted-foreground">or click the button below to browse</p>
          </div>
          <HeaderActionButton variant="outline" icon="solar:folder-open-bold-duotone" onClick={() => inputRef.current?.click()}>
            {browseLabel}
          </HeaderActionButton>
        </>
      )}
    </div>
  )
}

export function ImportResultTiles({
  tiles,
}: {
  tiles: Array<{ label: string; value: number; tone: string; glyph: string }>
}) {
  return (
    <div className={`grid gap-3 ${tiles.length === 3 ? 'grid-cols-3' : tiles.length === 2 ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-4'}`}>
      {tiles.map((tile) => (
        <div key={tile.label} className={`rounded-xl border px-4 py-3 text-center ${tile.tone}`}>
          <p className={`text-2xl font-bold tabular-nums ${tile.glyph}`}>{tile.value}</p>
          <p className="text-xs text-muted-foreground">{tile.label}</p>
        </div>
      ))}
    </div>
  )
}

const IMPORT_TILE_TONES = {
  created: { tone: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40', glyph: 'text-emerald-600 dark:text-emerald-400' },
  updated: { tone: 'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40', glyph: 'text-blue-600 dark:text-blue-400' },
  errors: { tone: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', glyph: 'text-red-600 dark:text-red-400' },
  rejected: { tone: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40', glyph: 'text-amber-600 dark:text-amber-400' },
} as const

export function ImportErrorList({ errors, warnings = [], errorsTitle = 'Import Errors' }: { errors: ImportRowIssue[]; warnings?: ImportRowIssue[]; errorsTitle?: string }) {
  return (
    <>
      {errors.length > 0 && (
        <Alert variant="destructive">
          <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
          <AlertTitle>{errorsTitle}</AlertTitle>
          <AlertDescription>
            <div className="mt-2 max-h-[240px] space-y-1 overflow-y-auto text-sm">
              {errors.map((error, index) => (
                <div key={index} className="flex gap-2">
                  <span className="font-medium">Row {error.row}:</span>
                  <span>{error.message}</span>
                </div>
              ))}
            </div>
          </AlertDescription>
        </Alert>
      )}
      {warnings.length > 0 && (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
          <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
          <AlertTitle>Warnings</AlertTitle>
          <AlertDescription>
            <div className="mt-2 max-h-[240px] space-y-1 overflow-y-auto text-sm">
              {warnings.map((warning, index) => (
                <div key={index} className="flex gap-2">
                  <span className="font-medium">Row {warning.row}:</span>
                  <span>{warning.message}</span>
                </div>
              ))}
            </div>
          </AlertDescription>
        </Alert>
      )}
    </>
  )
}

export function ImportResults({
  created,
  updated,
  errors,
  warnings = [],
  onReset,
  resetLabel = 'Import Another File',
  doneTo,
  doneLabel,
  doneIcon = 'solar:box-bold-duotone',
  summaryHint,
}: {
  created: number
  updated?: number
  errors: ImportRowIssue[]
  warnings?: ImportRowIssue[]
  onReset: () => void
  resetLabel?: string
  doneTo: string
  doneLabel: string
  doneIcon?: string
  summaryHint?: string
}) {
  const hasSuccess = created > 0 || (updated || 0) > 0
  const successParts = [
    created > 0 ? `${created} created` : null,
    (updated || 0) > 0 ? `${updated} updated` : null,
    errors.length > 0 ? `${errors.length} errors` : null,
    warnings.length > 0 ? `${warnings.length} warnings` : null,
  ].filter(Boolean).join(', ')

  const tiles: Array<{ label: string; value: number; tone: string; glyph: string }> = [
    { label: 'Created', value: created, ...IMPORT_TILE_TONES.created },
  ]
  if (updated !== undefined) {
    tiles.push({ label: 'Updated', value: updated, ...IMPORT_TILE_TONES.updated })
  }
  tiles.push({ label: 'Errors', value: errors.length, ...IMPORT_TILE_TONES.errors })

  return (
    <div className="space-y-4 border-t pt-4">
      <div className="flex items-center gap-3">
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
          hasSuccess
            ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
            : 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'
        }`}>
          <Icon icon={hasSuccess ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'} className="h-5 w-5" />
        </div>
        <div>
          <p className="text-sm font-semibold">Import results</p>
          <p className="text-xs text-muted-foreground">
            {summaryHint || successParts || 'Nothing was imported.'}
          </p>
        </div>
      </div>

      <ImportResultTiles tiles={tiles} />

      <ImportErrorList errors={errors} warnings={warnings} />

      <div className="flex flex-wrap items-center gap-2">
        <HeaderActionButton variant="outline" icon="solar:upload-bold-duotone" onClick={onReset}>
          {resetLabel}
        </HeaderActionButton>
        <HeaderActionButton icon={doneIcon} asChild>
          <Link to={doneTo}>{doneLabel}</Link>
        </HeaderActionButton>
      </div>
    </div>
  )
}