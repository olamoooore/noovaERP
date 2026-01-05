// Custom Context Menu for GrapesJS Canvas
// v2 - with pasteStyleComponent export
import { Editor } from 'grapesjs'

let menuEl: HTMLDivElement | null = null
let currentEditor: Editor | null = null
let clipboardData: any = null
let clipboardStyles: any = null
const originalStylesMap = new Map<string, any>()

function getMenuHTML(): string {
  return `
    <div class="ctx-item" data-action="edit">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      <span>Edit</span>
      <span class="shortcut">Enter</span>
    </div>
    <div class="ctx-item" data-action="duplicate">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      <span>Duplicate</span>
      <span class="shortcut">Ctrl+D</span>
    </div>
    <div class="ctx-item" data-action="copy">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
      <span>Copy</span>
      <span class="shortcut">Ctrl+C</span>
    </div>
    <div class="ctx-item" data-action="paste">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
      <span>Paste</span>
      <span class="shortcut">Ctrl+V</span>
    </div>
    <div class="ctx-item" data-action="pasteStyle">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
      <span>Paste Style</span>
      <span class="shortcut">Ctrl+Shift+V</span>
    </div>
    <div class="ctx-separator"></div>
    <div class="ctx-item" data-action="resetStyle">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
      <span>Reset Style</span>
    </div>
    <div class="ctx-separator"></div>
    <div class="ctx-item" data-action="moveUp">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 15-6-6-6 6"/></svg>
      <span>Move Up</span>
      <span class="shortcut">Ctrl+Up</span>
    </div>
    <div class="ctx-item" data-action="moveDown">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
      <span>Move Down</span>
      <span class="shortcut">Ctrl+Down</span>
    </div>
    <div class="ctx-separator"></div>
    <div class="ctx-item" data-action="navigator">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
      <span>Navigator</span>
      <span class="shortcut">N</span>
    </div>
    <div class="ctx-item" data-action="selectParent">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>
      <span>Select Parent</span>
      <span class="shortcut">Esc</span>
    </div>
    <div class="ctx-separator"></div>
    <div class="ctx-item ctx-delete" data-action="delete">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
      <span>Delete</span>
      <span class="shortcut">Del</span>
    </div>
  `
}


function getMenuStyles(): string {
  return `
    .gjs-context-menu {
      position: fixed;
      z-index: 999999;
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 8px;
      padding: 4px 0;
      min-width: 200px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.5);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 13px;
      display: none;
    }
    .gjs-context-menu .ctx-item {
      display: flex;
      align-items: center;
      padding: 8px 12px;
      cursor: pointer;
      color: #ddd;
      gap: 10px;
    }
    .gjs-context-menu .ctx-item:hover {
      background: #333;
    }
    .gjs-context-menu .ctx-item svg {
      color: #888;
      flex-shrink: 0;
    }
    .gjs-context-menu .ctx-item span:first-of-type {
      flex: 1;
    }
    .gjs-context-menu .ctx-item .shortcut {
      color: #666;
      font-size: 11px;
    }
    .gjs-context-menu .ctx-separator {
      height: 1px;
      background: #333;
      margin: 4px 8px;
    }
    .gjs-context-menu .ctx-delete:hover {
      background: #4a1a1a;
      color: #ff6b6b;
    }
    .gjs-context-menu .ctx-delete:hover svg {
      color: #ff6b6b;
    }
  `
}

