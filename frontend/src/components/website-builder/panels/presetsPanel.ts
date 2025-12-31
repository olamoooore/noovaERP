// Presets Panel for GrapesJS Editor
import { Editor } from 'grapesjs'
import { headerPresets } from '../headerPresets'
import { footerPresets } from '../footerPresets'
import { heroPresets } from '../heroPresets'
import { initLucideIcons } from '../utils/lucide'
import { Preset } from '../types'

// Category icons for presets
const CATEGORY_ICONS = {
  header: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 4h18v4H3V4zm0 6h18v10H3V10z" opacity="0.3"/><path d="M3 4h18v4H3z"/></svg>',
    search: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>',
    corporate: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>',
    mega: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 12h18v2H3zm0-7h18v2H3zm0 14h18v2H3z"/></svg>',
  },
  footer: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M19 2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 18H5v-4h14v4z"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M5 15h14v4H5v-4z"/></svg>',
    columns: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 14h4v6H4v-6zm6 0h4v6h-4v-6zm6 0h4v6h-4v-6z"/></svg>',
    newsletter: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    social: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>',
    map: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    app: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18l9-9m-9 9l-9-9m9 9v-16"/></svg>',
  },
  hero: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
    saas: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></svg>',
    split: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 4h7v16H4V4zm9 0h7v16h-7V4z"/></svg>',
    dark: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor"/><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="white"/></svg>',
    agency: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" /></svg>',
    app: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="5" y="2" width="14" height="20" rx="2" /><path d="M12 18h.01" /></svg>',
    video: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M10 9v6l5-3-5-3z"/><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 9h16v2H4V9zm4 4h8v2H8v-2z"/></svg>',
    fashion: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>',
  },
}

function getIconForPreset(presetId: string, category: 'header' | 'footer' | 'hero'): string {
  if (category === 'header') {
    const icons = CATEGORY_ICONS.header
    if (presetId.includes('search')) return icons.search
    if (presetId.includes('corporate')) return icons.corporate
    if (presetId.includes('mega') || presetId.includes('stacked')) return icons.mega
    if (presetId.includes('minimal')) return icons.minimal
    return icons.default
  } else if (category === 'footer') {
    const icons = CATEGORY_ICONS.footer
    if (presetId.includes('minimal')) return icons.minimal
    if (presetId.includes('4-columns') || presetId.includes('complex')) return icons.columns
    if (presetId.includes('newsletter')) return icons.newsletter
    if (presetId.includes('social')) return icons.social
    if (presetId.includes('map')) return icons.map
    if (presetId.includes('app')) return icons.app
    return icons.default
  } else {
    const icons = CATEGORY_ICONS.hero
    if (presetId.includes('saas') || presetId.includes('product')) return icons.saas
    if (presetId.includes('split')) return icons.split
    if (presetId.includes('dark')) return icons.dark
    if (presetId.includes('agency') || presetId.includes('creative')) return icons.agency
    if (presetId.includes('app')) return icons.app
    if (presetId.includes('video')) return icons.video
    if (presetId.includes('minimal')) return icons.minimal
    if (presetId.includes('fashion') || presetId.includes('ecommerce')) return icons.fashion
    return icons.default
  }
}

export function addPresetsPanel(editor: Editor) {
  const panelManager = editor.Panels
  const commandManager = editor.Commands
  
  // Helper function to initialize Lucide icons in the canvas
  const initCanvasLucide = () => initLucideIcons(editor)
  
  // Create Presets Container FIRST (before command definition)
  const presetsContainer = document.createElement('div')
  presetsContainer.className = 'gjs-presets-c'
  presetsContainer.style.display = 'none'
  presetsContainer.style.height = '100%'
  presetsContainer.style.overflowY = 'auto'
  presetsContainer.style.overflowX = 'hidden'
  
  // Define command BEFORE adding the button
  commandManager.add('open-presets', {
    run(editor) {
      const container = editor.getContainer()
      if (!container) return
      
      // Show presets panel
      presetsContainer.style.display = 'block'
      
      // Run stop on other view commands to properly hide their panels
      // This ensures GrapesJS properly manages panel visibility
      editor.Commands.stop('open-blocks')
      editor.Commands.stop('open-sm')
      editor.Commands.stop('open-tm')
      editor.Commands.stop('open-layers')
      
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
  
  // Add Presets button AFTER command is defined
  panelManager.addButton('views', {
    id: 'open-presets',
    className: 'gjs-pn-btn',
    command: 'open-presets',
    attributes: { title: 'Presets' },
    active: false,
    label: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 2H5a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V5a3 3 0 0 0-3-3m1 17a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1z" opacity="0.5"/><path fill="currentColor" d="M7 7h10v2H7zm0 4h10v2H7zm0 4h7v2H7z"/></svg>`,
  })
  
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
    caretEl.style.transform = 'rotate(-90deg)'
    
    titleEl.appendChild(caretEl)
    titleEl.appendChild(document.createTextNode(category))
    
    // Category Content
    const contentEl = document.createElement('div')
    contentEl.className = 'gjs-blocks-c'
    contentEl.style.padding = '10px'
    contentEl.style.display = 'none'
    
    // Populate based on category
    if (category === 'Headers') {
      contentEl.innerHTML = ''
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      contentEl.style.display = 'none' // Start hidden
      
      headerPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'header', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Footers') {
      contentEl.innerHTML = ''
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      contentEl.style.display = 'none'
      
      footerPresets.forEach((preset: Preset) => {
        const itemEl = createPresetItem(preset, 'footer', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Hero') {
      contentEl.innerHTML = ''
      contentEl.style.setProperty('display', 'grid', 'important')
      contentEl.style.setProperty('grid-template-columns', 'repeat(3, 1fr)', 'important')
      contentEl.style.setProperty('gap', '5px', 'important')
      contentEl.style.display = 'none'
      
      heroPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'hero', editor, initCanvasLucide)
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
      setTimeout(appendToContainer, 100)
    }
  }
  
  appendToContainer()

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

function createPresetItem(
  preset: Preset,
  category: 'header' | 'footer' | 'hero',
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
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
  
  // Icon
  const iconEl = document.createElement('div')
  iconEl.innerHTML = getIconForPreset(preset.id, category)
  
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
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}
