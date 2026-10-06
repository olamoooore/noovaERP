import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Generic unsaved-changes guard (#546, generalised for #317).
 *
 * While `dirty` is true the browser's own "leave site?" prompt is armed for
 * reloads / tab closes, and `guard(action)` defers in-app actions (switching a
 * section, Cancel, Back, navigating away) until the user confirms discarding.
 * Router-free on purpose so pages and tests without a router can use it; link
 * interception for document forms lives in useDocumentDraftGuard.
 */
export function useLeaveGuard(dirty: boolean, options: { onDiscard?: () => void } = {}) {
  const pendingAction = useRef<(() => void) | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const dirtyRef = useRef(dirty)
  dirtyRef.current = dirty
  const onDiscardRef = useRef(options.onDiscard)
  onDiscardRef.current = options.onDiscard

  useEffect(() => {
    if (!dirty) return
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', handleBeforeUnload)
    return () => window.removeEventListener('beforeunload', handleBeforeUnload)
  }, [dirty])

  /** Run `action` now, or after the user confirms discarding unsaved changes. */
  const guard = useCallback((action: () => void) => {
    if (!dirtyRef.current) {
      action()
      return
    }
    pendingAction.current = action
    setConfirmOpen(true)
  }, [])

  const confirmDiscard = useCallback(() => {
    const action = pendingAction.current
    pendingAction.current = null
    setConfirmOpen(false)
    onDiscardRef.current?.()
    action?.()
  }, [])

  const keepEditing = useCallback(() => {
    pendingAction.current = null
    setConfirmOpen(false)
  }, [])

  return { guard, confirmOpen, confirmDiscard, keepEditing }
}
