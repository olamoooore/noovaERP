// Keyboard Shortcuts for GrapesJS Editor
import { Editor } from 'grapesjs'

// Local clipboard for keyboard shortcuts
let localClipboard: any = null
let localClipboardStyles: any = null

// Copy component
function copySelected(editor: Editor): void {
  const selected = editor.getSelected()
  if (selected) {
    localClipboard = selected.clone()
    const styles = selected.getStyle()
    localClipboardStyles = JSON.parse(JSON.stringify(styles))
  }
}

// Paste component
function pasteSelected(editor: Editor): void {
  if (localClipboard) {
    const selected = editor.getSelected()
    const clone = localClipboard.clone()
    if (selected) {
      const parent = selected.parent()
      if (parent) {
        const idx = parent.components().indexOf(selected)
        parent.components().add(clone, { at: idx + 1 })
      }
    } else {
      editor.getWrapper()?.components().add(clone)
    }
    editor.select(clone)
  }
}

// Paste style
function pasteStyleSelected(editor: Editor): void {
  const selected = editor.getSelected()
  if (selected && localClipboardStyles) {
    const stylesToApply = JSON.parse(JSON.stringify(localClipboardStyles))
    selected.setStyle(stylesToApply)
    editor.refresh()
  }
}

// Duplicate component
function duplicateSelected(editor: Editor): void {
  const selected = editor.getSelected()
  if (selected) {
    const parent = selected.parent()
    if (parent) {
      const idx = parent.components().indexOf(selected)
      const clone = selected.clone()
      parent.components().add(clone, { at: idx + 1 })
      editor.select(clone)
    }
  }
}

// Delete component
function deleteSelected(editor: Editor): void {
  const selected = editor.getSelected()
  if (selected) {
    selected.remove()
  }
}

// Check if target is an input field
function isInputField(target: HTMLElement): boolean {
  const tagName = target.tagName?.toUpperCase()
  return tagName === 'INPUT' || 
         tagName === 'TEXTAREA' || 
         target.isContentEditable ||
         !!target.closest('.gjs-rte-toolbar') ||
         !!target.closest('[contenteditable="true"]')
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

    // Skip other shortcuts if in input field
    if (isInputField(target)) return

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
        copySelected(editor)
        return
      }

      // Ctrl/Cmd + Shift + V = Paste Style
      if (ctrlOrCmd && key === 'v' && e.shiftKey) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        pasteStyleSelected(editor)
        return
      }

      // Ctrl/Cmd + V = Paste
      if (ctrlOrCmd && key === 'v' && !e.shiftKey) {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        pasteSelected(editor)
        return
      }

      // Ctrl/Cmd + D = Duplicate
      if (ctrlOrCmd && key === 'd') {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        duplicateSelected(editor)
        return
      }

      // Delete or Backspace = Delete
      if (key === 'delete' || key === 'backspace') {
        e.preventDefault()
        e.stopPropagation()
        e.stopImmediatePropagation()
        deleteSelected(editor)
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
              component.move(parent, { at: index + 2 })
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
    const canvas = editor.Canvas.getElement()
    const canvasFrame = canvas?.querySelector('iframe') as HTMLIFrameElement | null
    if (canvasFrame?.contentDocument) {
      canvasFrame.contentDocument.removeEventListener('keydown', handleKeyDown, true)
    }
  }
}
