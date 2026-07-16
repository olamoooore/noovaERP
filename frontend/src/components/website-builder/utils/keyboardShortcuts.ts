import { Editor } from 'grapesjs'
import { 
  copyComponent, 
  pasteComponent, 
  duplicateComponent, 
  deleteComponent, 
  pasteStyleComponent 
} from './contextMenu'

// Actions are now imported from contextMenu.ts to ensure shared state

// Check if target is an input field or in text editing mode
function isInputField(target: HTMLElement): boolean {
  const tagName = target.tagName?.toUpperCase()
  
  // Standard input elements
  if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
    return true
  }
  
  // Contenteditable elements (RTE mode)
  if (target.isContentEditable) {
    return true
  }
  
  // Code editors (Monaco / CodeMirror) and any open GrapesJS modal (e.g. the
  // custom-code CodeMirror modal). Without this, Backspace/Delete/arrow keys
  // typed inside a code editor get hijacked by the canvas shortcuts below and
  // never reach the editor — which makes the editor appear "append-only".
  if (
    target.closest('.CodeMirror') ||        // CodeMirror 5
    target.closest('.cm-editor') ||         // CodeMirror 6
    target.closest('.monaco-editor') ||     // Monaco (page-level HTML/CSS/JS editor)
    target.closest('.gjs-mdl-dialog') ||    // any GrapesJS modal dialog
    target.closest('.gjs-mdl-dialog-wrp') ||
    target.closest('[role="textbox"]')
  ) {
    return true
  }
  
  // Check if inside RTE toolbar or contenteditable
  if (target.closest('.gjs-rte-toolbar') || target.closest('[contenteditable="true"]')) {
    return true
  }
  
  // Check if inside GrapesJS RTE active element
  if (target.closest('.gjs-rte-active')) {
    return true
  }
  
  // Check if the target or any parent has contenteditable attribute
  let el: HTMLElement | null = target
  while (el) {
    if (el.getAttribute?.('contenteditable') === 'true') {
      return true
    }
    el = el.parentElement
  }
  
  return false
}

// Check if RTE (Rich Text Editor) is currently active
function isRTEActive(editor: Editor): boolean {
  // Check if any selected component is in editing mode (GrapesJS native)
  const selected = editor.getSelected()
  if (selected && selected.get('status') === 'editing') {
    return true
  }

  // Check if RTE is enabled on any component
  const rte = editor.RichTextEditor
  if (rte && typeof rte.isActive === 'function' && rte.isActive()) {
    return true
  }
  
  // Also check for active contenteditable in the canvas
  const canvas = editor.Canvas.getElement()
  const canvasFrame = canvas?.querySelector('iframe') as HTMLIFrameElement | null
  if (canvasFrame?.contentDocument) {
    const activeElement = canvasFrame.contentDocument.activeElement
    if (activeElement?.getAttribute?.('contenteditable') === 'true' || (activeElement as any)?.isContentEditable) {
      return true
    }
    // Check if any element has gjs-rte-active class
    if (canvasFrame.contentDocument.querySelector('.gjs-rte-active')) {
      return true
    }
  }
  
  return false
}

