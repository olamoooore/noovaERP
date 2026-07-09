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

/* ── block factory ─────────────────────────────────────────────────── */

export function createBlock(type: BlockType): Block {
  const id = nanoid()
  switch (type) {
    case 'text':
      return { id, type, content: 'New text block' }
    case 'image':
      return { id, type, url: '{{ company.logo_url }}', width: 120, align: 'left' }
    case 'columns':
      return { id, type, gap: 8, children: [] }
    case 'table':
      return {
        id,
        type,
        source: 'lines',
        columns: [
          { label: '#', field: 'index', width: '6%', align: 'center' },
          { label: 'Description', field: 'description', width: '46%' },
          { label: 'Qty', field: 'quantity', width: '12%', align: 'right' },
          { label: 'Amount', field: 'line_total', width: '18%', align: 'right', format: 'money' },
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

  loadTemplate: (t: DocumentTemplate) => void
  updateMeta: (patch: Partial<DocumentTemplate>) => void
  selectBlock: (id: string | null) => void
  addBlock: (type: BlockType, parentId?: string | null, index?: number) => void
  removeBlock: (id: string) => void
  moveBlock: (id: string, dir: 'up' | 'down') => void
  moveBlockTo: (id: string, parentId: string | null, index: number) => void
  duplicateBlock: (id: string) => void
  updateBlock: (id: string, patch: Partial<Block>) => void
  markSaved: (t: DocumentTemplate) => void
  setPreview: (html: string | null, warnings: string[]) => void
  setLoadingPreview: (v: boolean) => void
  reset: () => void
}

export const useTemplateEditorStore = create<EditorState>((set, get) => ({
  template: null,
  selectedBlockId: null,
  dirty: false,
  previewHtml: null,
  previewWarnings: [],
  loadingPreview: false,

  loadTemplate: (t) => {
    const layout: LayoutConfig = t.layout_config
      ? { blocks: ensureIds(t.layout_config.blocks ?? []) }
      : { blocks: [] }
    set({ template: { ...t, layout_config: layout }, selectedBlockId: null, dirty: false, previewHtml: null })
  },

  updateMeta: (patch) =>
    set((s) => (s.template ? { template: { ...s.template, ...patch }, dirty: true } : s)),

  selectBlock: (id) => set({ selectedBlockId: id }),

  addBlock: (type, parentId = null, index = -1) => {
    const s = get()
    if (!s.template) return
    const block = createBlock(type)
    const blocks = s.template.layout_config?.blocks ?? []
    const next = insertIntoTree(blocks, block, parentId, index)
    set({
      template: { ...s.template, layout_config: { blocks: next } },
      selectedBlockId: block.id,
      dirty: true,
    })
  },

  removeBlock: (id) => {
    const s = get()
    if (!s.template?.layout_config) return
    const next = removeFromTree(s.template.layout_config.blocks, id)
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
    let next = removeFromTree(blocks, id)
    next = insertIntoTree(next, block, loc.parentId, newIdx)
    set({ template: { ...s.template, layout_config: { blocks: next } }, dirty: true })
  },

  moveBlockTo: (id, parentId, index) => {
    const s = get()
    if (!s.template?.layout_config) return
    const blocks = s.template.layout_config.blocks
    const block = walk(blocks, id)
    if (!block) return
    // Don't allow dropping a block into itself or its own descendant.
    if (parentId && (parentId === id || isDescendant(blocks, id, parentId))) return
    let next = removeFromTree(blocks, id)
    next = insertIntoTree(next, block, parentId, index)
    set({ template: { ...s.template, layout_config: { blocks: next } }, dirty: true })
  },

  duplicateBlock: (id) => {
    const s = get()
    if (!s.template?.layout_config) return
    const blocks = s.template.layout_config.blocks
    const loc = findLocation(blocks, id)
    if (!loc) return
    const block = walk(blocks, id)
    if (!block) return
    const clone = JSON.parse(JSON.stringify(block)) as Block
    const reId = (b: Block) => {
      b.id = nanoid()
      if ('children' in b && b.children.length) b.children.forEach(reId)
    }
    reId(clone)
    const next = insertIntoTree(blocks, clone, loc.parentId, loc.index + 1)
    set({
      template: { ...s.template, layout_config: { blocks: next } },
      selectedBlockId: clone.id,
      dirty: true,
    })
  },

  updateBlock: (id, patch) => {
    const s = get()
    if (!s.template?.layout_config) return
    const next = updateInTree(s.template.layout_config.blocks, id, patch)
    set({ template: { ...s.template, layout_config: { blocks: next } }, dirty: true })
  },

  markSaved: (t) => {
    const s = get()
    // Preserve the local working layout (server echoes the same) and reset dirty.
    set({ template: { ...s.template, ...t }, dirty: false })
  },

  setPreview: (html, warnings) => set({ previewHtml: html, previewWarnings: warnings }),
  setLoadingPreview: (v) => set({ loadingPreview: v }),

  reset: () =>
    set({ template: null, selectedBlockId: null, dirty: false, previewHtml: null, previewWarnings: [] }),
}))
