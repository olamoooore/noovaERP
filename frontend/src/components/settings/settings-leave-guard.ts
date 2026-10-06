import { createContext, useCallback, useEffect, useRef, useState } from 'react'

/**
 * Unsaved-changes tracking for settings pages that swap one section component
 * at a time (#546). Every section already renders a SettingsSaveBar with its
 * own `hasChanges`; the save bar reports that flag up through this context, so
 * the page can mark the section dirty and confirm before a switch unmounts it.
 * Pages without a provider are unaffected.
 */
export const SettingsDirtyContext = createContext<((dirty: boolean) => void) | null>(null)

export function useSettingsLeaveGuard() {
  const [dirty, setDirty] = useState(false)
  const pendingAction = useRef<(() => void) | null>(null)
  const [confirmOpen, setConfirmOpen] = useState(false)

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
  const guard = useCallback(
    (action: () => void) => {
      if (!dirty) {
        action()
        return
      }
      pendingAction.current = action
      setConfirmOpen(true)
    },
    [dirty],
  )

  const confirmDiscard = useCallback(() => {
    const action = pendingAction.current
    pendingAction.current = null
    setConfirmOpen(false)
    setDirty(false)
    action?.()
  }, [])

  const keepEditing = useCallback(() => {
    pendingAction.current = null
    setConfirmOpen(false)
  }, [])

  return { dirty, reportDirty: setDirty, guard, confirmOpen, confirmDiscard, keepEditing }
}
