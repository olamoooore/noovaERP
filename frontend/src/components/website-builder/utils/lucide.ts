// Lucide Icons Initialization Utilities
import { Editor } from 'grapesjs'

const LUCIDE_CDN = 'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js'

/**
 * Initialize Lucide icons in the GrapesJS canvas
 */
export function initLucideIcons(editor: Editor) {
  const frame = editor.Canvas.getFrameEl()
  if (!frame || !frame.contentWindow || !frame.contentDocument) return

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
      script.src = LUCIDE_CDN
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

/**
 * Setup Lucide icon event listeners for the editor
 */
export function setupLucideListeners(editor: Editor) {
  const initIcons = () => initLucideIcons(editor)

  // Listen for component additions to reinitialize icons
  editor.on('component:add', () => {
    setTimeout(initIcons, 50)
    setTimeout(initIcons, 200)
    setTimeout(initIcons, 500)
  })

  // Listen for component updates
  editor.on('component:update', () => {
    setTimeout(initIcons, 50)
  })

  // Listen for preview mode toggle
  editor.on('run:preview', () => {
    setTimeout(initIcons, 100)
  })

  editor.on('stop:preview', () => {
    setTimeout(initIcons, 100)
  })

  // Also init on canvas load
  editor.on('canvas:frame:load', () => {
    setTimeout(initIcons, 100)
    setTimeout(initIcons, 300)
    setTimeout(initIcons, 600)
    setTimeout(initIcons, 1000)
  })

  // Also init when editor is ready
  editor.on('load', () => {
    setTimeout(initIcons, 200)
    setTimeout(initIcons, 500)
    injectLucideScriptToWrapper(editor)
  })

  // Periodic check for uninitialized icons (every 1 second)
  const iconCheckInterval = setInterval(() => {
    const frame = editor.Canvas.getFrameEl()
    if (frame && frame.contentDocument) {
      const uninitializedIcons = frame.contentDocument.querySelectorAll('i[data-lucide]')
      if (uninitializedIcons.length > 0) {
        initIcons()
      }
    }
  }, 1000)

  // Return cleanup function
  return () => {
    clearInterval(iconCheckInterval)
  }
}

/**
 * Inject Lucide script into the wrapper content so it persists in Preview/Export
 */
function injectLucideScriptToWrapper(editor: Editor) {
  const wrapper = editor.getWrapper()
  if (!wrapper) return

  // Check if script is already present to avoid duplicates
  const hasScript = wrapper.find('script').some((c: any) => {
    const attrs = c.getAttributes()
    return attrs && attrs.src === LUCIDE_CDN
  })

  if (!hasScript) {
    wrapper.append({
      tagName: 'script',
      attributes: { src: LUCIDE_CDN },
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
