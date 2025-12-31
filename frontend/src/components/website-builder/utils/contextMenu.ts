// Custom Context Menu for GrapesJS Canvas
import { Editor, Component } from 'grapesjs'

// Store for clipboard and original styles
let clipboard: Component | null = null
let clipboardStyles: Record<string, any> | null = null
const originalStyles = new Map<string, Record<string, any>>()

// Store editor reference for menu actions
let editorRef: Editor | null = null

interface ContextMenuItem {
  id: string
  label: string
  icon: string
  shortcut?: string
  separator?: boolean
  action: () => void
  disabled?: () => boolean
}

// Get menu items (functions that use current editor state)
function getMenuItems(): ContextMenuItem[] {
  const editor = editorRef
  if (!editor) return []

  return [
    {
      id: 'edit',
      label: 'Edit',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>`,
      shortcut: 'Enter',
      action: () => {
        editor.runCommand('open-tm')
      },
    },
    {
      id: 'duplicate',
      label: 'Duplicate',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
      shortcut: 'Ctrl+D',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          const parent = component.parent()
          if (parent) {
            const index = parent.components().indexOf(component)
            const clone = component.clone()
            parent.components().add(clone, { at: index + 1 })
            editor.select(clone)
          }
        }
      },
    },
    {
      id: 'copy',
      label: 'Copy',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
      shortcut: 'Ctrl+C',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          clipboard = component.clone()
          clipboardStyles = component.getStyle()
        }
      },
    },
    {
      id: 'paste',
      label: 'Paste',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>`,
      shortcut: 'Ctrl+V',
      action: () => {
        if (clipboard) {
          const selected = editor.getSelected()
          const clone = clipboard.clone()
          if (selected) {
            const parent = selected.parent()
            if (parent) {
              const index = parent.components().indexOf(selected)
              parent.components().add(clone, { at: index + 1 })
            }
          } else {
            const wrapper = editor.getWrapper()
            wrapper?.components().add(clone)
          }
          editor.select(clone)
        }
      },
      disabled: () => !clipboard,
    },
    {
      id: 'paste-style',
      label: 'Paste Style',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>`,
      shortcut: 'Ctrl+Shift+V',
      action: () => {
        const component = editor.getSelected()
        if (component && clipboardStyles) {
          const componentId = component.getId()
          if (!originalStyles.has(componentId)) {
            originalStyles.set(componentId, { ...component.getStyle() })
          }
          component.setStyle(clipboardStyles)
        }
      },
      disabled: () => !clipboardStyles,
      separator: true,
    },
    {
      id: 'reset-style',
      label: 'Reset Style',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>`,
      action: () => {
        const component = editor.getSelected()
        if (component) {
          const componentId = component.getId()
          if (originalStyles.has(componentId)) {
            component.setStyle(originalStyles.get(componentId)!)
            originalStyles.delete(componentId)
          } else {
            component.setStyle({})
          }
        }
      },
      separator: true,
    },
    {
      id: 'move-up',
      label: 'Move Up',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>`,
      shortcut: 'Ctrl+↑',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          const parent = component.parent()
          if (parent) {
            const index = parent.components().indexOf(component)
            if (index > 0) {
              component.move(parent, { at: index - 1 })
              editor.select(component)
            }
          }
        }
      },
    },
    {
      id: 'move-down',
      label: 'Move Down',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>`,
      shortcut: 'Ctrl+↓',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          const parent = component.parent()
          if (parent) {
            const components = parent.components()
            const index = components.indexOf(component)
            if (index < components.length - 1) {
              component.move(parent, { at: index + 2 })
              editor.select(component)
            }
          }
        }
      },
      separator: true,
    },
    {
      id: 'navigator',
      label: 'Navigator',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>`,
      shortcut: 'N',
      action: () => {
        editor.runCommand('core:open-layers')
      },
    },
    {
      id: 'select-parent',
      label: 'Select Parent',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>`,
      shortcut: 'Esc',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          const parent = component.parent()
          if (parent && parent.get('type') !== 'wrapper') {
            editor.select(parent)
          }
        }
      },
      separator: true,
    },
    {
      id: 'delete',
      label: 'Delete',
      icon: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>`,
      shortcut: 'Del',
      action: () => {
        const component = editor.getSelected()
        if (component) {
          component.remove()
        }
      },
    },
  ]
}

// Context menu element reference
let menuElement: HTMLDivElement | null = null
let iframeListenerAdded = false

// Create context menu element
function createContextMenuElement(): HTMLDivElement {
  // Remove existing menu if any
  const existing = document.getElementById('gjs-custom-context-menu')
  if (existing) existing.remove()

  const menu = document.createElement('div')
  menu.id = 'gjs-custom-context-menu'
  menu.style.cssText = `
    position: fixed;
    z-index: 100000;
    background: #1e1e1e;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    padding: 6px 0;
    min-width: 200px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    display: none;
    backdrop-filter: blur(10px);
  `
  document.body.appendChild(menu)
  return menu
}

// Render menu items
function renderMenuItems(menu: HTMLDivElement): void {
  menu.innerHTML = ''
  const items = getMenuItems()

  items.forEach((item) => {
    if (item.separator) {
      const separator = document.createElement('div')
      separator.style.cssText = `
        height: 1px;
        background: #3a3a3a;
        margin: 6px 8px;
      `
      menu.appendChild(separator)
    }

    const isDisabled = item.disabled?.() ?? false
    const menuItem = document.createElement('div')
    menuItem.className = 'context-menu-item'
    menuItem.dataset.action = item.id
    menuItem.style.cssText = `
      display: flex;
      align-items: center;
      padding: 8px 12px;
      cursor: ${isDisabled ? 'not-allowed' : 'pointer'};
      color: ${isDisabled ? '#666' : '#e0e0e0'};
      transition: background 0.15s;
      gap: 10px;
      user-select: none;
    `

    menuItem.innerHTML = `
      <span style="display: flex; align-items: center; color: ${isDisabled ? '#555' : '#888'};">${item.icon}</span>
      <span style="flex: 1;">${item.label}</span>
      ${item.shortcut ? `<span style="color: #666; font-size: 11px; margin-left: auto;">${item.shortcut}</span>` : ''}
    `

    if (!isDisabled) {
      menuItem.onmouseenter = () => {
        menuItem.style.background = '#2a2a2a'
      }
      menuItem.onmouseleave = () => {
        menuItem.style.background = 'transparent'
      }
      menuItem.onclick = (e) => {
        e.preventDefault()
        e.stopPropagation()
        hideContextMenu()
        // Execute action after menu is hidden
        setTimeout(() => item.action(), 0)
      }
    }

    menu.appendChild(menuItem)
  })
}

// Show context menu
function showContextMenu(x: number, y: number): void {
  if (!menuElement) return

  renderMenuItems(menuElement)
  menuElement.style.display = 'block'

  // Need to get dimensions after display
  requestAnimationFrame(() => {
    if (!menuElement) return
    
    const rect = menuElement.getBoundingClientRect()
    const viewportWidth = window.innerWidth
    const viewportHeight = window.innerHeight

    let finalX = x
    let finalY = y

    if (x + rect.width > viewportWidth) {
      finalX = viewportWidth - rect.width - 10
    }
    if (y + rect.height > viewportHeight) {
      finalY = viewportHeight - rect.height - 10
    }

    menuElement.style.left = `${finalX}px`
    menuElement.style.top = `${finalY}px`
  })
}

// Hide context menu
function hideContextMenu(): void {
  if (menuElement) {
    menuElement.style.display = 'none'
  }
}

// Setup context menu for editor
export function setupContextMenu(editor: Editor): () => void {
  editorRef = editor
  menuElement = createContextMenuElement()

  // Handle context menu inside iframe
  const setupIframeContextMenu = () => {
    if (iframeListenerAdded) return
    
    const canvas = editor.Canvas.getElement()
    const canvasFrame = canvas?.querySelector('iframe') as HTMLIFrameElement | null
    
    if (canvasFrame && canvasFrame.contentDocument) {
      const iframeDoc = canvasFrame.contentDocument

      iframeDoc.addEventListener('contextmenu', (e: MouseEvent) => {
        e.preventDefault()
        e.stopPropagation()

        // Get position relative to main window
        const frameRect = canvasFrame.getBoundingClientRect()
        const x = e.clientX + frameRect.left
        const y = e.clientY + frameRect.top

        showContextMenu(x, y)
        return false
      }, true)

      // Also hide menu when clicking inside iframe
      iframeDoc.addEventListener('click', () => {
        hideContextMenu()
      }, true)

      iframeListenerAdded = true
    }
  }

  // Hide menu on click outside (in main document)
  const handleClickOutside = (e: MouseEvent) => {
    if (menuElement && !menuElement.contains(e.target as Node)) {
      hideContextMenu()
    }
  }

  // Hide menu on scroll
  const handleScroll = () => {
    hideContextMenu()
  }

  // Prevent default context menu on main document near canvas
  const handleMainContextMenu = (e: MouseEvent) => {
    const target = e.target as HTMLElement
    // If clicking on the canvas wrapper area, prevent default
    if (target.closest('.gjs-cv-canvas') || target.closest('.gjs-frame-wrapper')) {
      e.preventDefault()
    }
  }

  // Setup listeners
  document.addEventListener('click', handleClickOutside, true)
  document.addEventListener('scroll', handleScroll, true)
  document.addEventListener('contextmenu', handleMainContextMenu, true)

  // Setup iframe context menu after canvas loads
  editor.on('canvas:frame:load', () => {
    iframeListenerAdded = false
    setupIframeContextMenu()
  })
  
  // Try immediately and with delays for initial load
  setTimeout(setupIframeContextMenu, 100)
  setTimeout(setupIframeContextMenu, 500)
  setTimeout(setupIframeContextMenu, 1000)

  // Cleanup function
  return () => {
    document.removeEventListener('click', handleClickOutside, true)
    document.removeEventListener('scroll', handleScroll, true)
    document.removeEventListener('contextmenu', handleMainContextMenu, true)
    if (menuElement) {
      menuElement.remove()
      menuElement = null
    }
    editorRef = null
    iframeListenerAdded = false
  }
}

// Export clipboard functions for keyboard shortcuts
export function copyComponent(editor: Editor): void {
  const component = editor.getSelected()
  if (component) {
    clipboard = component.clone()
    clipboardStyles = component.getStyle()
  }
}

export function pasteComponent(editor: Editor): void {
  if (clipboard) {
    const selected = editor.getSelected()
    const clone = clipboard.clone()
    
    if (selected) {
      const parent = selected.parent()
      if (parent) {
        const index = parent.components().indexOf(selected)
        parent.components().add(clone, { at: index + 1 })
      }
    } else {
      const wrapper = editor.getWrapper()
      wrapper?.components().add(clone)
    }
    editor.select(clone)
  }
}

export function duplicateComponent(editor: Editor): void {
  const component = editor.getSelected()
  if (component) {
    const parent = component.parent()
    if (parent) {
      const index = parent.components().indexOf(component)
      const clone = component.clone()
      parent.components().add(clone, { at: index + 1 })
      editor.select(clone)
    }
  }
}

export function deleteComponent(editor: Editor): void {
  const component = editor.getSelected()
  if (component) {
    component.remove()
  }
}
