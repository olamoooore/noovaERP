// Template Builder — frontend type model.
// Mirrors the backend layout_config block tree consumed by BlockRenderer.

export type BlockType =
  | 'text'
  | 'image'
  | 'columns'
  | 'table'
  | 'totals'
  | 'spacer'
  | 'divider'
  | 'header'
  | 'footer'
  | 'conditional'

export type Align = 'left' | 'center' | 'right'

export interface BlockBase {
  id: string
  type: BlockType
}

export interface TextBlock extends BlockBase {
  type: 'text'
  content: string
  align?: Align
  size?: number
  color?: string
  bold?: boolean
}

export interface ImageBlock extends BlockBase {
  type: 'image'
  url: string
  width?: number
  align?: Align
}

export interface ColumnsBlock extends BlockBase {
  type: 'columns'
  gap?: number
  children: Block[]
}

export interface TableColumn {
  label: string
  field: string
  width?: string
  align?: Align
  format?: string
}

export interface TableBlock extends BlockBase {
  type: 'table'
  source: string
  columns: TableColumn[]
}

export interface TotalsRow {
  label: string
  field: string
  format?: string
  bold?: boolean
}

export interface TotalsBlock extends BlockBase {
  type: 'totals'
  rows: TotalsRow[]
}

export interface SpacerBlock extends BlockBase {
  type: 'spacer'
  height?: number
}

export interface DividerBlock extends BlockBase {
  type: 'divider'
}

export interface ContainerBlock extends BlockBase {
  type: 'header' | 'footer' | 'conditional'
  children: Block[]
  condition?: string
}

export type Block =
  | TextBlock
  | ImageBlock
  | ColumnsBlock
  | TableBlock
  | TotalsBlock
  | SpacerBlock
  | DividerBlock
  | ContainerBlock

export interface LayoutConfig {
  blocks: Block[]
}

export type DocumentType = 'invoice' | 'quotation' | 'purchase_order' | 'receipt'
export type TemplateMode = 'block' | 'code' | 'blade'
export type TemplateStatus = 'draft' | 'published' | 'archived'

export interface DocumentTemplate {
  id: string
  document_type: DocumentType
  code: string | null
  name: string
  description: string | null
  mode: TemplateMode
  format: string
  orientation: 'portrait' | 'landscape'
  margin: Record<string, number> | null
  default_font: string | null
  default_locale: string | null
  layout_config: LayoutConfig | null
  html: string | null
  css: string | null
  status: TemplateStatus
  published_version: number
  is_system_default: boolean
  metadata: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface MergeField {
  token: string
  label: string
}

export interface MergeFieldGroup {
  group: string
  fields: MergeField[]
}

export interface TemplateVersion {
  id: string
  version: number
  change_note: string | null
  is_published: boolean
  created_at: string | null
}

export interface Starter {
  document_type: DocumentType
  name: string
  mode: TemplateMode
  format: string
  orientation: 'portrait' | 'landscape'
  description: string
}

export const DOCUMENT_TYPE_LABELS: Record<DocumentType, string> = {
  invoice: 'Invoice',
  quotation: 'Quotation',
  purchase_order: 'Purchase Order',
  receipt: 'Receipt',
}
