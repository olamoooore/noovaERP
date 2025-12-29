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
          'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@300;400;500;600;700&family=Lato:wght@300;400;700&family=Montserrat:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Raleway:wght@300;400;500;600;700&family=Nunito:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@300;400;700&family=Source+Sans+Pro:wght@300;400;600;700&family=Ubuntu:wght@300;400;500;700&family=Oswald:wght@300;400;500;600;700&family=Quicksand:wght@300;400;500;600;700&family=Work+Sans:wght@300;400;500;600;700&family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap',
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

    // Customize panel view icons with Solar duotone icons
    const updatePanelIcons = () => {
      const panelButtons = document.querySelectorAll('.gjs-pn-views .gjs-pn-btn')
      const solarIcons = [
        // Style Manager - brush/wand icon
        `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M3.845 3.845a2.883 2.883 0 0 0 0 4.077L5.432 9.51c.012-.014.555.503.568.49l4-4c.013-.013-.504-.556-.49-.568L7.922 3.845a2.883 2.883 0 0 0-4.077 0m1.288 11.462a.483.483 0 0 1 .9 0l.157.4a.48.48 0 0 0 .272.273l.398.157a.486.486 0 0 1 0 .903l-.398.158a.48.48 0 0 0-.272.273l-.157.4a.483.483 0 0 1-.9 0l-.157-.4a.48.48 0 0 0-.272-.273l-.398-.158a.486.486 0 0 1 0-.903l.398-.157a.48.48 0 0 0 .272-.274z" opacity="0.5"/><path fill="currentColor" d="M19.967 9.13a.483.483 0 0 1 .9 0l.156.399c.05.125.148.224.273.273l.398.158a.486.486 0 0 1 0 .902l-.398.158a.5.5 0 0 0-.273.273l-.156.4a.483.483 0 0 1-.9 0l-.157-.4a.5.5 0 0 0-.272-.273l-.398-.158a.486.486 0 0 1 0-.902l.398-.158a.5.5 0 0 0 .272-.273z" opacity="0.2"/><path fill="currentColor" d="M16.1 2.307a.483.483 0 0 1 .9 0l.43 1.095a.48.48 0 0 0 .272.274l1.091.432a.486.486 0 0 1 0 .903l-1.09.432a.5.5 0 0 0-.273.273L17 6.81a.483.483 0 0 1-.9 0l-.43-1.095a.5.5 0 0 0-.273-.273l-1.09-.432a.486.486 0 0 1 0-.903l1.09-.432a.5.5 0 0 0 .273-.274z" opacity="0.7"/><path fill="currentColor" d="M10.568 6.49c-.012.014-.555-.503-.568-.49l-4 4c-.013.013.504.556.49.568l9.588 9.587a2.883 2.883 0 1 0 4.078-4.077z"/></svg>`,
        // Settings - cog icon
        `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M14.279 2.152C13.909 2 13.439 2 12.5 2s-1.408 0-1.779.152a2 2 0 0 0-1.09 1.083c-.094.223-.13.484-.145.863a1.62 1.62 0 0 1-.796 1.353a1.64 1.64 0 0 1-1.579.008c-.338-.178-.583-.276-.825-.308a2.03 2.03 0 0 0-1.49.396c-.318.242-.553.646-1.022 1.453c-.47.807-.704 1.21-.757 1.605c-.07.526.074 1.058.4 1.479c.148.192.357.353.68.555c.477.297.783.803.783 1.361s-.306 1.064-.782 1.36c-.324.203-.533.364-.682.556a2 2 0 0 0-.399 1.479c.053.394.287.798.757 1.605s.704 1.21 1.022 1.453c.424.323.96.465 1.49.396c.242-.032.487-.13.825-.308a1.64 1.64 0 0 1 1.58.008c.486.28.774.795.795 1.353c.015.38.051.64.145.863c.204.49.596.88 1.09 1.083c.37.152.84.152 1.779.152s1.409 0 1.779-.152a2 2 0 0 0 1.09-1.083c.094-.223.13-.483.145-.863c.02-.558.309-1.074.796-1.353a1.64 1.64 0 0 1 1.579-.008c.338.178.583.276.825.308c.53.07 1.066-.073 1.49-.396c.318-.242.553-.646 1.022-1.453c.47-.807.704-1.21.757-1.605a2 2 0 0 0-.4-1.479c-.148-.192-.357-.353-.68-.555c-.477-.297-.783-.803-.783-1.361s.306-1.064.782-1.36c.324-.203.533-.364.682-.556a2 2 0 0 0 .399-1.479c-.053-.394-.287-.798-.757-1.605s-.704-1.21-1.022-1.453a2.03 2.03 0 0 0-1.49-.396c-.242.032-.487.13-.825.308a1.64 1.64 0 0 1-1.58-.008a1.62 1.62 0 0 1-.795-1.353c-.015-.38-.051-.64-.145-.863a2 2 0 0 0-1.09-1.083" clip-rule="evenodd" opacity="0.5"/><path fill="currentColor" d="M15.523 12c0 1.657-1.354 3-3.023 3s-3.023-1.343-3.023-3S10.83 9 12.5 9s3.023 1.343 3.023 3"/></svg>`,
        // Layers - layers icon
        `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4.979 9.685C2.993 8.891 2 8.494 2 8s.993-.89 2.979-1.685l2.808-1.123C9.773 4.397 10.767 4 12 4s2.227.397 4.213 1.192l2.808 1.123C21.007 7.109 22 7.506 22 8s-.993.89-2.979 1.685l-2.808 1.124C14.227 11.603 13.233 12 12 12s-2.227-.397-4.213-1.191z"/><path d="m5.766 10l-.787.315C2.993 11.109 2 11.507 2 12s.993.89 2.979 1.685l2.808 1.124C9.773 15.603 10.767 16 12 16s2.227-.397 4.213-1.191l2.808-1.124C21.007 12.891 22 12.493 22 12s-.993-.89-2.979-1.685L18.234 10M5.766 14l-.787.315C2.993 15.109 2 15.507 2 16s.993.89 2.979 1.685l2.808 1.124C9.773 19.603 10.767 20 12 20s2.227-.397 4.213-1.192l2.808-1.123C21.007 16.891 22 16.494 22 16c0-.493-.993-.89-2.979-1.685L18.234 14" opacity="0.5"/></g></svg>`,
        // Blocks - grid icon
        `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" d="M14.5 6.5h3m0 0h3m-3 0v3m0-3v-3"/><path d="M2.5 6.5c0-1.886 0-2.828.586-3.414S4.614 2.5 6.5 2.5s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586S2.5 8.386 2.5 6.5Zm11 11c0-1.886 0-2.828.586-3.414s1.528-.586 3.414-.586s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586s-.586-1.528-.586-3.414Z"/><path d="M2.5 17.5c0-1.886 0-2.828.586-3.414S4.614 13.5 6.5 13.5s2.828 0 3.414.586s.586 1.528.586 3.414s0 2.828-.586 3.414s-1.528.586-3.414.586s-2.828 0-3.414-.586S2.5 19.386 2.5 17.5Z" opacity="0.5"/></g></svg>`,
      ]
      
      panelButtons.forEach((btn, index) => {
        if (solarIcons[index]) {
          btn.innerHTML = solarIcons[index]
        }
      })
    }

    // Initial icon update
    setTimeout(updatePanelIcons, 100)

    // Listen for panel changes to re-apply icons
    editor.on('run:open-sm', updatePanelIcons)
    editor.on('run:open-tm', updatePanelIcons)
    editor.on('run:open-layers', updatePanelIcons)
    editor.on('run:open-blocks', updatePanelIcons)
    
    // Also listen for any panel button clicks
    const panelViewsContainer = document.querySelector('.gjs-pn-views')
    if (panelViewsContainer) {
      panelViewsContainer.addEventListener('click', () => {
        setTimeout(updatePanelIcons, 50)
      })
    }

    return () => {
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
}

export default GrapesEditor
