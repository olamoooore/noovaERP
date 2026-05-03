import { create } from 'zustand'
import { nanoid } from 'nanoid'
import type { 
  ReceiptConfig, 
  ReceiptSection, 
  ReceiptColumn, 
  ColumnContent,
  SectionType,
  PaperWidth
} from '@/types/receipt-builder'
import { getSectionTemplate, DEFAULT_HEIGHTS } from '@/lib/receiptTemplates'

interface ReceiptBuilderState {
  // Current receipt configuration
  config: ReceiptConfig | null
  
  // UI state
  selectedSectionId: string | null
  selectedColumnId: string | null
  selectedContentId: string | null
  editingContentId: string | null
  isDragging: boolean
  hasUnsavedChanges: boolean
  
  // Actions - Config
  setConfig: (config: ReceiptConfig) => void
  createNewConfig: (name: string, paperWidth: PaperWidth) => void
  updateConfigName: (name: string) => void
  updatePaperWidth: (paperWidth: PaperWidth) => void
  
  // Actions - Sections
  addSection: (type: SectionType, afterIndex?: number) => void
  removeSection: (sectionId: string) => void
  moveSection: (sectionId: string, direction: 'up' | 'down') => void
  updateSection: (sectionId: string, updates: Partial<ReceiptSection>) => void
  duplicateSection: (sectionId: string) => void
  
  // Actions - Columns
  addColumn: (sectionId: string) => void
  removeColumn: (sectionId: string, columnId: string) => void
  updateColumn: (sectionId: string, columnId: string, updates: Partial<ReceiptColumn>) => void
  
  // Actions - Content
  addContent: (sectionId: string, columnId: string, content: Omit<ColumnContent, 'id'>) => void
  removeContent: (sectionId: string, columnId: string, contentId: string) => void
  updateContent: (sectionId: string, columnId: string, contentId: string, updates: Partial<ColumnContent>) => void
  moveContent: (sectionId: string, columnId: string, contentId: string, direction: 'up' | 'down') => void
  
  // Actions - Selection
  selectSection: (sectionId: string | null) => void
  selectColumn: (columnId: string | null) => void
  selectContent: (contentId: string | null) => void
  clearSelection: () => void
  
  // Actions - UI
  setDragging: (isDragging: boolean) => void
  setEditingContent: (contentId: string | null) => void
  markAsChanged: () => void
  markAsSaved: () => void
}

