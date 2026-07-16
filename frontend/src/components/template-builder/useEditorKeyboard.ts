import { useEffect } from 'react'
import { useTemplateEditorStore } from '@/store/templateEditorStore'

/**
 * ADR-004 §12 — keyboard operation for the studio.
 *
 *   Ctrl/Cmd+Z   undo
 *   Ctrl/Cmd+Shift+Z / Ctrl+Y   redo
 *   Ctrl/Cmd+C   copy selected block
 *   Ctrl/Cmd+V   paste at root
 *   Ctrl/Cmd+D   duplicate selected block
 *   Delete       remove selected block
 *
 * Ignores keystrokes originating from form fields (input/textarea/select).
 */
export function useEditorKeyboard(): void {
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null
      if (target) {
        const tag = target.tagName
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
          return
        }
      }

      const s = useTemplateEditorStore.getState()
      if (!s.template) return
      const mod = e.metaKey || e.ctrlKey
      const id = s.selectedBlockId

      if (mod && e.key.toLowerCase() === 'z') {
        e.preventDefault()
        if (e.shiftKey) s.redo()
        else s.undo()
        return
      }
      if (mod && e.key.toLowerCase() === 'y') {
        e.preventDefault()
        s.redo()
        return
      }
      if (mod && e.key.toLowerCase() === 'c' && id) {
        e.preventDefault()
        s.copyBlock(id)
        return
      }
      if (mod && e.key.toLowerCase() === 'v') {
        e.preventDefault()
        s.pasteBlock()
        return
      }
      if (mod && e.key.toLowerCase() === 'd' && id) {
        e.preventDefault()
        s.duplicateBlock(id)
        return
      }
      if ((e.key === 'Delete' || e.key === 'Backspace') && id) {
        e.preventDefault()
        s.removeBlock(id)
      }
    }

    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])
}
