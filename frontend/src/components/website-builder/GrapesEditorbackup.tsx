import { useEffect, useRef, useCallback } from 'react'
import grapesjs, { Editor } from 'grapesjs'
import 'grapesjs/dist/css/grapes.min.css'

// Plugins
import gjsPresetWebpage from 'grapesjs-preset-webpage'
import gjsBlocksBasic from 'grapesjs-blocks-basic'
import gjsPluginForms from 'grapesjs-plugin-forms'
import gjsComponentCountdown from 'grapesjs-component-countdown'
import gjsTabs from 'grapesjs-tabs'
import gjsCustomCode from 'grapesjs-custom-code'
import gjsTouch from 'grapesjs-touch'
import gjsParserPostcss from 'grapesjs-parser-postcss'
import gjsTooltip from 'grapesjs-tooltip'
import gjsTyped from 'grapesjs-typed'
import gjsStyleBg from 'grapesjs-style-bg'
import gjsTuiImageEditor from 'grapesjs-tui-image-editor'
// New plugins
import gjsPluginExport from 'grapesjs-plugin-export'
// @ts-ignore - no types available
import gjsNavbar from 'grapesjs-navbar'
// @ts-ignore - no types available
import gjsStyleGradient from 'grapesjs-style-gradient'
// @ts-ignore - no types available
import gjsStyleFilter from 'grapesjs-style-filter'
// @ts-ignore - no types available
import gjsBlocksFlexbox from 'grapesjs-blocks-flexbox'
// Note: grapesjs-lory-slider disabled due to compatibility issues with GrapesJS 0.22.x
// @ts-ignore - no types available
import gjsUiSuggestClasses from 'grapesjs-ui-suggest-classes'
// @ts-ignore - no types available
import gjsClick from 'grapesjs-click'
// @ts-ignore - no types available
import gjsFloat from 'grapesjs-float'
import { headerPresets } from './headerPresets'
import { footerPresets } from './footerPresets'
import { heroPresets } from './heroPresets'
// Note: @silexlabs/grapesjs-fonts requires a Google Fonts API key - disabled for now
// Note: @silexlabs/grapesjs-symbols is installed but disabled due to compatibility issues
// with GrapesJS 0.22.x (Cannot set property defaults of Component which has only a getter)

// Note: The following plugins are installed but not enabled by default:
// - grapesjs-plugin-filestack: Requires Filestack API key
// - grapesjs-plugin-ckeditor: Requires CKEditor to be loaded
// - grapesjs-indexeddb: Storage wrapper (we handle storage ourselves)
// - grapesjs-firestore: Storage wrapper (we handle storage ourselves)