export const useReceiptBuilderStore = create<ReceiptBuilderState>((set, get) => ({
  config: null,
  selectedSectionId: null,
  selectedColumnId: null,
  selectedContentId: null,
  editingContentId: null,
  isDragging: false,
  hasUnsavedChanges: false,
  
  // Config actions
  setConfig: (config) => set({ config, hasUnsavedChanges: false }),
  
  createNewConfig: (name, paperWidth) => {
    const newConfig: ReceiptConfig = {
      id: nanoid(),
      name,
      paperWidth,
      sections: [],
      metadata: {
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        version: '1.0.0'
      }
    }
    set({ config: newConfig, hasUnsavedChanges: true })
  },
  
  updateConfigName: (name) => {
    const { config } = get()
    if (!config) return
    
    set({
      config: {
        ...config,
        name,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  updatePaperWidth: (paperWidth) => {
    const { config } = get()
    if (!config) return
    
    set({
      config: {
        ...config,
        paperWidth,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  // Section actions
  addSection: (type, afterIndex) => {
    const { config } = get()
    if (!config) return
    
    const template = getSectionTemplate(type)
    if (!template) return
    
    const newSection: ReceiptSection = {
      id: nanoid(),
      type,
      order: afterIndex !== undefined ? afterIndex + 1 : config.sections.length,
      height: DEFAULT_HEIGHTS[type],
      columns: template.defaultSection.columns.map(col => ({
        id: nanoid(),
        width: col.width,
        alignment: col.alignment,
        content: (col.content ?? []).map(cnt => ({ ...cnt, id: nanoid() }))
      })),
      properties: { ...template.defaultSection.properties }
    }
    
    const sections = [...config.sections]
    if (afterIndex !== undefined) {
      sections.splice(afterIndex + 1, 0, newSection)
      // Reorder
      sections.forEach((s, i) => { s.order = i })
    } else {
      sections.push(newSection)
    }
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      selectedSectionId: newSection.id,
      hasUnsavedChanges: true
    })
  },
  
  removeSection: (sectionId) => {
    const { config, selectedSectionId } = get()
    if (!config) return
    
    const sections = config.sections.filter(s => s.id !== sectionId)
    sections.forEach((s, i) => { s.order = i })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      selectedSectionId: selectedSectionId === sectionId ? null : selectedSectionId,
      hasUnsavedChanges: true
    })
  },
  
  moveSection: (sectionId, direction) => {
    const { config } = get()
    if (!config) return
    
    const sections = [...config.sections]
    const index = sections.findIndex(s => s.id === sectionId)
    if (index === -1) return
    
    const newIndex = direction === 'up' ? index - 1 : index + 1
    if (newIndex < 0 || newIndex >= sections.length) return
    
    // Swap
    [sections[index], sections[newIndex]] = [sections[newIndex], sections[index]]
    sections.forEach((s, i) => { s.order = i })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  updateSection: (sectionId, updates) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s =>
      s.id === sectionId ? { ...s, ...updates } : s
    )
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  duplicateSection: (sectionId) => {
    const { config } = get()
    if (!config) return
    
    const section = config.sections.find(s => s.id === sectionId)
    if (!section) return
    
    const newSection: ReceiptSection = {
      ...section,
      id: nanoid(),
      order: section.order + 1,
      columns: section.columns.map(col => ({
        ...col,
        id: nanoid(),
        content: col.content.map(cnt => ({ ...cnt, id: nanoid() }))
      }))
    }
    
    const sections = [...config.sections]
    sections.splice(section.order + 1, 0, newSection)
    sections.forEach((s, i) => { s.order = i })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  // Column actions
  addColumn: (sectionId) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const newColumn: ReceiptColumn = {
        id: nanoid(),
        width: 100 / (s.columns.length + 1),
        alignment: 'left',
        content: []
      }
      
      // Adjust existing column widths
      const newWidth = 100 / (s.columns.length + 1)
      const adjustedColumns: ReceiptColumn[] = s.columns.map(col => ({
        id: col.id,
        width: newWidth,
        alignment: col.alignment,
        content: col.content
      }))
      
      return { ...s, columns: [...adjustedColumns, newColumn] }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  removeColumn: (sectionId, columnId) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.filter(c => c.id !== columnId)
      if (columns.length === 0) return s // Don't remove last column
      
      // Redistribute widths
      const adjustedColumns = columns.map(col => ({
        ...col,
        width: 100 / columns.length
      }))
      
      return { ...s, columns: adjustedColumns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  updateColumn: (sectionId, columnId, updates) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.map(c =>
        c.id === columnId ? { ...c, ...updates } : c
      )
      
      return { ...s, columns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  // Content actions
  addContent: (sectionId, columnId, content) => {
    const { config } = get()
    if (!config) return
    
    const newContent: ColumnContent = {
      ...content,
      id: nanoid()
    }
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.map(c => {
        if (c.id !== columnId) return c
        return { ...c, content: [...c.content, newContent] }
      })
      
      return { ...s, columns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  removeContent: (sectionId, columnId, contentId) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.map(c => {
        if (c.id !== columnId) return c
        return { ...c, content: c.content.filter(cnt => cnt.id !== contentId) }
      })
      
      return { ...s, columns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  updateContent: (sectionId, columnId, contentId, updates) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.map(c => {
        if (c.id !== columnId) return c
        
        const content = c.content.map(cnt =>
          cnt.id === contentId ? { ...cnt, ...updates } : cnt
        )
        
        return { ...c, content }
      })
      
      return { ...s, columns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  moveContent: (sectionId, columnId, contentId, direction) => {
    const { config } = get()
    if (!config) return
    
    const sections = config.sections.map(s => {
      if (s.id !== sectionId) return s
      
      const columns = s.columns.map(c => {
        if (c.id !== columnId) return c
        
        const content = [...c.content]
        const index = content.findIndex(cnt => cnt.id === contentId)
        if (index === -1) return c
        
        const newIndex = direction === 'up' ? index - 1 : index + 1
        if (newIndex < 0 || newIndex >= content.length) return c
        
        // Swap
        const temp = content[index]
        content[index] = content[newIndex]
        content[newIndex] = temp
        
        return { ...c, content }
      })
      
      return { ...s, columns }
    })
    
    set({
      config: {
        ...config,
        sections,
        metadata: { ...config.metadata, updatedAt: new Date().toISOString() }
      },
      hasUnsavedChanges: true
    })
  },
  
  // Selection actions
  selectSection: (sectionId) => set({ 
    selectedSectionId: sectionId,
    selectedColumnId: null,
    selectedContentId: null,
    editingContentId: null
  }),
  
  selectColumn: (columnId) => set({ 
    selectedColumnId: columnId,
    selectedContentId: null,
    editingContentId: null
  }),
  
  selectContent: (contentId) => set({ selectedContentId: contentId, editingContentId: null }),
  
  clearSelection: () => set({ 
    selectedSectionId: null,
    selectedColumnId: null,
    selectedContentId: null
  }),
  
  // UI actions
  setDragging: (isDragging) => set({ isDragging }),
  setEditingContent: (contentId) => set({ editingContentId: contentId }),
  markAsChanged: () => set({ hasUnsavedChanges: true }),
  markAsSaved: () => set({ hasUnsavedChanges: false })
}))
