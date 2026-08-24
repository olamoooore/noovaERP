import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { useTemplateEditorStore } from '@/store/templateEditorStore'
import type {
  Align,
  Block,
  CaseInformationBlock,
  DeliveryDetailBlock,
  ContainerBlock,
  ColumnsBlock,
  DocumentType,
  TableBlock,
  TableColumn,
  TotalsBlock,
  TotalsRow,
  InformationRow,
} from '@/types/template-builder'
import { DOCUMENT_TYPE_LABELS } from '@/types/template-builder'
import { Plus, Trash2, ChevronUp, ChevronDown } from 'lucide-react'
import { MergeFieldPicker } from './MergeFieldPicker'

function findBlock(blocks: Block[], id: string | null): Block | null {
  if (!id) return null
  for (const b of blocks) {
    if (b.id === id) return b
    if ('children' in b && b.children.length) {
      const f = findBlock(b.children, id)
      if (f) return f
    }
  }
  return null
}

const ALIGN_OPTIONS = [
  { value: 'left', label: 'Left' },
  { value: 'center', label: 'Center' },
  { value: 'right', label: 'Right' },
]

const FORMAT_OPTIONS = [
  { value: 'none', label: 'None' },
  { value: 'money', label: 'Money' },
  { value: 'date', label: 'Date' },
  { value: 'upper', label: 'Upper' },
  { value: 'lower', label: 'Lower' },
]

export function PropertyPanel() {
  const template = useTemplateEditorStore((s) => s.template)
  const selectedId = useTemplateEditorStore((s) => s.selectedBlockId)
  const updateBlock = useTemplateEditorStore((s) => s.updateBlock)
  const updateMeta = useTemplateEditorStore((s) => s.updateMeta)

  if (!template) return null

  const block = findBlock(template.layout_config?.blocks ?? [], selectedId)

  return (
    <div className="flex h-full min-h-0 flex-col overflow-hidden">
      <div className="border-b px-3 py-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {block ? `Block · ${block.type}` : 'Document'}
        </h3>
      </div>
      <ScrollArea className="min-h-0 flex-1">
        <div className="space-y-4 p-3">
          {!block ? <DocumentSettings template={template} updateMeta={updateMeta} /> : (
            <BlockEditor block={block} updateBlock={updateBlock} documentType={template.document_type} />
          )}
        </div>
      </ScrollArea>
    </div>
  )
}

/* ── Document-level settings (shown when no block is selected) ─────── */

const DATE_FORMAT_OPTIONS = [
  { value: 'Y-m-d', label: '2026-07-22' },
  { value: 'd/m/Y', label: '22/07/2026' },
  { value: 'd-m-Y', label: '22-07-2026' },
  { value: 'd-M-Y', label: '22-Jul-2026' },
  { value: 'd-F-Y', label: '22-July-2026' },
  { value: 'M d, Y', label: 'Jul 22, 2026' },
  { value: 'F d, Y', label: 'July 22, 2026' },
  { value: 'd/m/y', label: '22/07/26' },
]

