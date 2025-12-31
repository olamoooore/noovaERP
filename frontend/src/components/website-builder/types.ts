import { Editor } from 'grapesjs'

export interface GrapesEditorProps {
  initialContent?: {
    html: string
    css: string
    components?: any
    styles?: any
  }
  onSave?: (data: { html: string; css: string; components: any; styles: any }) => void
  onLoad?: (editor: Editor) => void
  projectId?: string | number
}

export interface Preset {
  id: string
  label: string
  category: string
  content: string
}

export interface IconDefinition {
  name: string
  label: string
}

export interface LucideIconSvgs {
  [key: string]: string
}