function executeAction(action: string): void {
  if (!currentEditor) return
  
  const editor = currentEditor
  const selected = editor.getSelected()
  
  switch (action) {
    case 'edit':
      editor.runCommand('open-tm')
      break
      
    case 'duplicate':
      if (selected) {
        // Use setTimeout to ensure the action runs after menu is fully closed
        setTimeout(() => {
          const comp = editor.getSelected()
          if (comp) {
            const parent = comp.parent()
            if (parent) {
              const idx = parent.components().indexOf(comp)
              const clone = comp.clone()
              parent.components().add(clone, { at: idx + 1 })
              editor.select(clone)
            }
          }
        }, 10)
      }
      break
      
    case 'copy':
      copyComponent(editor)
      break
      
    case 'paste':
      pasteComponent(editor)
      break
      
    case 'pasteStyle':
      pasteStyleComponent(editor)
      break
      
    case 'resetStyle':
      if (selected) {
        const selectedList = editor.getSelectedAll()
        selectedList.forEach(comp => {
          const id = comp.getId()
          if (originalStylesMap.has(id)) {
            comp.setStyle(originalStylesMap.get(id))
            originalStylesMap.delete(id)
          } else {
            comp.setStyle({})
          }
        })
      }
      break
      
    case 'moveUp':
      if (selected) {
        const parent = selected.parent()
        if (parent) {
          const idx = parent.components().indexOf(selected)
          if (idx > 0) {
            selected.move(parent, { at: idx - 1 })
            editor.select(selected)
          }
        }
      }
      break
      
    case 'moveDown':
      if (selected) {
        const parent = selected.parent()
        if (parent) {
          const comps = parent.components()
          const idx = comps.indexOf(selected)
          if (idx < comps.length - 1) {
            selected.move(parent, { at: idx + 2 })
            editor.select(selected)
          }
        }
      }
      break
      
    case 'navigator':
      // Open the Layers panel (Navigator/Structure view)
      // First close any open panels, then open layers
      editor.stopCommand('open-sm')
      editor.stopCommand('open-tm')
      editor.runCommand('open-layers')
      break
      
    case 'selectParent':
      if (selected) {
        const parent = selected.parent()
        if (parent && parent.get('type') !== 'wrapper') {
          editor.select(parent)
        }
      }
      break
      
    case 'delete':
      deleteComponent(editor)
      break
  }
}


function createMenu(): HTMLDivElement {
  // Remove any existing menu
  document.getElementById('gjs-ctx-menu')?.remove()
  
  // Add styles
  let styleEl = document.getElementById('gjs-ctx-menu-styles')
  if (!styleEl) {
    styleEl = document.createElement('style')
    styleEl.id = 'gjs-ctx-menu-styles'
    styleEl.textContent = getMenuStyles()
    document.head.appendChild(styleEl)
  }
  
  // Create menu
  const menu = document.createElement('div')
  menu.id = 'gjs-ctx-menu'
  menu.className = 'gjs-context-menu'
  menu.innerHTML = getMenuHTML()
  document.body.appendChild(menu)
  
  // Add click handlers
  menu.addEventListener('click', (e) => {
    const item = (e.target as HTMLElement).closest('.ctx-item') as HTMLElement
    if (item) {
      const action = item.dataset.action
      if (action) {
        hideMenu()
        executeAction(action)
      }
    }
  })
  
  return menu
}

function showMenu(x: number, y: number): void {
  if (!menuEl) return
  
  menuEl.style.display = 'block'
  
  // Position after display to get correct dimensions
  requestAnimationFrame(() => {
    if (!menuEl) return
    const rect = menuEl.getBoundingClientRect()
    const vw = window.innerWidth
    const vh = window.innerHeight
    
    let finalX = x
    let finalY = y
    
    if (x + rect.width > vw) finalX = vw - rect.width - 10
    if (y + rect.height > vh) finalY = vh - rect.height - 10
    if (finalX < 0) finalX = 10
    if (finalY < 0) finalY = 10
    
    menuEl.style.left = finalX + 'px'
    menuEl.style.top = finalY + 'px'
  })
}

function hideMenu(): void {
  if (menuEl) {
    menuEl.style.display = 'none'
  }
}

