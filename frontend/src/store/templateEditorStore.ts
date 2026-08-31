import { create } from 'zustand'
import { nanoid } from 'nanoid'
import type {
  Block,
  BlockType,
  ContainerBlock,
  ColumnsBlock,
  DocumentTemplate,
  LayoutConfig,
} from '@/types/template-builder'

/* ── pure tree helpers ──────────────────────────────────────────────── */

function walk(blocks: Block[], id: string): Block | null {
  for (const b of blocks) {
    if (b.id === id) return b
    if ('children' in b && b.children.length) {
      const found = walk(b.children, id)
      if (found) return found
    }
  }
  return null
}

/** True when `maybeDescendantId` lives anywhere inside the subtree of `ancestorId`. */
function isDescendant(blocks: Block[], ancestorId: string, maybeDescendantId: string): boolean {
  const ancestor = walk(blocks, ancestorId)
  if (!ancestor || !('children' in ancestor)) return false
  const children = (ancestor as ContainerBlock | ColumnsBlock).children
  return !!walk(children, maybeDescendantId)
}

function updateInTree(blocks: Block[], id: string, patch: Partial<Block>): Block[] {
  return blocks.map((b) => {
    if (b.id === id) return { ...b, ...patch } as Block
    if ('children' in b && b.children.length) {
      return { ...b, children: updateInTree(b.children, id, patch) } as Block
    }
    return b
  })
}

function removeFromTree(blocks: Block[], id: string): Block[] {
  return blocks
    .filter((b) => b.id !== id)
    .map((b) =>
      'children' in b && b.children.length
        ? ({ ...b, children: removeFromTree(b.children, id) } as Block)
        : b,
    )
}

function insertIntoTree(blocks: Block[], block: Block, parentId: string | null, index: number): Block[] {
  if (parentId === null) {
    const next = [...blocks]
    const at = index < 0 ? next.length : Math.min(index, next.length)
    next.splice(at, 0, block)
    return next
  }
  return blocks.map((b) => {
    if (b.id === parentId && 'children' in b && b.children) {
      const next = [...b.children]
      const at = index < 0 ? next.length : Math.min(index, next.length)
      next.splice(at, 0, block)
      return { ...b, children: next } as Block
    }
    if ('children' in b && b.children.length) {
      return { ...b, children: insertIntoTree(b.children, block, parentId, index) } as Block
    }
    return b
  })
}

function findLocation(
  blocks: Block[],
  id: string,
  parentId: string | null = null,
): { parentId: string | null; index: number } | null {
  const idx = blocks.findIndex((b) => b.id === id)
  if (idx >= 0) return { parentId, index: idx }
  for (const b of blocks) {
    if ('children' in b && b.children.length) {
      const found = findLocation(b.children, id, b.id)
      if (found) return found
    }
  }
  return null
}

function ensureIds(blocks: Block[]): Block[] {
  return blocks.map((b) => {
    const withId: Block = { ...b, id: b.id || nanoid() } as Block
    if ('children' in withId && (withId as ContainerBlock | ColumnsBlock).children) {
      (withId as ContainerBlock | ColumnsBlock).children = ensureIds(
        (withId as ContainerBlock | ColumnsBlock).children,
      )
    }
    return withId
  })
}

function normalizeFinalSection(blocks: Block[]): Block[] {
  const totalsIndex = blocks.map((block) => block.type).lastIndexOf('totals')
  const footerIndex = blocks.map((block) => block.type).lastIndexOf('footer')
  if (totalsIndex < 0 || footerIndex < 0) return blocks

  const totals = blocks[totalsIndex]
  const footer = blocks[footerIndex]
  const remaining = blocks.filter((_, index) => index !== totalsIndex && index !== footerIndex)

  return [...remaining, totals, footer]
}

/* ── block factory ─────────────────────────────────────────────────── */