interface GrapesEditorProps {
  initialContent?: {
    html: string
    css: string
    components?: any
    styles?: any
  }
  onSave?: (data: { html: string; css: string; components: any; styles: any }) => void
  onLoad?: (editor: Editor) => void
  projectId?: string | number // Reserved for future use
}

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
      storageManager: false, // We handle storage ourselves
      
      // Parser config - don't sanitize HTML
      parser: {
        optionsHtml: {
          allowScripts: true,
        },
      },
      
      // Device manager for responsive design
      deviceManager: {
        devices: [
          { name: 'Desktop', width: '' },
          { name: 'Tablet', width: '768px', widthMedia: '992px' },
          { name: 'Mobile', width: '375px', widthMedia: '480px' },
        ],
      },

      // Plugins
      plugins: [
        gjsPresetWebpage,
        gjsBlocksBasic,
        gjsPluginForms,
        gjsComponentCountdown,
        gjsTabs,
        gjsCustomCode,
        gjsTouch,
        gjsParserPostcss,
        gjsTooltip,
        gjsTyped,
        gjsStyleBg,
        gjsTuiImageEditor,
        // New plugins
        gjsPluginExport,
        gjsNavbar,
        gjsStyleGradient,
        gjsStyleFilter,
        gjsBlocksFlexbox,
        gjsUiSuggestClasses,
        gjsClick,
        gjsFloat,
      ],
      
      pluginsOpts: {
        [gjsPresetWebpage as any]: {
          modalImportTitle: 'Import Template',
          modalImportLabel: '<div style="margin-bottom: 10px;">Paste your HTML/CSS here</div>',
          modalImportContent: (editor: Editor) => {
            return editor.getHtml() + '<style>' + editor.getCss() + '</style>'
          },
        },
        [gjsBlocksBasic as any]: {
          flexGrid: true,
        },
        [gjsPluginForms as any]: {
          blocks: ['form', 'input', 'textarea', 'select', 'button', 'label', 'checkbox', 'radio'],
        },
        [gjsTabs as any]: {
          tabsBlock: { category: 'Extra' },
        },
        [gjsNavbar as any]: {
          block: { category: 'Extra' },
        },
        [gjsBlocksFlexbox as any]: {
          flexboxBlock: { category: 'Layout' },
        },
        [gjsPluginExport as any]: {
          addExportBtn: true,
        },
        [gjsTuiImageEditor as any]: {
          includeUI: {
            initMenu: 'filter',
          },
        },
        [gjsStyleGradient as any]: {
          colorPicker: 'default',
        },
      },

      // Canvas configuration
      canvas: {
        styles: [
          'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
          // Google Fonts - Optimized loading with display=swap and preconnect
          'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@300;400;500;600;700&family=Lato:wght@300;400;700&family=Montserrat:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Raleway:wght@300;400;500;600;700&family=Nunito:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@300;400;700&family=Source+Sans+Pro:wght@300;400;600;700&family=Ubuntu:wght@300;400;500;700&family=Oswald:wght@300;400;500;600;700&family=Quicksand:wght@300;400;500;600;700&family=Work+Sans:wght@300;400;500;600;700&family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap',
          // Lucide icons CSS - style i[data-lucide] elements before Lucide converts them
          'data:text/css,' + encodeURIComponent(`
            /* Style i[data-lucide] elements - Lucide will replace them with SVGs */
            i[data-lucide] {
              display: inline-block;
              width: 1em;
              height: 1em;
              vertical-align: middle;
              background-color: currentColor;
              -webkit-mask-size: 100% 100%;
              mask-size: 100% 100%;
              -webkit-mask-repeat: no-repeat;
              mask-repeat: no-repeat;
            }
            /* Lucide SVGs styling */
            svg.lucide {
              display: inline-block;
              vertical-align: middle;
              width: 1em;
              height: 1em;
              stroke: currentColor;
              stroke-width: 2;
              stroke-linecap: round;
              stroke-linejoin: round;
              fill: none;
            }
            /* Size classes for icons */
            .w-4 { width: 1rem; }
            .h-4 { height: 1rem; }
            .w-5 { width: 1.25rem; }
            .h-5 { height: 1.25rem; }
            .w-6 { width: 1.5rem; }
            .h-6 { height: 1.5rem; }
          `),
          // Custom CSS for modern features not in Tailwind 2.x
          'data:text/css,' + encodeURIComponent(`
            /* Ensure inline SVGs render properly */
            svg { 
              display: inline-block; 
              vertical-align: middle;
              width: 1em;
              height: 1em;
            }
            
            /* Make SVGs inherit stroke color from parent text color */
            svg[stroke="currentColor"] { 
              stroke: currentColor !important; 
            }
            
            /* Ensure SVG paths/shapes also inherit */
            svg path, svg circle, svg line, svg polyline, svg polygon, svg rect, svg ellipse {
              stroke: inherit;
            }
            
            /* Fix for SVG icons in buttons and links */
            button, a, span { color: inherit; }
            
            /* Explicit color mappings for common Tailwind classes */
            /* Slate colors (not in Tailwind 2.x - it uses gray instead) */
            .text-slate-50 { color: rgb(248, 250, 252); }
            .text-slate-100 { color: rgb(241, 245, 249); }
            .text-slate-200 { color: rgb(226, 232, 240); }
            .text-slate-300 { color: rgb(203, 213, 225); }
            .text-slate-400 { color: rgb(148, 163, 184); }
            .text-slate-500 { color: rgb(100, 116, 139); }
            .text-slate-600 { color: rgb(71, 85, 105); }
            .text-slate-700 { color: rgb(51, 65, 85); }
            .text-slate-800 { color: rgb(30, 41, 59); }
            .text-slate-900 { color: rgb(15, 23, 42); }
            .text-slate-950 { color: rgb(2, 6, 23); }
            
            .bg-slate-50 { background-color: rgb(248, 250, 252); }
            .bg-slate-100 { background-color: rgb(241, 245, 249); }
            .bg-slate-200 { background-color: rgb(226, 232, 240); }
            .bg-slate-300 { background-color: rgb(203, 213, 225); }
            .bg-slate-400 { background-color: rgb(148, 163, 184); }
            .bg-slate-500 { background-color: rgb(100, 116, 139); }
            .bg-slate-600 { background-color: rgb(71, 85, 105); }
            .bg-slate-700 { background-color: rgb(51, 65, 85); }
            .bg-slate-800 { background-color: rgb(30, 41, 59); }
            .bg-slate-900 { background-color: rgb(15, 23, 42); }
            .bg-slate-950 { background-color: rgb(2, 6, 23); }
            
            .border-slate-100 { border-color: rgb(241, 245, 249); }
            .border-slate-200 { border-color: rgb(226, 232, 240); }
            .border-slate-300 { border-color: rgb(203, 213, 225); }
            .border-slate-700 { border-color: rgb(51, 65, 85); }
            .border-slate-800 { border-color: rgb(30, 41, 59); }
            
            /* Hover states for slate */
            .hover\\:text-slate-900:hover { color: rgb(15, 23, 42); }
            .hover\\:text-slate-600:hover { color: rgb(71, 85, 105); }
            .hover\\:bg-slate-50:hover { background-color: rgb(248, 250, 252); }
            .hover\\:bg-slate-100:hover { background-color: rgb(241, 245, 249); }
            .hover\\:bg-slate-800:hover { background-color: rgb(30, 41, 59); }
            
            /* Placeholder colors */
            .placeholder-slate-400::placeholder { color: rgb(148, 163, 184); }
            
            /* Focus ring for slate */
            .focus\\:ring-slate-500:focus { --tw-ring-color: rgb(100, 116, 139); }
            
            /* Gray fallbacks */
            .text-gray-500 { color: rgb(107, 114, 128); }
            .text-gray-400 { color: rgb(156, 163, 175); }
            .text-gray-600 { color: rgb(75, 85, 99); }
            .text-gray-900 { color: rgb(17, 24, 39); }
            .text-white { color: white; }
            .text-yellow-400 { color: rgb(250, 204, 21); }
            .text-indigo-600 { color: rgb(79, 70, 229); }
            .text-emerald-400 { color: rgb(52, 211, 153); }
            .text-orange-500 { color: rgb(249, 115, 22); }
            .text-violet-600 { color: rgb(124, 58, 237); }
            .text-rose-600 { color: rgb(225, 29, 72); }
            .text-red-500 { color: rgb(239, 68, 68); }
            .text-blue-500 { color: rgb(59, 130, 246); }
            .text-cyan-400 { color: rgb(34, 211, 238); }
            
            
            /* Backdrop blur */
            .backdrop-blur-sm { backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
            .backdrop-blur-md { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
            .backdrop-blur-lg { backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
            .backdrop-blur-xl { backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); }
            .backdrop-blur-2xl { backdrop-filter: blur(40px); -webkit-backdrop-filter: blur(40px); }
            
            /* Background opacity variants */
            .bg-white\\/70 { background-color: rgba(255,255,255,0.7); }
            .bg-white\\/80 { background-color: rgba(255,255,255,0.8); }
            .bg-white\\/90 { background-color: rgba(255,255,255,0.9); }
            .bg-slate-900\\/50 { background-color: rgba(15,23,42,0.5); }
            .bg-slate-900\\/60 { background-color: rgba(15,23,42,0.6); }
            .bg-slate-900\\/95 { background-color: rgba(15,23,42,0.95); }
            .bg-slate-950\\/95 { background-color: rgba(2,6,23,0.95); }
            .bg-violet-900\\/30 { background-color: rgba(76,29,149,0.3); }
            .bg-black\\/5 { background-color: rgba(0,0,0,0.05); }
            .bg-slate-800\\/50 { background-color: rgba(30,41,59,0.5); }
            
            /* Text opacity variants */
            .text-white\\/90 { color: rgba(255,255,255,0.9); }
            
            /* Border opacity variants */
            .border-white\\/30 { border-color: rgba(255,255,255,0.3); }
            
            /* Shadow color variants */
            .shadow-violet-500\\/30 { --tw-shadow-color: rgba(139,92,246,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-violet-500\\/20 { --tw-shadow-color: rgba(139,92,246,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-blue-500\\/20 { --tw-shadow-color: rgba(59,130,246,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-blue-500\\/25 { --tw-shadow-color: rgba(59,130,246,0.25); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-blue-500\\/40 { --tw-shadow-color: rgba(59,130,246,0.4); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-yellow-500\\/20 { --tw-shadow-color: rgba(234,179,8,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-yellow-500\\/30 { --tw-shadow-color: rgba(234,179,8,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-orange-500\\/20 { --tw-shadow-color: rgba(249,115,22,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-emerald-600\\/20 { --tw-shadow-color: rgba(5,150,105,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-indigo-500\\/20 { --tw-shadow-color: rgba(99,102,241,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-slate-900\\/20 { --tw-shadow-color: rgba(15,23,42,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            .shadow-slate-900\\/30 { --tw-shadow-color: rgba(15,23,42,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
            
            /* Hover background opacity */
            .hover\\:bg-white\\/20:hover { background-color: rgba(255,255,255,0.2); }
            .hover\\:bg-white\\/50:hover { background-color: rgba(255,255,255,0.5); }
            .hover\\:bg-white\\/60:hover { background-color: rgba(255,255,255,0.6); }
            .hover\\:bg-white\\/80:hover { background-color: rgba(255,255,255,0.8); }
            
            /* Group hover states */
            .group:hover .group-hover\\:text-slate-900 { color: rgb(15,23,42); }
            .group:hover .group-hover\\:hover\\:bg-slate-100:hover { background-color: rgb(241,245,249); }
            .group:hover .group-hover\\:rotate-180 { transform: rotate(180deg); }
            .group:hover .group-hover\\:opacity-100 { opacity: 1; }
            .group:hover .group-hover\\:visible { visibility: visible; }
            
            /* Scrollbar hiding */
            .no-scrollbar::-webkit-scrollbar { display: none; }
            .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
            
            /* Pointer events */
            .pointer-events-none { pointer-events: none; }
            .pointer-events-auto { pointer-events: auto; }
            
            /* Focus within */
            .group:focus-within .group-focus-within\\:text-yellow-400 { color: rgb(250,204,21); }
            
            /* Gradient text */
            .bg-clip-text { -webkit-background-clip: text; background-clip: text; }
            .text-transparent { color: transparent; }
          `),
        ],
        scripts: [
          // Lucide JS for icon rendering in canvas
          'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js',
        ],
      },

      // Asset manager for images
      assetManager: {
        uploadFile: async (e: any) => {
          // TODO: Implement file upload to your backend
          const files = e.dataTransfer ? e.dataTransfer.files : e.target.files
          // For now, just log - you'll want to upload to your API
          console.log('Files to upload:', files)
        },
        assets: [],
      },

      // Style manager sectors
      styleManager: {
        sectors: [
          {
            name: 'General',
            open: false,
            buildProps: ['float', 'display', 'position', 'top', 'right', 'left', 'bottom'],
          },
          {
            name: 'Flex',
            open: false,
            buildProps: ['flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'order', 'flex-basis', 'flex-grow', 'flex-shrink', 'align-self'],
          },
          {
            name: 'Dimension',
            open: false,
            buildProps: ['width', 'height', 'max-width', 'min-height', 'margin', 'padding'],
          },
          {
            name: 'Typography',
            open: false,
            buildProps: ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'color', 'line-height', 'text-align', 'text-decoration', 'text-shadow'],
            properties: [
              {
                name: 'Font Family',
                property: 'font-family',
                type: 'select',
                defaults: 'Inter, sans-serif',
                options: [
                  { id: 'Inter, sans-serif', label: 'Inter' },
                  { id: 'Arial, Helvetica, sans-serif', label: 'Arial' },
                  { id: 'Georgia, serif', label: 'Georgia' },
                  { id: 'Times New Roman, serif', label: 'Times New Roman' },
                  { id: 'Courier New, monospace', label: 'Courier New' },
                  { id: 'Verdana, sans-serif', label: 'Verdana' },
                  { id: 'Trebuchet MS, sans-serif', label: 'Trebuchet MS' },
                  { id: 'Roboto, sans-serif', label: 'Roboto' },
                  { id: 'Open Sans, sans-serif', label: 'Open Sans' },
                  { id: 'Lato, sans-serif', label: 'Lato' },
                  { id: 'Montserrat, sans-serif', label: 'Montserrat' },
                  { id: 'Poppins, sans-serif', label: 'Poppins' },
                  { id: 'Raleway, sans-serif', label: 'Raleway' },
                  { id: 'Nunito, sans-serif', label: 'Nunito' },
                  { id: 'Playfair Display, serif', label: 'Playfair Display' },
                  { id: 'Merriweather, serif', label: 'Merriweather' },
                  { id: 'Source Sans Pro, sans-serif', label: 'Source Sans Pro' },
                  { id: 'Ubuntu, sans-serif', label: 'Ubuntu' },
                  { id: 'Oswald, sans-serif', label: 'Oswald' },
                  { id: 'Quicksand, sans-serif', label: 'Quicksand' },
                  { id: 'Work Sans, sans-serif', label: 'Work Sans' },
                  { id: 'DM Sans, sans-serif', label: 'DM Sans' },
                  { id: 'Space Grotesk, sans-serif', label: 'Space Grotesk' },
                  { id: 'Manrope, sans-serif', label: 'Manrope' },
                  { id: 'Plus Jakarta Sans, sans-serif', label: 'Plus Jakarta Sans' },
                ],
              },
            ],
          },
          {
            name: 'Decorations',
            open: false,
            buildProps: ['background-color', 'border-radius', 'border', 'box-shadow', 'background'],
          },
          {
            name: 'Extra',
            open: false,
            buildProps: ['opacity', 'transition', 'transform', 'cursor', 'overflow'],
          },
        ],
      },
    })

    // Add custom blocks
    addCustomBlocks(editor)
    
    // Add custom presets panel
    addPresetsPanel(editor)

    // Add keyboard shortcuts
    editor.Commands.add('save-project', {
      run: () => handleSave(),
    })

    editor.Keymaps.add('save', 'ctrl+s', 'save-project')
    editor.Keymaps.add('save-mac', 'cmd+s', 'save-project')

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

    // Initialize Lucide icons in canvas when components change
    const initLucideIcons = () => {
      const frame = editor.Canvas.getFrameEl()
      if (frame && frame.contentWindow && frame.contentDocument) {
        const win = frame.contentWindow as any
        const doc = frame.contentDocument
        
        // Check if there are any uninitialized icons
        const uninitializedIcons = doc.querySelectorAll('i[data-lucide]')
        if (uninitializedIcons.length === 0) return
        
        // Check if Lucide is loaded
        if (win.lucide && typeof win.lucide.createIcons === 'function') {
          try {
            win.lucide.createIcons({
              attrs: {
                'stroke-width': 2,
              },
            })
          } catch (e) {
            console.warn('Lucide createIcons error:', e)
          }
        } else {
          // If Lucide isn't loaded yet, inject it manually
          const existingScript = doc.querySelector('script[src*="lucide"]')
          if (!existingScript) {
            const script = doc.createElement('script')
            script.src = 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js'
            script.onload = () => {
              setTimeout(() => {
                if (win.lucide && typeof win.lucide.createIcons === 'function') {
                  win.lucide.createIcons()
                }
              }, 100)
            }
            doc.head.appendChild(script)
          }
        }
      }
    }

    // Listen for component additions to reinitialize icons
    editor.on('component:add', () => {
      setTimeout(initLucideIcons, 50)
      setTimeout(initLucideIcons, 200)
      setTimeout(initLucideIcons, 500)
    })

    // Listen for component updates
    editor.on('component:update', () => {
      setTimeout(initLucideIcons, 50)
    })

    // Listen for preview mode toggle
    editor.on('run:preview', () => {
      // Re-init icons when entering preview mode as GrapesJS might re-render the DOM
      setTimeout(initLucideIcons, 100)
    })

    editor.on('stop:preview', () => {
      // Re-init icons when exiting preview mode
      setTimeout(initLucideIcons, 100)
    })
    
    // Also init on canvas load
    editor.on('canvas:frame:load', () => {
      setTimeout(initLucideIcons, 100)
      setTimeout(initLucideIcons, 300)
      setTimeout(initLucideIcons, 600)
      setTimeout(initLucideIcons, 1000)
    })

    // Also init when editor is ready
    editor.on('load', () => {
      setTimeout(initLucideIcons, 200)
      setTimeout(initLucideIcons, 500)

      // Inject Lucide script into the wrapper content so it persists in Preview/Export
      const wrapper = editor.getWrapper()
      if (wrapper) {
        const lucideSrc = 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js'
        // Check if script is already present to avoid duplicates
        const hasScript = wrapper.find('script').some((c: any) => {
          const attrs = c.getAttributes()
          return attrs && attrs.src === lucideSrc
        })

        if (!hasScript) {
          wrapper.append({
            tagName: 'script',
            attributes: { src: lucideSrc },
            // Make it non-interactive in the editor
            layerable: false,
            selectable: false,
            droppable: false,
            draggable: false,
            removable: false,
          })
          
          wrapper.append({
            tagName: 'script',
            content: `
              if (window.lucide) window.lucide.createIcons();
              window.addEventListener("load", function() { if (window.lucide) window.lucide.createIcons(); });
            `,
            layerable: false,
            selectable: false,
            droppable: false,
            draggable: false,
            removable: false,
          })
        }
      }

      // Customize panel icons after editor is fully loaded
      customizePanelIcons(editor)
    })
    
    // Fallback: Try to customize icons after a delay in case 'load' event was missed
    // or if plugins take longer to initialize
    setTimeout(() => {
      customizePanelIcons(editor)
    }, 1000)
    
    // Periodic check for uninitialized icons (every 1 second)
    const iconCheckInterval = setInterval(() => {
      const frame = editor.Canvas.getFrameEl()
      if (frame && frame.contentDocument) {
        const uninitializedIcons = frame.contentDocument.querySelectorAll('i[data-lucide]')
        if (uninitializedIcons.length > 0) {
          initLucideIcons()
        }
      }
    }, 1000)

    return () => {
      clearInterval(iconCheckInterval)
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


// Custom blocks for your ERP - Using NoovaERP teal theme (#2a9d8f)
function addCustomBlocks(editor: Editor) {
  const blockManager = editor.BlockManager

  // Fix: Override the default button component from grapesjs-plugin-forms
  // This prevents the button content from being overwritten with "Send"
  const defaultButton = editor.DomComponents.getType('button');
  if (defaultButton) {
    editor.DomComponents.addType('button', {
      isComponent: (el) => el.tagName === 'BUTTON',
      model: {
        defaults: {
          ...defaultButton.model.defaults,
          content: '', // Clear the default "Send" text
          text: '', // CRITICAL: Clear the text trait/property which overrides content
          label: '', // Clear label if present
          droppable: true, // Allow dropping content inside
          resizable: true,
        },
      },
      view: defaultButton.view,
    });
  }

  // Register Lucide Icon component type
  editor.DomComponents.addType('lucide-icon', {
    isComponent: (el) => el.tagName === 'I' && el.hasAttribute('data-lucide'),
    model: {
      defaults: {
        tagName: 'i',
        draggable: true,
        droppable: false,
        attributes: { 'data-lucide': 'star' },
        traits: [
          {
            type: 'select',
            label: 'Icon',
            name: 'data-lucide',
            options: [
              { id: 'star', name: 'Star' },
              { id: 'heart', name: 'Heart' },
              { id: 'search', name: 'Search' },
              { id: 'menu', name: 'Menu' },
              { id: 'x', name: 'Close (X)' },
              { id: 'chevron-down', name: 'Chevron Down' },
              { id: 'chevron-right', name: 'Chevron Right' },
              { id: 'chevron-left', name: 'Chevron Left' },
              { id: 'chevron-up', name: 'Chevron Up' },
              { id: 'arrow-right', name: 'Arrow Right' },
              { id: 'arrow-left', name: 'Arrow Left' },
              { id: 'user', name: 'User' },
              { id: 'users', name: 'Users' },
              { id: 'shopping-cart', name: 'Shopping Cart' },
              { id: 'shopping-bag', name: 'Shopping Bag' },
              { id: 'mail', name: 'Mail' },
              { id: 'phone', name: 'Phone' },
              { id: 'map-pin', name: 'Map Pin' },
              { id: 'clock', name: 'Clock' },
              { id: 'calendar', name: 'Calendar' },
              { id: 'check', name: 'Check' },
              { id: 'check-circle', name: 'Check Circle' },
              { id: 'plus', name: 'Plus' },
              { id: 'minus', name: 'Minus' },
              { id: 'settings', name: 'Settings' },
              { id: 'home', name: 'Home' },
              { id: 'bell', name: 'Bell' },
              { id: 'bookmark', name: 'Bookmark' },
              { id: 'tag', name: 'Tag' },
              { id: 'box', name: 'Box' },
              { id: 'grid', name: 'Grid' },
              { id: 'list', name: 'List' },
              { id: 'image', name: 'Image' },
              { id: 'video', name: 'Video' },
              { id: 'music', name: 'Music' },
              { id: 'file', name: 'File' },
              { id: 'folder', name: 'Folder' },
              { id: 'download', name: 'Download' },
              { id: 'upload', name: 'Upload' },
              { id: 'share', name: 'Share' },
              { id: 'link', name: 'Link' },
              { id: 'external-link', name: 'External Link' },
              { id: 'eye', name: 'Eye' },
              { id: 'eye-off', name: 'Eye Off' },
              { id: 'lock', name: 'Lock' },
              { id: 'unlock', name: 'Unlock' },
              { id: 'trash', name: 'Trash' },
              { id: 'edit', name: 'Edit' },
              { id: 'copy', name: 'Copy' },
              { id: 'clipboard', name: 'Clipboard' },
              { id: 'refresh-cw', name: 'Refresh' },
              { id: 'loader', name: 'Loader' },
              { id: 'alert-circle', name: 'Alert Circle' },
              { id: 'alert-triangle', name: 'Alert Triangle' },
              { id: 'info', name: 'Info' },
              { id: 'help-circle', name: 'Help Circle' },
              { id: 'message-circle', name: 'Message Circle' },
              { id: 'send', name: 'Send' },
              { id: 'thumbs-up', name: 'Thumbs Up' },
              { id: 'thumbs-down', name: 'Thumbs Down' },
              { id: 'zap', name: 'Zap' },
              { id: 'flame', name: 'Flame' },
              { id: 'sun', name: 'Sun' },
              { id: 'moon', name: 'Moon' },
              { id: 'cloud', name: 'Cloud' },
              { id: 'truck', name: 'Truck' },
              { id: 'package', name: 'Package' },
              { id: 'credit-card', name: 'Credit Card' },
              { id: 'dollar-sign', name: 'Dollar Sign' },
              { id: 'percent', name: 'Percent' },
              { id: 'trending-up', name: 'Trending Up' },
              { id: 'trending-down', name: 'Trending Down' },
              { id: 'bar-chart', name: 'Bar Chart' },
              { id: 'pie-chart', name: 'Pie Chart' },
              { id: 'activity', name: 'Activity' },
              { id: 'github', name: 'GitHub' },
              { id: 'twitter', name: 'Twitter' },
              { id: 'facebook', name: 'Facebook' },
              { id: 'instagram', name: 'Instagram' },
              { id: 'linkedin', name: 'LinkedIn' },
              { id: 'youtube', name: 'YouTube' },
              { id: 'globe', name: 'Globe' },
              { id: 'wifi', name: 'WiFi' },
              { id: 'bluetooth', name: 'Bluetooth' },
              { id: 'smartphone', name: 'Smartphone' },
              { id: 'tablet', name: 'Tablet' },
              { id: 'monitor', name: 'Monitor' },
              { id: 'laptop', name: 'Laptop' },
              { id: 'printer', name: 'Printer' },
              { id: 'camera', name: 'Camera' },
              { id: 'mic', name: 'Microphone' },
              { id: 'headphones', name: 'Headphones' },
              { id: 'play', name: 'Play' },
              { id: 'pause', name: 'Pause' },
              { id: 'stop-circle', name: 'Stop' },
              { id: 'skip-forward', name: 'Skip Forward' },
              { id: 'skip-back', name: 'Skip Back' },
              { id: 'volume-2', name: 'Volume' },
              { id: 'volume-x', name: 'Volume Mute' },
              { id: 'maximize', name: 'Maximize' },
              { id: 'minimize', name: 'Minimize' },
              { id: 'log-in', name: 'Log In' },
              { id: 'log-out', name: 'Log Out' },
              { id: 'briefcase', name: 'Briefcase' },
              { id: 'award', name: 'Award' },
              { id: 'gift', name: 'Gift' },
              { id: 'coffee', name: 'Coffee' },
              { id: 'book', name: 'Book' },
              { id: 'book-open', name: 'Book Open' },
              { id: 'graduation-cap', name: 'Graduation Cap' },
              { id: 'building', name: 'Building' },
              { id: 'store', name: 'Store' },
            ],
          },
          {
            type: 'class',
            label: 'Size Class',
            name: 'class',
          },
        ],
      },
    },
    view: {
      onRender() {
        // Re-initialize Lucide icons when component is rendered
        const frame = editor.Canvas.getFrameEl()
        if (frame && frame.contentWindow) {
          const win = frame.contentWindow as any
          if (win.lucide) {
            setTimeout(() => win.lucide.createIcons(), 50)
          }
        }
      },
    },
  })

  // Inline SVG icons for blocks (no JS dependency)
  const iconSvgs: Record<string, string> = {
    'search': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>`,
    'menu': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line><line x1="4" x2="20" y1="18" y2="18"></line></svg>`,
    'x': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg>`,
    'chevron-down': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"></path></svg>`,
    'arrow-right': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>`,
    'user': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>`,
    'shopping-cart': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"></path></svg>`,
    'shopping-bag': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path></svg>`,
    'heart': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"></path></svg>`,
    'star': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>`,
    'mail': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg>`,
    'phone': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>`,
    'map-pin': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>`,
    'check': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>`,
    'plus': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg>`,
    'settings': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>`,
    'home': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>`,
    'bell': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path></svg>`,
    'instagram': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"></line></svg>`,
    'facebook': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg>`,
    'twitter': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"></path></svg>`,
    'linkedin': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path><rect width="4" height="12" x="2" y="9"></rect><circle cx="4" cy="4" r="2"></circle></svg>`,
    'github': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"></path><path d="M9 18c-4.51 2-5-2-7-2"></path></svg>`,
    'globe': `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>`,
  }

  // Add Lucide Icon blocks with inline SVGs
  const popularIcons = [
    { name: 'search', label: 'Search' },
    { name: 'menu', label: 'Menu' },
    { name: 'x', label: 'Close' },
    { name: 'chevron-down', label: 'Chevron Down' },
    { name: 'arrow-right', label: 'Arrow Right' },
    { name: 'user', label: 'User' },
    { name: 'shopping-cart', label: 'Cart' },
    { name: 'shopping-bag', label: 'Bag' },
    { name: 'heart', label: 'Heart' },
    { name: 'star', label: 'Star' },
    { name: 'mail', label: 'Mail' },
    { name: 'phone', label: 'Phone' },
    { name: 'map-pin', label: 'Location' },
    { name: 'check', label: 'Check' },
    { name: 'plus', label: 'Plus' },
    { name: 'settings', label: 'Settings' },
    { name: 'home', label: 'Home' },
    { name: 'bell', label: 'Bell' },
    { name: 'instagram', label: 'Instagram' },
    { name: 'facebook', label: 'Facebook' },
    { name: 'twitter', label: 'Twitter' },
    { name: 'linkedin', label: 'LinkedIn' },
    { name: 'github', label: 'GitHub' },
    { name: 'globe', label: 'Globe' },
  ]

  popularIcons.forEach(icon => {
    const svg = iconSvgs[icon.name] || iconSvgs['star']
    blockManager.add(`icon-${icon.name}`, {
      label: icon.label,
      category: 'Icons',
      content: `<span class="inline-flex items-center justify-center w-6 h-6">${svg}</span>`,
      media: svg,
    })
  })

  // Register Header Presets as Blocks - REMOVED per user request (kept only in Presets panel)
  /* 
  headerPresets.forEach(preset => {
    blockManager.add(preset.id, {
      label: preset.label,
      category: 'Headers',
      content: preset.content,
      media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>', // Generic header icon
    })
  })
  */

  // Hero Section
  blockManager.add('hero-section', {
    label: 'Hero Section',
    category: 'Sections',
    content: `
      <section class="py-20 px-4 bg-gradient-to-r from-teal-600 to-cyan-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h1 class="text-5xl font-bold mb-6">Welcome to Your Website</h1>
          <p class="text-xl mb-8 opacity-90">Create stunning websites with our drag-and-drop builder</p>
          <a href="#" class="inline-block px-8 py-3 bg-white text-teal-600 font-semibold rounded-lg hover:bg-gray-100 transition">Get Started</a>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
  })

  // Features Grid
  blockManager.add('features-grid', {
    label: 'Features Grid',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-gray-50">
        <div class="max-w-6xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-12">Our Features</h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🚀</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Fast Performance</h3>
              <p class="text-gray-600">Lightning fast load times for better user experience</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🔒</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Secure</h3>
              <p class="text-gray-600">Enterprise-grade security for your peace of mind</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">📱</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Responsive</h3>
              <p class="text-gray-600">Looks great on all devices and screen sizes</p>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>',
  })

  // Pricing Table
  blockManager.add('pricing-table', {
    label: 'Pricing Table',
    category: 'Sections',
    content: `
      <section class="py-16 px-4">
        <div class="max-w-5xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-12">Pricing Plans</h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="border rounded-lg p-6 text-center">
              <h3 class="text-xl font-semibold mb-2">Starter</h3>
              <div class="text-4xl font-bold mb-4">$9<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>5 Projects</li>
                <li>Basic Support</li>
                <li>1GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 border border-teal-600 text-teal-600 rounded-lg hover:bg-teal-50 transition">Choose Plan</a>
            </div>
            <div class="border-2 border-teal-600 rounded-lg p-6 text-center relative">
              <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-teal-600 text-white px-3 py-1 text-sm rounded-full">Popular</span>
              <h3 class="text-xl font-semibold mb-2">Professional</h3>
              <div class="text-4xl font-bold mb-4">$29<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>Unlimited Projects</li>
                <li>Priority Support</li>
                <li>10GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition">Choose Plan</a>
            </div>
            <div class="border rounded-lg p-6 text-center">
              <h3 class="text-xl font-semibold mb-2">Enterprise</h3>
              <div class="text-4xl font-bold mb-4">$99<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>Unlimited Everything</li>
                <li>24/7 Support</li>
                <li>100GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 border border-teal-600 text-teal-600 rounded-lg hover:bg-teal-50 transition">Contact Us</a>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg>',
  })

  // Contact Section
  blockManager.add('contact-section', {
    label: 'Contact Section',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-gray-900 text-white">
        <div class="max-w-4xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-8">Get In Touch</h2>
          <form class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <input type="text" placeholder="Your Name" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none">
            <input type="email" placeholder="Your Email" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none">
            <textarea placeholder="Your Message" rows="4" class="md:col-span-2 px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none"></textarea>
            <button type="submit" class="md:col-span-2 px-8 py-3 bg-teal-600 text-white font-semibold rounded-lg hover:bg-teal-700 transition">Send Message</button>
          </form>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
  })

  // Footer
  blockManager.add('footer', {
    label: 'Footer',
    category: 'Sections',
    content: `
      <footer class="py-12 px-4 bg-gray-900 text-gray-400">
        <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-8">
          <div>
            <h4 class="text-white font-semibold mb-4">Company</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">About Us</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Careers</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Contact</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Products</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Features</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Pricing</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Integrations</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Resources</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Documentation</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Blog</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Support</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Legal</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Privacy Policy</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Terms of Service</a></li>
            </ul>
          </div>
        </div>
        <div class="max-w-6xl mx-auto mt-8 pt-8 border-t border-gray-800 text-center">
          <p>&copy; 2025 Your Company. All rights reserved.</p>
        </div>
      </footer>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h12v2H6v-2z"/></svg>',
  })

  // Testimonials
  blockManager.add('testimonials', {
    label: 'Testimonials',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-teal-50">
        <div class="max-w-4xl mx-auto text-center">
          <h2 class="text-3xl font-bold mb-12">What Our Customers Say</h2>
          <div class="bg-white p-8 rounded-xl shadow-sm">
            <p class="text-xl text-gray-600 italic mb-6">"This website builder has transformed how we create landing pages. It's intuitive, powerful, and saves us hours of development time."</p>
            <div class="flex items-center justify-center gap-4">
              <div class="w-12 h-12 bg-teal-200 rounded-full"></div>
              <div class="text-left">
                <p class="font-semibold">John Doe</p>
                <p class="text-gray-500 text-sm">CEO, TechCorp</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></svg>',
  })

  // CTA Banner
  blockManager.add('cta-banner', {
    label: 'CTA Banner',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-teal-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h2 class="text-3xl font-bold mb-4">Ready to Get Started?</h2>
          <p class="text-xl opacity-90 mb-8">Join thousands of satisfied customers building amazing websites</p>
          <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="#" class="px-8 py-3 bg-white text-teal-600 font-semibold rounded-lg hover:bg-gray-100 transition">Start Free Trial</a>
            <a href="#" class="px-8 py-3 border-2 border-white text-white font-semibold rounded-lg hover:bg-white/10 transition">Learn More</a>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>',
  })

  // Navigation
  blockManager.add('navbar', {
    label: 'Navigation',
    category: 'Sections',
    content: `
      <nav class="py-4 px-6 bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
          <a href="#" class="text-2xl font-bold text-teal-600">Logo</a>
          <div class="hidden md:flex items-center gap-8">
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Home</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Features</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Pricing</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Contact</a>
          </div>
          <a href="#" class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition">Sign Up</a>
        </div>
      </nav>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>',
  })

  // ==========================================
  // Lucide Icons Collection (Subset for Demo)
  // ==========================================
  const LUCIDE_ICONS: Record<string, string> = {
    'ArrowRight': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>',
    'ArrowLeft': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>',
    'ArrowUp': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>',
    'ArrowDown': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>',
    'ChevronRight': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
    'ChevronLeft': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
    'ChevronUp': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>',
    'ChevronDown': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
    'Search': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
    'Menu': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/></svg>',
    'Home': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'User': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'Users': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'ShoppingCart': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
    'Settings': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.47a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
    'Mail': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
    'Phone': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    'MapPin': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
    'Calendar': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
    'Clock': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'Heart': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
    'Star': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'Check': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'X': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 18 12"/></svg>',
    'Plus': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
    'Minus': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>',
    'Image': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.08-3.08a2 2 0 0 0-2.83 0L5 21"/></svg>',
    'File': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>',
    'Folder': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>',
    'Download': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
    'Upload': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
    'ExternalLink': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>',
    'Link': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
    'Facebook': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
    'Twitter': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 4s-.7 2.1-2 3.4c1.6 10-9.4 17.3-18 11.6 2.2.1 4.4-.6 6-2C3 15.5.5 9.6 3 5c2.2 2.6 5.6 4.1 9 4-.9-4.2 4-6.6 7-3.8 1.1 0 3-1.2 3-1.2z"/></svg>',
    'Instagram': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/></svg>',
    'Linkedin': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/></svg>',
    'Github': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/></svg>',
    'Play': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
    'Pause': '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="4" height="16" x="6" y="4"/><rect width="4" height="16" x="14" y="4"/></svg>'
  }

  // Add Icon Blocks
  Object.entries(LUCIDE_ICONS).forEach(([name, svg]) => {
    blockManager.add(`icon-${name.toLowerCase()}`, {
      label: name,
      category: 'Icons',
      content: svg,
      media: svg
    })
  })

  // Apply custom class to Icons category for 8-column grid layout
  // We need to wait for the block manager to render
  const styleIconsCategory = () => {
    const blocksContainer = editor.getContainer()?.querySelector('.gjs-blocks-c')
    if (!blocksContainer) return

    // Find the Icons category
    const categories = editor.getContainer()?.querySelectorAll('.gjs-block-category')
    categories?.forEach((cat: any) => {
      const title = cat.querySelector('.gjs-title')?.textContent
      if (title && title.includes('Icons')) {
        cat.classList.add('gjs-category-icons')
        
        // Ensure standard search works (it does by default on block labels)
        // But we can add a placeholder or hint if needed
      }
    })
  }

  editor.on('load', styleIconsCategory)
  // Also check periodically in case of re-renders
  editor.on('run:open-blocks', () => setTimeout(styleIconsCategory, 50))
}

function customizePanelIcons(editor: Editor) {
  const pn = editor.Panels
  
  // Debug: Log all buttons in the options panel to help identify the correct IDs
  const optionsPanel = pn.getPanel('options')
  if (optionsPanel) {
    const buttons = optionsPanel.get('buttons') as any
    if (buttons) {
      console.log('Options Panel Buttons:', buttons.map((b: any) => ({
        id: b.get('id'),
        command: b.get('command'),
        className: b.get('className'),
        attributes: b.get('attributes')
      })))

      // Aggressively find and remove preview/visibility buttons
      // Method 1: Remove by known IDs
      pn.removeButton('options', 'sw-visibility')
      pn.removeButton('options', 'preview')
      pn.removeButton('options', 'fullscreen') // Remove fullscreen too if it's there and confusing

      // Method 2: Remove by searching for properties (in case IDs are different)
      // We use a while loop because modifying the collection while iterating can be tricky
      let btnToRemove = buttons.find((b: any) => 
        b.get('id') === 'preview' || 
        b.get('command') === 'preview' || 
        b.get('command') === 'core:preview' ||
        (b.get('className') && b.get('className').includes('fa-eye')) ||
        (b.get('attributes') && b.get('attributes').title === 'Preview')
      )

      while (btnToRemove) {
        console.log('Removing button:', btnToRemove.get('id'))
        buttons.remove(btnToRemove)
        btnToRemove = buttons.find((b: any) => 
          b.get('id') === 'preview' || 
          b.get('command') === 'preview' || 
          b.get('command') === 'core:preview' ||
          (b.get('className') && b.get('className').includes('fa-eye')) ||
          (b.get('attributes') && b.get('attributes').title === 'Preview')
        )
      }
    }
  }
  
  // Method 3: CSS Hiding (The Nuclear Option)
  // Inject a style to force hide the eye icon if JS removal fails
  const style = document.createElement('style')
  style.innerHTML = `
    .gjs-pn-btn.fa-eye, 
    .gjs-pn-btn[title="Preview"], 
    .gjs-pn-btn[title="View components"] { 
      display: none !important; 
    }
  `
  document.head.appendChild(style)

  // Add Upload Button command
  editor.Commands.add('upload-file', {
    run: (editor) => {
      const input = document.createElement('input')
      input.type = 'file'
      input.accept = '.html,.css,.js,.txt'
      input.multiple = true
      input.onchange = (e: any) => {
        const files = e.target.files
        if (!files) return
        
        Array.from(files).forEach((file: any) => {
          const reader = new FileReader()
          reader.onload = (event) => {
            const content = event.target?.result as string
            if (file.name.endsWith('.css')) {
              editor.addStyle(content)
            } else if (file.name.endsWith('.js')) {
              const wrapper = editor.getWrapper()
              if (wrapper) {
                wrapper.append({
                  tagName: 'script',
                  content: content,
                  attributes: { type: 'text/javascript' }
                })
              }
            } else {
              // Assume HTML
              editor.addComponents(content)
            }
          }
          reader.readAsText(file)
        })
      }
      input.click()
    }
  })

  // Add Upload button before the download/import icon
  // Re-fetch panel to ensure we have the latest reference and avoid variable shadowing
  const optionsPanelRef = pn.getPanel('options')
  if (optionsPanelRef) {
    const buttons = optionsPanelRef.get('buttons') as any
    if (buttons) {
      // Try to find the import button (usually 'gjs-open-import-webpage')
      // If not found, try to find the last button or just append
      const targetBtn = buttons.find((b: any) => b.get('id') === 'gjs-open-import-webpage') || 
                        buttons.find((b: any) => b.get('id') === 'export-template')
      
      const index = targetBtn ? buttons.indexOf(targetBtn) : buttons.length
      
      // Check if button already exists to prevent duplicates
      const existingBtn = buttons.find((b: any) => b.get('id') === 'upload-file')
      if (!existingBtn) {
        buttons.add({
          id: 'upload-file',
          command: 'upload-file',
          className: 'fa fa-upload',
          attributes: { title: 'Upload HTML/CSS/JS' },
          label: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>`
        }, { at: index })
      }
    }
  }
  
  // Style Manager - brush/wand icon
  const smBtn = pn.getButton('views', 'open-sm')
  if (smBtn) {
    smBtn.set('label', `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M3.845 3.845a2.883 2.883 0 0 0 0 4.077L5.432 9.51c.012-.014.555.503.568.49l4-4c.013-.013-.504-.556-.49-.568L7.922 3.845a2.883 2.883 0 0 0-4.077 0m1.288 11.462a.483.483 0 0 1 .9 0l.157.4a.48.48 0 0 0 .272.273l.398.157a.486.486 0 0 1 0 .903l-.398.158a.48.48 0 0 0-.272.273l-.157.4a.483.483 0 0 1-.9 0l-.157-.4a.48.48 0 0 0-.272-.273l-.398-.158a.486.486 0 0 1 0-.903l.398-.157a.48.48 0 0 0 .272-.274z" opacity="0.5"/><path fill="currentColor" d="M19.967 9.13a.483.483 0 0 1 .9 0l.156.399c.05.125.148.224.273.273l.398.158a.486.486 0 0 1 0 .902l-.398.158a.5.5 0 0 0-.273.273l-.156.4a.483.483 0 0 1-.9 0l-.157-.4a.5.5 0 0 0-.272-.273l-.398-.158a.486.486 0 0 1 0-.902l.398-.158a.5.5 0 0 0 .272-.273z" opacity="0.2"/><path fill="currentColor" d="M16.1 2.307a.483.483 0 0 1 .9 0l.43 1.095a.48.48 0 0 0 .272.274l1.091.432a.486.486 0 0 1 0 .903l-1.09.432a.5.5 0 0 0-.273.273L17 6.81a.483.483 0 0 1-.9 0l-.43-1.095a.5.5 0 0 0-.273-.273l-1.09-.432a.486.486 0 0 1 0-.903l1.09-.432a.5.5 0 0 0 .273-.274z" opacity="0.7"/><path fill="currentColor" d="M10.568 6.49c-.012.014-.555-.503-.568-.49l-4 4c-.013.013.504.556.49.568l9.588 9.587a2.883 2.883 0 1 0 4.078-4.077z"/></svg>`)
  }

  // Settings - cog icon
  const tmBtn = pn.getButton('views', 'open-tm')
  if (tmBtn) {
    tmBtn.set('label', `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M14.279 2.152C13.909 2 13.439 2 12.5 2s-1.408 0-1.779.152a2 2 0 0 0-1.09 1.083c-.094.223-.13.484-.145.863a1.62 1.62 0 0 1-.796 1.353a1.64 1.64 0 0 1-1.579.008c-.338-.178-.583-.276-.825-.308a2.03 2.03 0 0 0-1.49.396c-.318.242-.553.646-1.022 1.453c-.47.807-.704 1.21-.757 1.605c-.07.526.074 1.058.4 1.479c.148.192.357.353.68.555c.477.297.783.803.783 1.361s-.306 1.064-.782 1.36c-.324.203-.533.364-.682.556a2 2 0 0 0-.399 1.479c.053.394.287.798.757 1.605s.704 1.21 1.022 1.453c.424.323.96.465 1.49.396c.242-.032.487-.13.825-.308a1.64 1.64 0 0 1 1.58.008c.486.28.774.795.795 1.353c.015.38.051.64.145.863c.204.49.596.88 1.09 1.083c.37.152.84.152 1.779.152s1.409 0 1.779-.152a2 2 0 0 0 1.09-1.083c.094-.223.13-.483.145-.863c.02-.558.309-1.074.796-1.353a1.64 1.64 0 0 1 1.579-.008c.338.178.583.276.825.308c.53.07 1.066-.073 1.49-.396c.318-.242.553-.646 1.022-1.453c.47-.807.704-1.21.757-1.605a2 2 0 0 0-.4-1.479c-.148-.192-.357-.353-.68-.555c-.477-.297-.783-.803-.783-1.361s.306-1.064.782-1.36c.324-.203.533-.364.682-.556a2 2 0 0 0 .399-1.479c-.053-.394-.287-.798-.757-1.605s-.704-1.21-1.022-1.453a2.03 2.03 0 0 0-1.49-.396c-.242.032-.487.13-.825.308a1.64 1.64 0 0 1-1.58-.008a1.62 1.62 0 0 1-.795-1.353c-.015-.38-.051-.64-.145-.863a2 2 0 0 0-1.09-1.083" clip-rule="evenodd" opacity="0.5"/><path fill="currentColor" d="M15.523 12c0 1.657-1.354 3-3.023 3s-3.023-1.343-3.023-3S10.83 9 12.5 9s3.023 1.343 3.023 3"/></svg>`)
  }

  // Layers - layers icon
  const layersBtn = pn.getButton('views', 'open-layers')
  if (layersBtn) {
    layersBtn.set('label', `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.979 9.685C2.993 8.891 2 8.494 2 8s.993-.89 2.979-1.685l2.808-1.123C9.773 4.397 10.767 4 12 4s2.227.397 4.213 1.192l2.808 1.123C21.007 7.109 22 7.506 22 8s-.993.89-2.979 1.685l-2.808 1.124C14.227 11.603 13.233 12 12 12s-2.227-.397-4.213-1.191z"/><path d="m5.766 10l-.787.315C2.993 11.109 2 11.507 2 12s.993.89 2.979 1.685l2.808 1.124C9.773 15.603 10.767 16 12 16s2.227-.397 4.213-1.191l2.808-1.124C21.007 12.891 22 12.493 22 12s-.993-.89-2.979-1.685L18.234 10M5.766 14l-.787.315C2.993 15.109 2 15.507 2 16s.993.89 2.979 1.685l2.808 1.124C9.773 19.603 10.767 20 12 20s2.227-.397 4.213-1.192l2.808-1.123C21.007 16.891 22 16.494 22 16c0-.493-.993-.89-2.979-1.685L18.234 14" opacity="0.5"/></g></svg>`)
  }

  // Blocks - grid icon
  const blocksBtn = pn.getButton('views', 'open-blocks')
  if (blocksBtn) {
    blocksBtn.set('label', `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" d="M14.5 6.5h3m0 0h3m-3 0v3m0-3v-3"/><path d="M2.5 6.5c0-1.886 0-2.828.586-3.414S4.614 2.5 6.5 2.5s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586S2.5 8.386 2.5 6.5Zm11 11c0-1.886 0-2.828.586-3.414s1.528-.586 3.414-.586s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586s-.586-1.528-.586-3.414Z"/><path d="M2.5 17.5c0-1.886 0-2.828.586-3.414S4.614 13.5 6.5 13.5s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586S2.5 19.386 2.5 17.5Z" opacity="0.5"/></g></svg>`)
  }
}

// Custom Presets Panel
interface Preset {
  id: string
  label: string
  category: string
  content: string
}

function addPresetsPanel(editor: Editor) {
  const panelManager = editor.Panels
  const commandManager = editor.Commands
  
  // Helper function to initialize Lucide icons in the canvas
  const initCanvasLucide = () => {
    const frame = editor.Canvas.getFrameEl()
    if (frame && frame.contentWindow && frame.contentDocument) {
      const win = frame.contentWindow as any
      const doc = frame.contentDocument
      
      // Check if there are any uninitialized icons
      const uninitializedIcons = doc.querySelectorAll('i[data-lucide]')
      if (uninitializedIcons.length === 0) return
      
      if (win.lucide && typeof win.lucide.createIcons === 'function') {
        try {
          win.lucide.createIcons()
        } catch (e) {
          console.warn('Lucide createIcons error:', e)
        }
      } else if (doc) {
        // Inject Lucide if not loaded
        const existingScript = doc.querySelector('script[src*="lucide"]')
        if (!existingScript) {
          const script = doc.createElement('script')
          script.src = 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js'
          script.onload = () => {
            setTimeout(() => {
              if (win.lucide && typeof win.lucide.createIcons === 'function') {
                win.lucide.createIcons()
              }
            }, 100)
          }
          doc.head.appendChild(script)
        } else {
          // Retry after a delay
          setTimeout(initCanvasLucide, 200)
        }
      }
    }
  }
  
  // Add Presets button
  panelManager.addButton('views', {
    id: 'open-presets',
    className: 'gjs-pn-btn',
    command: 'open-presets',
    attributes: { title: 'Presets' },
    active: false,
    label: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 2H5a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V5a3 3 0 0 0-3-3m1 17a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1z" opacity="0.5"/><path fill="currentColor" d="M7 7h10v2H7zm0 4h10v2H7zm0 4h7v2H7z"/></svg>`,
  })

  // Create Presets Container
  const presetsContainer = document.createElement('div')
  presetsContainer.className = 'gjs-presets-c'
  presetsContainer.style.display = 'none'
  presetsContainer.style.height = '100%'
  presetsContainer.style.overflowY = 'auto'
  presetsContainer.style.overflowX = 'hidden'
  
  // Define categories
  const categories = ['Theme', 'Headers', 'Footers', 'Hero', 'Shop']
  
  categories.forEach((category, index) => {
    const categoryEl = document.createElement('div')
    categoryEl.className = 'gjs-block-category'
    categoryEl.style.borderBottom = '1px solid rgba(0,0,0,0.05)'
    
    // Category Header
    const titleEl = document.createElement('div')
    titleEl.className = 'gjs-title'
    titleEl.style.cursor = 'pointer'
    titleEl.style.display = 'flex'
    titleEl.style.alignItems = 'center'
    titleEl.style.padding = '10px 15px'
    titleEl.style.userSelect = 'none'
    titleEl.style.background = 'rgba(0,0,0,0.01)'
    
    // Caret Icon
    const caretEl = document.createElement('div')
    caretEl.className = 'gjs-caret-icon'
    caretEl.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>'
    caretEl.style.marginRight = '8px'
    caretEl.style.transition = 'transform 0.2s ease'
    caretEl.style.transform = 'rotate(-90deg)' // Default closed (pointing right)
    
    titleEl.appendChild(caretEl)
    titleEl.appendChild(document.createTextNode(category))
    
    // Category Content
    const contentEl = document.createElement('div')
    contentEl.className = 'gjs-blocks-c'
    contentEl.style.padding = '10px'
    contentEl.style.display = 'none' // Hidden by default
    
    // Populate Headers category with special UI
    if (category === 'Headers') {
      contentEl.innerHTML = '' // Clear default
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      
      headerPresets.forEach(preset => {
        const itemEl = document.createElement('div')
        itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
        itemEl.style.cursor = 'grab'
        itemEl.style.padding = '5px'
        itemEl.style.border = '1px solid rgba(0,0,0,0.1)'
        itemEl.style.borderRadius = '3px'
        itemEl.style.display = 'flex'
        itemEl.style.flexDirection = 'column'
        itemEl.style.alignItems = 'center'
        itemEl.style.justifyContent = 'center'
        itemEl.style.minHeight = '60px'
        itemEl.style.transition = 'all 0.2s ease'
        itemEl.style.setProperty('width', '100%', 'important') // Ensure it fits the grid column
        itemEl.style.setProperty('min-width', '0', 'important')
        itemEl.style.boxSizing = 'border-box'
        
        // Label
        const labelEl = document.createElement('div')
        labelEl.className = 'gjs-block-label'
        labelEl.textContent = preset.label
        labelEl.style.marginBottom = '2px'
        labelEl.style.fontSize = '10px'
        labelEl.style.fontWeight = '500'
        labelEl.style.textAlign = 'center'
        labelEl.style.lineHeight = '1.2'
        
        // Preview/Icon
        const iconEl = document.createElement('div')
        
        // Determine icon based on preset ID
        let iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 4h18v4H3V4zm0 6h18v10H3V10z" opacity="0.3"/><path d="M3 4h18v4H3z"/></svg>' // Default
        
        if (preset.id.includes('search')) {
           // Search icon
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>'
        } else if (preset.id.includes('corporate')) {
           // Phone/Contact icon
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>'
        } else if (preset.id.includes('mega') || preset.id.includes('stacked')) {
           // List/Mega menu icon
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>'
        } else if (preset.id.includes('minimal')) {
           // Minimal/Burger icon
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 12h18v2H3zm0-7h18v2H3zm0 14h18v2H3z"/></svg>'
        } else if (preset.id.includes('cart') || preset.id.includes('shop')) {
           // Cart icon (if any future presets use this)
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>'
        }
        
        iconEl.innerHTML = iconSvg
        
        itemEl.appendChild(iconEl)
        itemEl.appendChild(labelEl)
        
        // Hover effect
        itemEl.onmouseenter = () => {
          itemEl.style.borderColor = '#2a9d8f'
          itemEl.style.boxShadow = '0 2px 5px rgba(0,0,0,0.1)'
        }
        itemEl.onmouseleave = () => {
          itemEl.style.borderColor = 'rgba(0,0,0,0.1)'
          itemEl.style.boxShadow = 'none'
        }

        // Drag functionality (Integration with GrapesJS Canvas)
        itemEl.setAttribute('draggable', 'true')
        
        itemEl.addEventListener('dragstart', (e) => {
          // Set standard HTML drag data
          e.dataTransfer?.setData('text/html', preset.content)
          // Note: Native block drag trigger removed as blocks are no longer registered in BlockManager
        })

        itemEl.addEventListener('dragend', (e) => {
           // Cleanup if needed
        })
        
        // Click to append
        itemEl.onclick = () => {
          editor.addComponents(preset.content)
          // Initialize Lucide icons after adding component
          setTimeout(initCanvasLucide, 200)
          setTimeout(initCanvasLucide, 500)
        }
        
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Footers') {
      contentEl.innerHTML = '' // Clear default
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      
      footerPresets.forEach((preset: Preset) => {
        const itemEl = document.createElement('div')
        itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
        itemEl.style.cursor = 'grab'
        itemEl.style.padding = '5px'
        itemEl.style.border = '1px solid rgba(0,0,0,0.1)'
        itemEl.style.borderRadius = '3px'
        itemEl.style.display = 'flex'
        itemEl.style.flexDirection = 'column'
        itemEl.style.alignItems = 'center'
        itemEl.style.justifyContent = 'center'
        itemEl.style.minHeight = '60px'
        itemEl.style.transition = 'all 0.2s ease'
        itemEl.style.setProperty('width', '100%', 'important')
        itemEl.style.setProperty('min-width', '0', 'important')
        itemEl.style.boxSizing = 'border-box'
        
        // Label
        const labelEl = document.createElement('div')
        labelEl.className = 'gjs-block-label'
        labelEl.textContent = preset.label
        labelEl.style.marginBottom = '2px'
        labelEl.style.fontSize = '10px'
        labelEl.style.fontWeight = '500'
        labelEl.style.textAlign = 'center'
        labelEl.style.lineHeight = '1.2'
        
        // Preview/Icon
        const iconEl = document.createElement('div')
        
        // Determine icon based on preset ID
        let iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M19 2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 18H5v-4h14v4z"/></svg>' // Default Footer Icon

        if (preset.id.includes('minimal')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M5 15h14v4H5v-4z"/></svg>'
        } else if (preset.id.includes('4-columns') || preset.id.includes('complex')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 14h4v6H4v-6zm6 0h4v6h-4v-6zm6 0h4v6h-4v-6z"/></svg>'
        } else if (preset.id.includes('newsletter')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>'
        } else if (preset.id.includes('dark')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="14" width="18" height="6" rx="1" fill="currentColor"/><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.2.05-.4.09-.61.15zM3.18 6.34a9.006 9.006 0 0 1 8.82-3.34c-1.64.44-3 1.54-3.8 2.94a5.384 5.384 0 0 0-1.88 4.6 9 9 0 0 1-3.14-4.2z"/></svg>'
        } else if (preset.id.includes('social')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>'
        } else if (preset.id.includes('map')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'
        } else if (preset.id.includes('app')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18l9-9m-9 9l-9-9m9 9v-16"/></svg>'
        }

        iconEl.innerHTML = iconSvg
        
        itemEl.appendChild(iconEl)
        itemEl.appendChild(labelEl)
        
        // Hover effect
        itemEl.onmouseenter = () => {
          itemEl.style.borderColor = '#2a9d8f'
          itemEl.style.boxShadow = '0 2px 5px rgba(0,0,0,0.1)'
        }
        itemEl.onmouseleave = () => {
          itemEl.style.borderColor = 'rgba(0,0,0,0.1)'
          itemEl.style.boxShadow = 'none'
        }

        // Drag functionality
        itemEl.setAttribute('draggable', 'true')
        itemEl.addEventListener('dragstart', (e) => {
          e.dataTransfer?.setData('text/html', preset.content)
        })

        // Click to append
        itemEl.onclick = () => {
          editor.addComponents(preset.content)
          // Initialize Lucide icons after adding component
          setTimeout(initCanvasLucide, 200)
          setTimeout(initCanvasLucide, 500)
        }
        
        contentEl.appendChild(itemEl)
       })
    } else if (category === 'Hero') {
      contentEl.innerHTML = '' // Clear default
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      
      heroPresets.forEach(preset => {
        const itemEl = document.createElement('div')
        itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
        itemEl.style.cursor = 'grab'
        itemEl.style.padding = '5px'
        itemEl.style.border = '1px solid rgba(0,0,0,0.1)'
        itemEl.style.borderRadius = '3px'
        itemEl.style.display = 'flex'
        itemEl.style.flexDirection = 'column'
        itemEl.style.alignItems = 'center'
        itemEl.style.justifyContent = 'center'
        itemEl.style.minHeight = '60px'
        itemEl.style.transition = 'all 0.2s ease'
        itemEl.style.setProperty('width', '100%', 'important')
        itemEl.style.setProperty('min-width', '0', 'important')
        itemEl.style.boxSizing = 'border-box'
        
        // Label
        const labelEl = document.createElement('div')
        labelEl.className = 'gjs-block-label'
        labelEl.textContent = preset.label
        labelEl.style.marginBottom = '2px'
        labelEl.style.fontSize = '10px'
        labelEl.style.fontWeight = '500'
        labelEl.style.textAlign = 'center'
        labelEl.style.lineHeight = '1.2'
        
        // Preview/Icon
        const iconEl = document.createElement('div')
        
        // Determine icon based on preset ID
        let iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>' // Default Hero Icon

        if (preset.id.includes('saas') || preset.id.includes('product')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></svg>'
        } else if (preset.id.includes('split')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 4h7v16H4V4zm9 0h7v16h-7V4z"/></svg>'
        } else if (preset.id.includes('dark')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor"/><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="white"/></svg>'
        } else if (preset.id.includes('agency') || preset.id.includes('creative')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" /></svg>'
        } else if (preset.id.includes('app')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="5" y="2" width="14" height="20" rx="2" /><path d="M12 18h.01" /></svg>'
        } else if (preset.id.includes('video')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M10 9v6l5-3-5-3z"/><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/></svg>'
        } else if (preset.id.includes('minimal')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 9h16v2H4V9zm4 4h8v2H8v-2z"/></svg>'
        } else if (preset.id.includes('fashion') || preset.id.includes('ecommerce')) {
           iconSvg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>'
        }

        iconEl.innerHTML = iconSvg
        
        itemEl.appendChild(iconEl)
        itemEl.appendChild(labelEl)
        
        // Hover effect
        itemEl.onmouseenter = () => {
          itemEl.style.borderColor = '#2a9d8f'
          itemEl.style.boxShadow = '0 2px 5px rgba(0,0,0,0.1)'
        }
        itemEl.onmouseleave = () => {
          itemEl.style.borderColor = 'rgba(0,0,0,0.1)'
          itemEl.style.boxShadow = 'none'
        }

        // Drag functionality
        itemEl.setAttribute('draggable', 'true')
        itemEl.addEventListener('dragstart', (e) => {
          e.dataTransfer?.setData('text/html', preset.content)
        })

        // Click to append
        itemEl.onclick = () => {
          editor.addComponents(preset.content)
          // Initialize Lucide icons after adding component
          setTimeout(initCanvasLucide, 200)
          setTimeout(initCanvasLucide, 500)
        }
        
        contentEl.appendChild(itemEl)
      })
    } else {
      contentEl.innerHTML = `<div style="width: 100%; padding: 20px 0; color: #888; font-size: 12px; text-align: center;">No presets available</div>`
    }
    
    // Toggle Logic
    const toggle = (forceOpen?: boolean) => {
      const isOpen = forceOpen !== undefined ? forceOpen : contentEl.style.display === 'none'
      
      if (isOpen) {
        if (category === 'Headers' || category === 'Footers' || category === 'Hero') {
          contentEl.style.setProperty('display', 'grid', 'important')
          contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
          contentEl.style.setProperty('gap', '5px', 'important')
        } else {
          contentEl.style.display = 'block'
        }
      } else {
        contentEl.style.display = 'none'
      }

      caretEl.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(-90deg)'
      categoryEl.classList.toggle('gjs-open', isOpen)
    }

    titleEl.onclick = () => toggle()
    
    categoryEl.appendChild(titleEl)
    categoryEl.appendChild(contentEl)
    presetsContainer.appendChild(categoryEl)
    
    // Open first category by default
    if (index === 0) {
      toggle(true)
    }
  })
  
  // Append to views container with retry
  const appendToContainer = () => {
    const viewsContainer = editor.getContainer()?.querySelector('.gjs-pn-views-container')
    if (viewsContainer) {
      viewsContainer.appendChild(presetsContainer)
    } else {
      // Retry if container not found yet
      setTimeout(appendToContainer, 100)
    }
  }
  
  appendToContainer()

  // Define command
  commandManager.add('open-presets', {
    run(editor) {
      // Hide other containers
      const container = editor.getContainer()
      if (!container) return
      
      const views = ['.gjs-sm-c', '.gjs-lm-c', '.gjs-tm-c', '.gjs-blocks-c']
      views.forEach(selector => {
        const el = container.querySelector(selector) as HTMLElement
        if (el) el.style.display = 'none'
      })
      
      // Show presets
      presetsContainer.style.display = 'block'
      
      // Deactivate other buttons
      panelManager.getButton('views', 'open-blocks')?.set('active', false)
      panelManager.getButton('views', 'open-sm')?.set('active', false)
      panelManager.getButton('views', 'open-tm')?.set('active', false)
      panelManager.getButton('views', 'open-layers')?.set('active', false)
    },
    stop() {
      presetsContainer.style.display = 'none'
    }
  })

  // Listen to other commands to hide presets
  const hidePresets = () => {
    presetsContainer.style.display = 'none'
    panelManager.getButton('views', 'open-presets')?.set('active', false)
  }
  
  editor.on('run:open-blocks', hidePresets)
  editor.on('run:open-sm', hidePresets)
  editor.on('run:open-tm', hidePresets)
  editor.on('run:open-layers', hidePresets)
}

export default GrapesEditor