export function setupContextMenu(editor: Editor): () => void {
  currentEditor = editor
  menuEl = createMenu()
  
  // Store reference to handler for cleanup
  let iframeHandler: ((e: MouseEvent) => void) | null = null
  let iframeDoc: Document | null = null
  
  // Handle right-click in iframe
  const handleIframeContextMenu = (e: MouseEvent) => {
    e.preventDefault()
    e.stopPropagation()
    
    const canvas = editor.Canvas.getElement()
    const iframe = canvas?.querySelector('iframe') as HTMLIFrameElement
    if (!iframe) return false
    
    const frameRect = iframe.getBoundingClientRect()
    showMenu(e.clientX + frameRect.left, e.clientY + frameRect.top)
    
    return false
  }
  
  // Setup iframe listener
  const setupIframe = () => {
    const canvas = editor.Canvas.getElement()
    const iframe = canvas?.querySelector('iframe') as HTMLIFrameElement
    
    if (iframe?.contentDocument) {
      // Clean up old listener if exists
      if (iframeDoc && iframeHandler) {
        iframeDoc.removeEventListener('contextmenu', iframeHandler, true)
        iframeDoc.removeEventListener('mousedown', hideMenu, true)
      }
      
      iframeDoc = iframe.contentDocument
      iframeHandler = handleIframeContextMenu
      
      // Add new listeners
      iframeDoc.addEventListener('contextmenu', iframeHandler, true)
      iframeDoc.addEventListener('mousedown', hideMenu, true)
    }
  }
  
  // Hide on click outside
  const handleDocClick = (e: MouseEvent) => {
    if (menuEl && !menuEl.contains(e.target as Node)) {
      hideMenu()
    }
  }
  
  // Prevent default context menu on canvas area
  const handleDocContext = (e: MouseEvent) => {
    const target = e.target as HTMLElement
    if (target.closest('.gjs-cv-canvas')) {
      e.preventDefault()
    }
  }
  
  document.addEventListener('mousedown', handleDocClick, true)
  document.addEventListener('contextmenu', handleDocContext, true)
  
  // Setup on canvas load
  editor.on('canvas:frame:load', setupIframe)
  
  // Try setup immediately and with delays
  setupIframe()
  setTimeout(setupIframe, 300)
  setTimeout(setupIframe, 800)
  
  return () => {
    document.removeEventListener('mousedown', handleDocClick, true)
    document.removeEventListener('contextmenu', handleDocContext, true)
    if (iframeDoc && iframeHandler) {
      iframeDoc.removeEventListener('contextmenu', iframeHandler, true)
      iframeDoc.removeEventListener('mousedown', hideMenu, true)
    }
    menuEl?.remove()
    document.getElementById('gjs-ctx-menu-styles')?.remove()
    menuEl = null
    currentEditor = null
  }
}

// Exports for keyboard shortcuts
export function copyComponent(editor: Editor): void {
  const selected = editor.getSelectedAll()
  if (selected.length > 0) {
    if (selected.length === 1) {
      clipboardData = selected[0].clone()
      const styles = selected[0].getStyle()
      clipboardStyles = JSON.parse(JSON.stringify(styles))
    } else {
      // Support for multiple selection copying
      clipboardData = selected.map(comp => comp.clone())
      clipboardStyles = null // Styles only for single component copy/paste
    }
    console.log(`Copied ${selected.length} components`)
  }
}

export function pasteComponent(editor: Editor): void {
  if (clipboardData) {
    const selected = editor.getSelected()
    const parent = selected ? selected.parent() : editor.getWrapper()
    if (!parent) return

    const componentsToPaste = Array.isArray(clipboardData) ? clipboardData : [clipboardData]
    const pastedComponents: any[] = []
    
    componentsToPaste.forEach((comp, idx) => {
      const clone = comp.clone()
      if (selected && selected !== parent) {
        const parentComps = parent.components()
        const pos = parentComps.indexOf(selected)
        parentComps.add(clone, { at: pos + idx + 1 })
      } else {
        parent.components().add(clone)
      }
      pastedComponents.push(clone)
    })
    
    if (pastedComponents.length > 0) {
      editor.select(pastedComponents)
    }
  }
}

export function duplicateComponent(editor: Editor): void {
  const selected = editor.getSelectedAll()
  if (selected.length > 0) {
    const duplicatedComponents: any[] = []
    
    // Group by parent to maintain order and hierarchy
    selected.forEach(comp => {
      const parent = comp.parent()
      if (parent) {
        const idx = parent.components().indexOf(comp)
        const clone = comp.clone()
        parent.components().add(clone, { at: idx + 1 })
        duplicatedComponents.push(clone)
      }
    })
    
    if (duplicatedComponents.length > 0) {
      editor.select(duplicatedComponents)
    }
  }
}

export function deleteComponent(editor: Editor): void {
  const selected = editor.getSelectedAll()
  if (selected.length > 0) {
    selected.forEach(comp => {
      if (comp.get('type') !== 'wrapper') {
        comp.remove()
      }
    })
  }
}

export function pasteStyleComponent(editor: Editor): void {
  const selected = editor.getSelectedAll()
  if (selected.length > 0 && clipboardStyles) {
    selected.forEach(comp => {
      const id = comp.getId()
      if (!originalStylesMap.has(id)) {
        const currentStyles = comp.getStyle()
        originalStylesMap.set(id, JSON.parse(JSON.stringify(currentStyles)))
      }
      const stylesToApply = JSON.parse(JSON.stringify(clipboardStyles))
      comp.setStyle(stylesToApply)
    })
    console.log('Applied styles to', selected.length, 'components')
  }
}