export function createBlock(type: BlockType): Block {
  const id = nanoid()
  switch (type) {
    case 'text':
      return { id, type, content: 'New text block' }
    case 'image':
      return { id, type, url: '{{ company.logo_url }}', width: 120, align: 'left', offset_x: 0 }
    case 'columns':
      return { id, type, gap: 8, children: [] }
    case 'table':
      return {
        id,
        type,
        source: 'lines',
        paginate: true,
        show_page_subtotal: true,
        subtotal_label: 'Page Subtotal',
        subtotal_field: 'line_total',
        subtotal_format: 'money',
        subtotal_font_size: 9,
        subtotal_gap_mm: 3,
        columns: [
          { label: '#', field: 'index', width: '4%', align: 'center' },
          { label: 'SKU', field: 'sku', width: '10%' },
          { label: 'Description', field: 'description', width: '34%' },
          { label: 'Qty', field: 'quantity', width: '8%', align: 'right' },
          { label: 'Unit Price', field: 'unit_price', width: '12%', align: 'right', format: 'money' },
          { label: 'Tax %', field: 'tax_rate', width: '8%', align: 'right' },
          { label: 'Discount', field: 'discount', width: '10%', align: 'right', format: 'money' },
          { label: 'Line Total', field: 'line_total', width: '14%', align: 'right', format: 'money' },
        ],
      }
    case 'case_information':
      return {
        id,
        type,
        rows: [
          { label: 'Case Number', field: 'invoice.case_number' },
          { label: 'Date of Case', field: 'invoice.date_of_case', format: 'date' },
          { label: 'Case Location', field: 'invoice.case_location' },
          { label: 'Patient Name', field: 'invoice.patient_name' },
          { label: 'Doctor', field: 'invoice.doctor' },
          { label: 'Requestor', field: 'invoice.requestor' },
          { label: 'Delivery Date', field: 'invoice.delivery_date', format: 'date' },
          { label: 'PO Number', field: 'invoice.po_number' },
        ],
      }
    case 'delivery_detail':
      return {
        id,
        type,
        rows: [
          { label: 'Salesperson', field: 'delivery_order.salesperson' },
          { label: 'PO Number', field: 'delivery_order.po_number' },
          { label: 'Invoice No', field: 'invoice.number' },
          { label: 'Quotation No', field: 'quotation.number' },
          { label: 'Payment Terms', field: 'delivery_order.payment_terms' },
          { label: 'Due Date', field: 'delivery_order.due_date', format: 'date' },
        ],
      }
    case 'quotation_detail':
      return {
        id,
        type,
        rows: [
          { label: 'Quotation No', field: 'quotation.number' },
          { label: 'Quotation Date', field: 'quotation.issue_date', format: 'date' },
          { label: 'Valid Until', field: 'quotation.valid_until', format: 'date' },
          { label: 'Customer Reference', field: 'quotation.customer_reference' },
          { label: 'Salesperson', field: 'quotation.salesperson' },
          { label: 'Delivery Lead Time', field: 'quotation.delivery_lead_time' },
          { label: 'Shipping Method', field: 'quotation.shipping_method' },
          { label: 'Validity', field: 'quotation.validity' },
        ],
      }
    case 'totals':
      return {
        id,
        type,
        rows: [
          { label: 'Subtotal', field: 'totals.subtotal', format: 'money' },
          { label: 'Total', field: 'totals.total', format: 'money', bold: true },
        ],
      }
    case 'spacer':
      return { id, type, height: 12 }
    case 'divider':
      return { id, type }
    case 'header':
    case 'footer':
      return { id, type, children: [] }
    case 'conditional':
      return { id, type, condition: '{{ totals.discount }} > 0', children: [] }
  }
}

/* ── store ─────────────────────────────────────────────────────────── */

interface EditorState {
  template: DocumentTemplate | null
  selectedBlockId: string | null
  dirty: boolean
  previewHtml: string | null
  previewWarnings: string[]
  loadingPreview: boolean
  clipboard: Block | null