function DocumentSettings({ template, updateMeta }: {
  template: { document_type: DocumentType; format: string; orientation: 'portrait' | 'landscape'; default_font: string | null; metadata?: Record<string, unknown> | null }
  updateMeta: (patch: Record<string, unknown>) => void
}) {
  const currentDateFormat = (template.metadata as Record<string, unknown> | null)?.date_format as string ?? 'Y-m-d'

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">Select a block on the canvas to edit it, or adjust document defaults below.</p>
      <Separator />
      <div className="space-y-1.5">
        <Label className="text-xs">Document type</Label>
        <Select value={template.document_type} onValueChange={(v) => updateMeta({ document_type: v })}>
          <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
          <SelectContent>
            {Object.entries(DOCUMENT_TYPE_LABELS).map(([v, l]) => (
              <SelectItem key={v} value={v}>{l}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <div className="space-y-1.5">
          <Label className="text-xs">Paper</Label>
          <Select value={template.format} onValueChange={(v) => updateMeta({ format: v })}>
            <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              {['A4', 'Letter', 'A5', '80mm', '58mm'].map((f) => <SelectItem key={f} value={f}>{f}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label className="text-xs">Orientation</Label>
          <Select value={template.orientation} onValueChange={(v) => updateMeta({ orientation: v })}>
            <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="portrait">Portrait</SelectItem>
              <SelectItem value="landscape">Landscape</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
      <div className="space-y-1.5">
        <Label className="text-xs">Default font</Label>
        <Input
          className="h-8 text-xs"
          value={template.default_font ?? ''}
          placeholder="DejaVu Sans"
          onChange={(e) => updateMeta({ default_font: e.target.value })}
        />
      </div>
      <div className="space-y-1.5">
        <Label className="text-xs">Date format</Label>
        <Select value={currentDateFormat} onValueChange={(v) => updateMeta({ metadata: { ...(template.metadata ?? {}), date_format: v } })}>
          <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
          <SelectContent>
            {DATE_FORMAT_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}

/* ── Per-block editor ──────────────────────────────────────────────── */

function BlockEditor({ block, updateBlock, documentType }: {
  block: Block
  updateBlock: (id: string, patch: Partial<Block>) => void
  documentType: DocumentType
}) {
  switch (block.type) {
    case 'text':
      return (
        <>
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label className="text-xs">Content</Label>
              <MergeFieldPicker documentType={documentType} onInsert={(t) => updateBlock(block.id, { content: `${(block as { content: string }).content} {{${t}}}` })} />
            </div>
            <Textarea
              className="min-h-[100px] text-xs"
              value={(block as { content: string }).content}
              onChange={(e) => updateBlock(block.id, { content: e.target.value })}
            />
            <p className="text-[10px] text-muted-foreground">Supports HTML and {'{{ merge.field }}'} tokens.</p>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <LabeledSelect label="Align" value={(block as { align?: string }).align ?? 'left'} options={ALIGN_OPTIONS} onChange={(v) => updateBlock(block.id, { align: v as Align })} />
            <LabeledNumber label="Font size" value={(block as { size?: number }).size} onChange={(v) => updateBlock(block.id, { size: v })} />
          </div>
          <div className="flex items-center justify-between">
            <Label className="text-xs">Text color</Label>
            <input type="color" value={(block as { color?: string }).color ?? '#1f2937'} onChange={(e) => updateBlock(block.id, { color: e.target.value })} className="h-7 w-12 rounded border" />
          </div>
          <div className="flex items-center justify-between">
            <Label className="text-xs">Bold</Label>
            <Switch checked={!!(block as { bold?: boolean }).bold} onCheckedChange={(v) => updateBlock(block.id, { bold: v })} />
          </div>
        </>
      )

    case 'image':
      return (
        <>
          <div className="space-y-1.5">
            <Label className="text-xs">Image URL</Label>
            <Input className="h-8 text-xs" value={(block as { url: string }).url} onChange={(e) => updateBlock(block.id, { url: e.target.value })} />
            <p className="text-[10px] text-muted-foreground">Use {'{{ company.logo_url }}'} for the tenant logo.</p>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <LabeledNumber label="Width (px)" value={(block as { width?: number }).width} onChange={(v) => updateBlock(block.id, { width: v })} />
            <LabeledSelect label="Align" value={(block as { align?: string }).align ?? 'left'} options={ALIGN_OPTIONS} onChange={(v) => updateBlock(block.id, { align: v as Align })} />
          </div>
        </>
      )

    case 'columns':
      return (
        <div className="space-y-1.5">
          <LabeledNumber label="Gap (px)" value={(block as ColumnsBlock).gap} onChange={(v) => updateBlock(block.id, { gap: v })} />
          <p className="text-[10px] text-muted-foreground">Add child blocks by selecting this block on the canvas.</p>
        </div>
      )

    case 'table': {
      const t = block as TableBlock
      const setColumns = (columns: TableColumn[]) => updateBlock(block.id, { columns })
      return (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <div>
              <Label className="text-xs">Paginate line items</Label>
              <p className="text-[10px] text-muted-foreground">Adds page numbers, per-page subtotals, and final-page footer placement.</p>
            </div>
            <Switch checked={t.paginate !== false} onCheckedChange={(value) => updateBlock(block.id, { paginate: value })} />
          </div>
          {t.paginate !== false && (
            <div className="space-y-2 rounded border p-2">
              <p className="text-[10px] text-muted-foreground">Rows flow according to the actual printable page space. A complete row moves to the next page when it cannot fit.</p>
              <div className="flex items-center justify-between">
                <Label className="text-xs">Show subtotal on every page</Label>
                <Switch checked={t.show_page_subtotal !== false} onCheckedChange={(value) => updateBlock(block.id, { show_page_subtotal: value })} />
              </div>
              {t.show_page_subtotal !== false && (
                <>
                  <div className="space-y-1.5">
                    <Label className="text-xs">Subtotal label</Label>
                    <Input className="h-8 text-xs" value={t.subtotal_label ?? 'Page Subtotal'} onChange={(e) => updateBlock(block.id, { subtotal_label: e.target.value })} />
                  </div>
                  <div className="space-y-1.5">
                    <Label className="text-xs">Line amount field</Label>
                    <Input className="h-8 text-xs font-mono" value={t.subtotal_field ?? 'line_total'} onChange={(e) => updateBlock(block.id, { subtotal_field: e.target.value })} />
                  </div>
                  <LabeledSelect
                    label="Subtotal format"
                    value={t.subtotal_format ?? 'money'}
                    options={FORMAT_OPTIONS}
                    onChange={(value) => updateBlock(block.id, { subtotal_format: value === 'none' ? undefined : value })}
                  />
                  <div className="grid grid-cols-2 gap-2">
                    <LabeledNumber
                      label="Font size (pt)"
                      value={t.subtotal_font_size ?? 9}
                      onChange={(value) => updateBlock(block.id, { subtotal_font_size: value })}
                    />
                    <LabeledNumber
                      label="Gap above totals (mm)"
                      value={t.subtotal_gap_mm ?? 3}
                      onChange={(value) => updateBlock(block.id, { subtotal_gap_mm: value })}
                    />
                  </div>
                </>
              )}
            </div>
          )}
          <Separator />
          <div className="space-y-1.5">
            <Label className="text-xs">Source</Label>
            <Input className="h-8 text-xs" value={t.source} onChange={(e) => updateBlock(block.id, { source: e.target.value })} />
            <p className="text-[10px] text-muted-foreground">Binds a Table block to a list (default: lines).</p>
          </div>
          <Separator />
          <div className="flex items-center justify-between">
            <Label className="text-xs">Columns</Label>
            <Button size="sm" variant="outline" onClick={() => setColumns([...t.columns, { label: 'New', field: 'description', width: '20%' }])}>
              <Plus className="mr-1 h-3 w-3" /> Add
            </Button>
          </div>
          {t.columns.map((col, i) => (
            <div key={i} className="space-y-1.5 rounded border p-2">
              <div className="flex items-center gap-1">
                <Input className="h-7 text-xs" placeholder="Label" value={col.label} onChange={(e) => patchColumn(setColumns, t.columns, i, { label: e.target.value })} />
                <IconBtn title="Up" onClick={() => swap(t.columns, i, i - 1, setColumns)}><ChevronUp className="h-3 w-3" /></IconBtn>
                <IconBtn title="Down" onClick={() => swap(t.columns, i, i + 1, setColumns)}><ChevronDown className="h-3 w-3" /></IconBtn>
                <IconBtn title="Remove" onClick={() => setColumns(t.columns.filter((_, j) => j !== i))}><Trash2 className="h-3 w-3" /></IconBtn>
              </div>
              <Input className="h-7 text-xs" placeholder="Field" value={col.field} onChange={(e) => patchColumn(setColumns, t.columns, i, { field: e.target.value })} />
              <div className="grid grid-cols-2 gap-1.5">
                <Input className="h-7 text-xs" placeholder="Width" value={col.width ?? ''} onChange={(e) => patchColumn(setColumns, t.columns, i, { width: e.target.value })} />
                <Select value={col.align ?? 'left'} onValueChange={(v) => patchColumn(setColumns, t.columns, i, { align: v as Align })}>
                  <SelectTrigger className="h-7 text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>{ALIGN_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
                </Select>
              </div>
              <Select value={col.format ?? 'none'} onValueChange={(v) => patchColumn(setColumns, t.columns, i, { format: v === 'none' ? undefined : v })}>
                <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="Format" /></SelectTrigger>
                <SelectContent>{FORMAT_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          ))}
        </div>
      )
    }

    case 'totals': {
      const t = block as TotalsBlock
      const setRows = (rows: TotalsRow[]) => updateBlock(block.id, { rows })
      return (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs">Rows</Label>
            <Button size="sm" variant="outline" onClick={() => setRows([...t.rows, { label: 'New', field: 'totals.total', format: 'money' }])}>
              <Plus className="mr-1 h-3 w-3" /> Add
            </Button>
          </div>
          {t.rows.map((row, i) => (
            <div key={i} className="space-y-1.5 rounded border p-2">
              <div className="flex items-center gap-1">
                <Input className="h-7 text-xs" placeholder="Label" value={row.label} onChange={(e) => patchRow(setRows, t.rows, i, { label: e.target.value })} />
                <IconBtn title="Up" onClick={() => swap(t.rows, i, i - 1, setRows)}><ChevronUp className="h-3 w-3" /></IconBtn>
                <IconBtn title="Down" onClick={() => swap(t.rows, i, i + 1, setRows)}><ChevronDown className="h-3 w-3" /></IconBtn>
                <IconBtn title="Remove" onClick={() => setRows(t.rows.filter((_, j) => j !== i))}><Trash2 className="h-3 w-3" /></IconBtn>
              </div>
              <Input className="h-7 text-xs" placeholder="Field (e.g. totals.total)" value={row.field} onChange={(e) => patchRow(setRows, t.rows, i, { field: e.target.value })} />
              <div className="flex items-center justify-between">
                <Select value={row.format ?? 'none'} onValueChange={(v) => patchRow(setRows, t.rows, i, { format: v === 'none' ? undefined : v })}>
                  <SelectTrigger className="h-7 w-32 text-xs"><SelectValue placeholder="Format" /></SelectTrigger>
                  <SelectContent>{FORMAT_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
                </Select>
                <div className="flex items-center gap-1.5">
                  <Label className="text-[10px]">Bold</Label>
                  <Switch checked={!!row.bold} onCheckedChange={(v) => patchRow(setRows, t.rows, i, { bold: v })} />
                </div>
              </div>
            </div>
          ))}
        </div>
      )
    }

    case 'case_information': {
      const info = block as CaseInformationBlock
      const setRows = (rows: InformationRow[]) => updateBlock(block.id, { rows })
      return (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs">Information rows</Label>
            <Button size="sm" variant="outline" onClick={() => setRows([...info.rows, { label: 'New field', field: 'invoice.case_number' }])}>
              <Plus className="mr-1 h-3 w-3" /> Add
            </Button>
          </div>
          {info.rows.map((row, i) => (
            <div key={i} className="space-y-1.5 rounded border p-2">
              <div className="flex items-center gap-1">
                <Input className="h-7 text-xs" placeholder="Label" value={row.label} onChange={(e) => patchInformationRow(setRows, info.rows, i, { label: e.target.value })} />
                <IconBtn title="Up" onClick={() => swap(info.rows, i, i - 1, setRows)}><ChevronUp className="h-3 w-3" /></IconBtn>
                <IconBtn title="Down" onClick={() => swap(info.rows, i, i + 1, setRows)}><ChevronDown className="h-3 w-3" /></IconBtn>
                <IconBtn title="Remove" onClick={() => setRows(info.rows.filter((_, j) => j !== i))}><Trash2 className="h-3 w-3" /></IconBtn>
              </div>
              <Input className="h-7 text-xs font-mono" placeholder="Field (e.g. invoice.case_number)" value={row.field} onChange={(e) => patchInformationRow(setRows, info.rows, i, { field: e.target.value })} />
              <Select value={row.format ?? 'none'} onValueChange={(v) => patchInformationRow(setRows, info.rows, i, { format: v === 'none' ? undefined : v })}>
                <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="Format" /></SelectTrigger>
                <SelectContent>{FORMAT_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          ))}
        </div>
      )
    }

    case 'delivery_detail': {
      const info = block as DeliveryDetailBlock
      const setRows = (rows: InformationRow[]) => updateBlock(block.id, { rows })
      return (
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs">Delivery fields</Label>
            <Button size="sm" variant="outline" onClick={() => setRows([...info.rows, { label: 'New field', field: 'delivery_order.number' }])}>
              <Plus className="mr-1 h-3 w-3" /> Add
            </Button>
          </div>
          {info.rows.map((row, i) => (
            <div key={i} className="space-y-1.5 rounded border p-2">
              <div className="flex items-center gap-1">
                <Input className="h-7 text-xs" placeholder="Label" value={row.label} onChange={(e) => patchInformationRow(setRows, info.rows, i, { label: e.target.value })} />
                <IconBtn title="Up" onClick={() => swap(info.rows, i, i - 1, setRows)}><ChevronUp className="h-3 w-3" /></IconBtn>
                <IconBtn title="Down" onClick={() => swap(info.rows, i, i + 1, setRows)}><ChevronDown className="h-3 w-3" /></IconBtn>
                <IconBtn title="Remove" onClick={() => setRows(info.rows.filter((_, j) => j !== i))}><Trash2 className="h-3 w-3" /></IconBtn>
              </div>
              <Input className="h-7 text-xs font-mono" placeholder="Field" value={row.field} onChange={(e) => patchInformationRow(setRows, info.rows, i, { field: e.target.value })} />
              <Select value={row.format ?? 'none'} onValueChange={(v) => patchInformationRow(setRows, info.rows, i, { format: v === 'none' ? undefined : v })}>
                <SelectTrigger className="h-7 text-xs"><SelectValue placeholder="Format" /></SelectTrigger>
                <SelectContent>{FORMAT_OPTIONS.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
              </Select>
            </div>
          ))}
        </div>
      )
    }

    case 'spacer':
      return <LabeledNumber label="Height (px)" value={(block as { height?: number }).height} onChange={(v) => updateBlock(block.id, { height: v })} />

    case 'divider':
      return <p className="text-xs text-muted-foreground">A horizontal rule. No properties.</p>

    case 'header':
      return <p className="text-xs text-muted-foreground">This block repeats at the top of every generated page. The system page number is added above it.</p>

    case 'footer':
      return <p className="text-xs text-muted-foreground">This block is anchored to the bottom of the final PDF page only.</p>

    case 'conditional':
      return (
        <div className="space-y-1.5">
          <Label className="text-xs">Condition</Label>
          <Input className="h-8 text-xs font-mono" value={(block as ContainerBlock).condition ?? ''} onChange={(e) => updateBlock(block.id, { condition: e.target.value })} />
          <p className="text-[10px] text-muted-foreground">e.g. {'{{ totals.discount }} > 0'}. Children render only when true.</p>
        </div>
      )
  }
}

/* ── small helpers ─────────────────────────────────────────────────── */

function patchColumn(setColumns: (c: TableColumn[]) => void, cols: TableColumn[], i: number, patch: Partial<TableColumn>) {
  setColumns(cols.map((c, j) => (j === i ? { ...c, ...patch } : c)))
}
function patchRow(setRows: (r: TotalsRow[]) => void, rows: TotalsRow[], i: number, patch: Partial<TotalsRow>) {
  setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
}
function patchInformationRow(setRows: (r: InformationRow[]) => void, rows: InformationRow[], i: number, patch: Partial<InformationRow>) {
  setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
}
function swap<T>(arr: T[], i: number, j: number, set: (a: T[]) => void) {
  if (j < 0 || j >= arr.length) return
  const next = [...arr]
  ;[next[i], next[j]] = [next[j], next[i]]
  set(next)
}

function LabeledSelect({ label, value, options, onChange }: { label: string; value: string; options: { value: string; label: string }[]; onChange: (v: string) => void }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs">{label}</Label>
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
        <SelectContent>{options.map((o) => <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>)}</SelectContent>
      </Select>
    </div>
  )
}

function LabeledNumber({ label, value, onChange }: { label: string; value?: number; onChange: (v: number | undefined) => void }) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs">{label}</Label>
      <Input className="h-8 text-xs" type="number" value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? undefined : Number(e.target.value))} />
    </div>
  )
}

function IconBtn({ children, onClick, title }: { children: React.ReactNode; onClick: () => void; title: string }) {
  return (
    <button onClick={onClick} title={title} className="p-1 text-muted-foreground hover:bg-accent hover:text-foreground">
      {children}
    </button>
  )
}
