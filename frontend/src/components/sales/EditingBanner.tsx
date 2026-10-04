import { Icon } from '@iconify/react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import type { ActiveEditor } from '@/lib/recordConflict'

interface EditingBannerProps {
  /** The active soft lock from the document GET (`active_lock`), if any. */
  activeEditor?: ActiveEditor | null
  /** The signed-in user's id — no banner when they are the active editor. */
  currentUserId?: number | null
}

/**
 * Issue #906 (SALES-PLAT / UX2): "Someone else is editing" banner.
 *
 * The backend soft lock records the last user who saved the draft within its
 * TTL (#896). When that is not the signed-in user, warn before they invest
 * more edits that will hit a 409 on save.
 */
export function EditingBanner({ activeEditor, currentUserId }: EditingBannerProps) {
  if (!activeEditor) return null
  if (currentUserId != null && Number(activeEditor.user_id) === Number(currentUserId)) {
    return null
  }

  const name = activeEditor.user_name?.trim() || 'Another user'

  return (
    <Alert variant="warning" data-testid="editing-banner" className="mb-6">
      <Icon icon="solar:user-clock-bold-duotone" className="h-4 w-4" />
      <AlertTitle>Someone else is editing</AlertTitle>
      <AlertDescription>
        {name} is editing this document. Your save may raise a conflict you can resolve.
      </AlertDescription>
    </Alert>
  )
}