  loadTemplate: (t: DocumentTemplate) => void
  updateMeta: (patch: Partial<DocumentTemplate>) => void
  selectBlock: (id: string | null) => void
  addBlock: (type: BlockType, parentId?: string | null, index?: number) => void
  removeBlock: (id: string) => void
  moveBlock: (id: string, dir: 'up' | 'down') => void
  moveBlockTo: (id: string, parentId: string | null, index: number) => void
  duplicateBlock: (id: string) => void
  updateBlock: (id: string, patch: Partial<Block>) => void
  copyBlock: (id: string) => void
  pasteBlock: (parentId?: string | null, index?: number) => void
  undo: () => void
  redo: () => void
  markSaved: (t: DocumentTemplate) => void
  setPreview: (html: string | null, warnings: string[]) => void
  setLoadingPreview: (v: boolean) => void
  reset: () => void
}

const HISTORY_LIMIT = 50

export const useTemplateEditorStore = create<EditorState>((set, get) => {
  // Undo/redo history (ADR-004 §12). Snapshots of the block tree.
  let past: Block[][] = []
  let future: Block[][] = []

  const snapshot = (): Block[] => {
    const t = get().template?.layout_config?.blocks
    return t ? (JSON.parse(JSON.stringify(t)) as Block[]) : []
  }

  /** Push current tree to past, clear future (call BEFORE a mutation). */
  const commit = (): void => {
    past = [...past.slice(-(HISTORY_LIMIT - 1)), snapshot()]
    future = []
  }

  const replaceBlocks = (next: Block[], extra: Partial<EditorState> = {}): void => {
    const s = get()
    if (!s.template) return
    set({ template: { ...s.template, layout_config: { blocks: normalizeFinalSection(next) } }, dirty: true, ...extra })
  }

  return {
    template: null,
    selectedBlockId: null,
    dirty: false,
    previewHtml: null,
    previewWarnings: [],
    loadingPreview: false,
    clipboard: null,

    loadTemplate: (t) => {
      const layout: LayoutConfig = t.layout_config
        ? { blocks: normalizeFinalSection(ensureIds(t.layout_config.blocks ?? [])) }
        : { blocks: [] }
      past = []
      future = []
      set({ template: { ...t, layout_config: layout }, selectedBlockId: null, dirty: false, previewHtml: null })
    },

    updateMeta: (patch) =>
      set((s) => (s.template ? { template: { ...s.template, ...patch }, dirty: true } : s)),

    selectBlock: (id) => set({ selectedBlockId: id }),

    addBlock: (type, parentId = null, index = -1) => {
      const s = get()
      if (!s.template) return
      commit()
      const block = createBlock(type)
      const blocks = s.template.layout_config?.blocks ?? []
      const next = normalizeFinalSection(insertIntoTree(blocks, block, parentId, index))
      set({
        template: { ...s.template, layout_config: { blocks: next } },
        selectedBlockId: block.id,
        dirty: true,
      })
    },

    removeBlock: (id) => {
      const s = get()
      if (!s.template?.layout_config) return
      commit()
      const next = normalizeFinalSection(removeFromTree(s.template.layout_config.blocks, id))
      set({
        template: { ...s.template, layout_config: { blocks: next } },
        selectedBlockId: s.selectedBlockId === id ? null : s.selectedBlockId,
        dirty: true,
      })
    },

    moveBlock: (id, dir) => {
      const s = get()
      if (!s.template?.layout_config) return
      const blocks = s.template.layout_config.blocks
      const loc = findLocation(blocks, id)
      if (!loc) return
      const block = walk(blocks, id)
      if (!block) return
      const newIdx = dir === 'up' ? loc.index - 1 : loc.index + 1
      if (newIdx < 0) return
      commit()
      let next = removeFromTree(blocks, id)
      next = insertIntoTree(next, block, loc.parentId, newIdx)
      replaceBlocks(next)
    },

    moveBlockTo: (id, parentId, index) => {
      const s = get()
      if (!s.template?.layout_config) return
      const blocks = s.template.layout_config.blocks
      const block = walk(blocks, id)
      if (!block) return
      // Don't allow dropping a block into itself or its own descendant.
      if (parentId && (parentId === id || isDescendant(blocks, id, parentId))) return
      commit()
      let next = removeFromTree(blocks, id)
      next = insertIntoTree(next, block, parentId, index)
      replaceBlocks(next)
    },

    duplicateBlock: (id) => {
      const s = get()
      if (!s.template?.layout_config) return
      const blocks = s.template.layout_config.blocks
      const loc = findLocation(blocks, id)
      if (!loc) return
      const block = walk(blocks, id)
      if (!block) return
      commit()
      const clone = JSON.parse(JSON.stringify(block)) as Block
      const reId = (b: Block) => {
        b.id = nanoid()
        if ('children' in b && b.children.length) b.children.forEach(reId)
      }
      reId(clone)
      const next = normalizeFinalSection(insertIntoTree(blocks, clone, loc.parentId, loc.index + 1))
      set({
        template: { ...s.template, layout_config: { blocks: next } },
        selectedBlockId: clone.id,
        dirty: true,
      })
    },

    updateBlock: (id, patch) => {
      const s = get()
      if (!s.template?.layout_config) return
      commit()
      const next = updateInTree(s.template.layout_config.blocks, id, patch)
      replaceBlocks(next)
    },

    // ADR-004 §12 — copy/paste.
    copyBlock: (id) => {
      const s = get()
      if (!s.template?.layout_config) return
      const block = walk(s.template.layout_config.blocks, id)
      if (!block) return
      const clone = JSON.parse(JSON.stringify(block)) as Block
      const reId = (b: Block) => {
        b.id = nanoid()
        if ('children' in b && b.children.length) b.children.forEach(reId)
      }
      reId(clone)
      set({ clipboard: clone })
    },

    pasteBlock: (parentId = null, index = -1) => {
      const s = get()
      if (!s.template?.layout_config || !s.clipboard) return
      commit()
      // Re-id again so repeated pastes produce distinct blocks.
      const clone = JSON.parse(JSON.stringify(s.clipboard)) as Block
      const reId = (b: Block) => {
        b.id = nanoid()
        if ('children' in b && b.children.length) b.children.forEach(reId)
      }
      reId(clone)
      const next = normalizeFinalSection(insertIntoTree(s.template.layout_config.blocks, clone, parentId, index))
      set({
        template: { ...s.template, layout_config: { blocks: next } },
        selectedBlockId: clone.id,
        dirty: true,
      })
    },

    // ADR-004 §12 — undo/redo.
    undo: () => {
      if (past.length === 0) return
      const s = get()
      if (!s.template?.layout_config) return
      const current = snapshot()
      const prev = past[past.length - 1]
      past = past.slice(0, -1)
      future = [current, ...future].slice(0, HISTORY_LIMIT)
      set({ template: { ...s.template, layout_config: { blocks: prev } }, dirty: true })
    },

    redo: () => {
      if (future.length === 0) return
      const s = get()
      if (!s.template?.layout_config) return
      const current = snapshot()
      const nextTree = future[0]
      future = future.slice(1)
      past = [...past, current].slice(-HISTORY_LIMIT)
      set({ template: { ...s.template, layout_config: { blocks: nextTree } }, dirty: true })
    },

    markSaved: (t) => {
      const s = get()
      // Preserve the local working layout (server echoes the same) and reset dirty.
      set({ template: { ...s.template, ...t }, dirty: false })
    },

    setPreview: (html, warnings) => set({ previewHtml: html, previewWarnings: warnings }),
    setLoadingPreview: (v) => set({ loadingPreview: v }),

    reset: () => {
      past = []
      future = []
      set({ template: null, selectedBlockId: null, dirty: false, previewHtml: null, previewWarnings: [], clipboard: null })
    },
  }
})
