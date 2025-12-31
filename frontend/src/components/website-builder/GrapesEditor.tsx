import { useEffect, useRef, useCallback } from 'react'
import grapesjs, { Editor } from 'grapesjs'
import 'grapesjs/dist/css/grapes.min.css'

// Configuration
import { plugins } from './config/plugins'
import { canvasConfig } from './config/canvas'
import { styleManagerConfig } from './config/styleManager'
import { deviceManagerConfig } from './config/devices'

// Blocks
import { registerCustomBlocks } from './blocks'

// Panels
import { customizePanelIcons, addUploadButton, addPresetsPanel, addIconSearch } from './panels'

// Utils
import { setupLucideListeners, setupContextMenu, setupKeyboardShortcuts } from './utils'

// Types
import { GrapesEditorProps } from './types'

export function GrapesEditor({ initialContent, onSave, onLoad }: GrapesEditorProps) {
  const editorRef = useRef<Editor | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  const handleSave = useCallback(() => {
    if (!editorRef.current || !onSave) return

    const editor = editorRef.current
    onSave({
      html: editor.getHtml(),
      css: editor.getCss() || '',
      components: editor.getComponents(),
      styles: editor.getStyle(),
    })
  }, [onSave])

  useEffect(() => {
    if (!containerRef.current || editorRef.current) return

    const editor = grapesjs.init({
      container: containerRef.current,
      height: '100%',
      width: 'auto',
      storageManager: false,

      // Parser config
      parser: {
        optionsHtml: {
          allowScripts: true,
        },
      },

      // Device manager
      deviceManager: deviceManagerConfig,

      // Plugins
      plugins: plugins,
      pluginsOpts: {
        'grapesjs-preset-webpage': {
          modalImportTitle: 'Import Template',
          modalImportLabel: '<div style="margin-bottom: 10px;">Paste your HTML/CSS here</div>',
          modalImportContent: (ed: Editor) => {
            return ed.getHtml() + '<style>' + ed.getCss() + '</style>'
          },
        },
        'grapesjs-blocks-basic': {
          flexGrid: true,
        },
        'grapesjs-plugin-forms': {
          blocks: ['form', 'input', 'textarea', 'select', 'button', 'label', 'checkbox', 'radio'],
        },
        'grapesjs-tabs': {
          tabsBlock: { category: 'Extra' },
        },
        'grapesjs-navbar': {
          block: { category: 'Extra' },
        },
        'grapesjs-blocks-flexbox': {
          flexboxBlock: { category: 'Layout' },
        },
        'grapesjs-plugin-export': {
          addExportBtn: true,
        },
        'grapesjs-tui-image-editor': {
          includeUI: {
            initMenu: 'filter',
          },
        },
        'grapesjs-style-gradient': {
          colorPicker: 'default',
        },
      },

      // Canvas configuration
      canvas: canvasConfig,

      // Asset manager
      assetManager: {
        uploadFile: async (e: any) => {
          const files = e.dataTransfer ? e.dataTransfer.files : e.target.files
          console.log('Files to upload:', files)
        },
        assets: [],
      },

      // Style manager
      styleManager: styleManagerConfig,
    })

    // Register custom blocks
    registerCustomBlocks(editor)
    
    // Add custom presets panel
    addPresetsPanel(editor)

    // Add save command
    editor.Commands.add('save-project', {
      run: () => handleSave(),
    })

    // Remove ALL default GrapesJS keymaps to prevent conflicts
    // We handle all shortcuts ourselves
    const keymaps = editor.Keymaps
    const allKeymaps = keymaps.getAll()
    Object.keys(allKeymaps).forEach(id => {
      try {
        keymaps.remove(id)
      } catch (e) {
        // Ignore errors
      }
    })

    // Load initial content if provided
    if (initialContent) {
      if (initialContent.components) {
        editor.setComponents(initialContent.components)
        editor.setStyle(initialContent.styles || [])
      } else if (initialContent.html) {
        editor.setComponents(initialContent.html)
        editor.setStyle(initialContent.css || '')
      }
    }

    editorRef.current = editor
    onLoad?.(editor)

    // Setup Lucide icon listeners
    const cleanupLucide = setupLucideListeners(editor)

    // Setup custom context menu (replaces browser default)
    const cleanupContextMenu = setupContextMenu(editor)

    // Setup keyboard shortcuts
    const cleanupKeyboardShortcuts = setupKeyboardShortcuts(editor)

    // Customize panel icons after editor is fully loaded
    editor.on('load', () => {
      customizePanelIcons(editor)
      addUploadButton(editor)
      addIconSearch(editor)
    })

    // Auto-open Trait Manager (Settings) when SVG is selected
    editor.on('component:selected', (component: any) => {
      const tagName = component.get('tagName')?.toUpperCase() || component.view?.el?.tagName?.toUpperCase()
      if (tagName === 'SVG') {
        // Open the Trait Manager panel (Settings) for SVG editing
        editor.runCommand('open-tm')
      }
    })

    // Fallback: Try to customize icons after a delay
    setTimeout(() => {
      customizePanelIcons(editor)
      addUploadButton(editor)
      addIconSearch(editor)
    }, 1000)

    return () => {
      cleanupLucide()
      cleanupContextMenu()
      cleanupKeyboardShortcuts()
      editor.destroy()
      editorRef.current = null
    }
  }, [])

  // Update save handler when onSave changes
  useEffect(() => {
    if (!editorRef.current) return

    editorRef.current.Commands.add('save-project', {
      run: () => handleSave(),
    })
  }, [handleSave])

  return (
    <div ref={containerRef} className="grapesjs-editor h-full w-full" />
  )
}

export default GrapesEditor
