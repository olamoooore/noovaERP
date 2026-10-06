import { createContext, useState } from 'react'
import { useLeaveGuard } from '@/hooks/useLeaveGuard'

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
  // #317: the beforeunload + confirm-before-action mechanics are shared with
  // the sales document forms (useLeaveGuard).
  const { guard, confirmOpen, confirmDiscard, keepEditing } = useLeaveGuard(dirty, {
    onDiscard: () => setDirty(false),
  })

  return { dirty, reportDirty: setDirty, guard, confirmOpen, confirmDiscard, keepEditing }
}
