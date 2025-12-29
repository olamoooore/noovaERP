import { create } from 'zustand'
import { immer } from 'zustand/middleware/immer'

export interface BuilderNode {
  id: string
  type: string
  props: Record<string, any>
  children: BuilderNode[]
}

export interface BuilderState {
  // Website & Page
  websiteId: number | null
  pageId: number | null
  pageName: string
  
  // Content
  content: BuilderNode
  
  // Selection
  selectedNodeId: string | null
  hoveredNodeId: string | null
  
  // UI State
  previewMode: 'desktop' | 'tablet' | 'mobile'
  isDirty: boolean
  isSaving: boolean
  
  // Actions
  setWebsite: (websiteId: number, pageId: number, pageName: string) => void
  setContent: (content: BuilderNode) => void
  selectNode: (nodeId: string | null) => void
  hoverNode: (nodeId: string | null) => void
  setPreviewMode: (mode: 'desktop' | 'tablet' | 'mobile') => void
  
  // Node Operations
  addNode: (parentId: string, node: BuilderNode, index?: number) => void
  updateNode: (nodeId: string, props: Partial<BuilderNode['props']>) => void
  deleteNode: (nodeId: string) => void
  moveNode: (nodeId: string, newParentId: string, index: number) => void
  
  // Persistence
  markDirty: () => void
  markSaved: () => void
  setSaving: (saving: boolean) => void
}

const findNodeById = (root: BuilderNode, id: string): BuilderNode | null => {
  if (root.id === id) return root
  for (const child of root.children) {
    const found = findNodeById(child, id)
    if (found) return found
  }
  return null
}

const findParentNode = (root: BuilderNode, id: string): BuilderNode | null => {
  for (const child of root.children) {
    if (child.id === id) return root
    const found = findParentNode(child, id)
    if (found) return found
  }
  return null
}

const removeNodeFromParent = (root: BuilderNode, id: string): boolean => {
  const idx = root.children.findIndex(c => c.id === id)
  if (idx !== -1) {
    root.children.splice(idx, 1)
    return true
  }
  for (const child of root.children) {
    if (removeNodeFromParent(child, id)) return true
  }
  return false
}

const defaultContent: BuilderNode = {
  id: 'root',
  type: 'page',
  props: {},
  children: [],
}

export const useBuilderStore = create<BuilderState>()(
  immer((set) => ({
    websiteId: null,
    pageId: null,
    pageName: '',
    content: defaultContent,
    selectedNodeId: null,
    hoveredNodeId: null,
    previewMode: 'desktop',
    isDirty: false,
    isSaving: false,

    setWebsite: (websiteId, pageId, pageName) => set((state) => {
      state.websiteId = websiteId
      state.pageId = pageId
      state.pageName = pageName
    }),

    setContent: (content) => set((state) => {
      state.content = content
      state.isDirty = false
    }),

    selectNode: (nodeId) => set((state) => {
      state.selectedNodeId = nodeId
    }),

    hoverNode: (nodeId) => set((state) => {
      state.hoveredNodeId = nodeId
    }),

    setPreviewMode: (mode) => set((state) => {
      state.previewMode = mode
    }),

    addNode: (parentId, node, index) => set((state) => {
      const parent = findNodeById(state.content, parentId)
      if (parent) {
        if (index !== undefined) {
          parent.children.splice(index, 0, node)
        } else {
          parent.children.push(node)
        }
        state.isDirty = true
      }
    }),

    updateNode: (nodeId, props) => set((state) => {
      const node = findNodeById(state.content, nodeId)
      if (node) {
        node.props = { ...node.props, ...props }
        state.isDirty = true
      }
    }),

    deleteNode: (nodeId) => set((state) => {
      removeNodeFromParent(state.content, nodeId)
      if (state.selectedNodeId === nodeId) {
        state.selectedNodeId = null
      }
      state.isDirty = true
    }),

    moveNode: (nodeId, newParentId, index) => set((state) => {
      const node = findNodeById(state.content, nodeId)
      if (!node) return

      // Clone the node
      const clonedNode = JSON.parse(JSON.stringify(node))
      
      // Remove from current parent
      removeNodeFromParent(state.content, nodeId)
      
      // Add to new parent
      const newParent = findNodeById(state.content, newParentId)
      if (newParent) {
        newParent.children.splice(index, 0, clonedNode)
        state.isDirty = true
      }
    }),

    markDirty: () => set((state) => {
      state.isDirty = true
    }),

    markSaved: () => set((state) => {
      state.isDirty = false
    }),

    setSaving: (saving) => set((state) => {
      state.isSaving = saving
    }),
  }))
)