// Setup keyboard shortcuts for the editor
export function setupKeyboardShortcuts(editor: Editor): () => void {
  let isHandling = false

  const handleKeyDown = (e: KeyboardEvent) => {
    // Prevent re-entry
    if (isHandling) return
    
    const target = e.target as HTMLElement
    const key = e.key.toLowerCase()
    const ctrlOrCmd = e.ctrlKey || e.metaKey

    // Always allow save, even in input fields
    if (ctrlOrCmd && key === 's') {
      e.preventDefault()
      e.stopPropagation()
      e.stopImmediatePropagation()
      editor.runCommand('save-project')
      return
    }

    // Skip other shortcuts if in input field or RTE is active
    if (isInputField(target) || isRTEActive(editor)) return

    isHandling = true

    try {
      // Ctrl/Cmd + Z = Undo
      if (ctrlOrCmd && key === 'z' && !e.shiftKey) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        editor.UndoManager.undo()
        return
      }

      // Ctrl/Cmd + Shift + Z or Ctrl/Cmd + Y = Redo
      if ((ctrlOrCmd && key === 'z' && e.shiftKey) || (ctrlOrCmd && key === 'y')) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        editor.UndoManager.redo()
        return
      }

      // Ctrl/Cmd + C = Copy
      if (ctrlOrCmd && key === 'c') {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        copyComponent(editor)
        return
      }

      // Ctrl/Cmd + Shift + V = Paste Style
      if (ctrlOrCmd && key === 'v' && e.shiftKey) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        pasteStyleComponent(editor)
        return
      }

      // Ctrl/Cmd + V = Paste
      if (ctrlOrCmd && key === 'v' && !e.shiftKey) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        pasteComponent(editor)
        return
      }

      // Ctrl/Cmd + D = Duplicate
      if (ctrlOrCmd && key === 'd') {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        duplicateComponent(editor)
        return
      }

      // Delete or Backspace = Delete
      if (key === 'delete' || key === 'backspace') {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        deleteComponent(editor)
        return
      }

      // Escape = Select parent or deselect
      if (key === 'escape') {
        e.preventDefault()
        e.stopPropagation()
        const selected = editor.getSelected()
        if (selected) {
          const parent = selected.parent()
          if (parent && parent.get('type') !== 'wrapper') {
            editor.select(parent)
          } else {
            editor.select()
          }
        }
        return
      }

      // Ctrl/Cmd + Arrow Up = Move up
      if (ctrlOrCmd && key === 'arrowup') {
        e.preventDefault()
        e.stopPropagation()
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
        return
      }

      // Ctrl/Cmd + Arrow Down = Move down
      if (ctrlOrCmd && key === 'arrowdown') {
        e.preventDefault()
        e.stopPropagation()
        const component = editor.getSelected()
        if (component) {
          const parent = component.parent()
          if (parent) {
            const components = parent.components()
            const index = components.indexOf(component)
            if (index < components.length - 1) {
              // GrapesJS removes the component before re-inserting at `at`,
              // so the correct target index is index + 1 (one position down).
              component.move(parent, { at: index + 1 })
              editor.select(component)
            }
          }
        }
        return
      }

      // N = Navigator (open layers panel)
      if (key === 'n' && !ctrlOrCmd) {
        e.preventDefault()
        e.stopPropagation()
        editor.stopCommand('open-sm')
        editor.stopCommand('open-tm')
        editor.runCommand('open-layers')
        return
      }

      // Ctrl/Cmd + P = Preview
      if (ctrlOrCmd && key === 'p') {
        e.preventDefault()
        e.stopPropagation()
        editor.runCommand('preview')
        return
      }

    } finally {
      isHandling = false
    }
  }

  // Add listener at capture phase to intercept before GrapesJS
  document.addEventListener('keydown', handleKeyDown, true)

  // Setup iframe shortcuts
  const setupIframeShortcuts = () => {
    // Editor may have been destroyed (e.g. React StrictMode remount / unmount)
    // by the time this delayed callback runs.
    if (!editor?.Canvas?.getElement) return
    const canvas = editor.Canvas.getElement()
    const canvasFrame = canvas?.querySelector('iframe') as HTMLIFrameElement | null
    
    if (canvasFrame?.contentDocument) {
      canvasFrame.contentDocument.removeEventListener('keydown', handleKeyDown, true)
      canvasFrame.contentDocument.addEventListener('keydown', handleKeyDown, true)
    }
  }

  // Setup after canvas loads
  editor.on('canvas:frame:load', setupIframeShortcuts)
  setTimeout(setupIframeShortcuts, 500)

  // Cleanup
  return () => {
    document.removeEventListener('keydown', handleKeyDown, true)
    if (!editor?.Canvas?.getElement) return
    const canvas = editor.Canvas.getElement()
    const canvasFrame = canvas?.querySelector('iframe') as HTMLIFrameElement | null
    if (canvasFrame?.contentDocument) {
      canvasFrame.contentDocument.removeEventListener('keydown', handleKeyDown, true)
    }
  }
}
