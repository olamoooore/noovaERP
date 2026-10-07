import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import type { DocumentDraftGuard } from '@/hooks/useDocumentDraftGuard'

/**
 * Issue #317: the visible half of useDocumentDraftGuard — the "restore your
 * draft?" banner and the leave confirmation. Render both once per form.
 */
export function DocumentDraftBanner({ guard, label }: { guard: DocumentDraftGuard; label: string }) {
  if (!guard.draft.pending) return null
  const savedAt = guard.draft.savedAt ? new Date(guard.draft.savedAt) : null
  const when = savedAt && !Number.isNaN(savedAt.getTime()) ? savedAt.toLocaleString() : null
  return (
    <div
      role="status"
      data-testid="document-draft-banner"
      className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
    >
      <div className="flex items-center gap-2">
        <Icon icon="solar:history-bold-duotone" className="h-5 w-5 shrink-0" />
        <span>
          You have an unsaved {label} draft{when ? ` from ${when}` : ''} on this device.
        </span>
      </div>
      <div className="flex gap-2">
        <Button type="button" size="sm" variant="outline" onClick={guard.draft.discard}>
          Discard draft
        </Button>
        <Button type="button" size="sm" onClick={guard.draft.restore}>
          Restore draft
        </Button>
      </div>
    </div>
  )
}

export function DocumentLeaveDialog({ guard }: { guard: DocumentDraftGuard }) {
  return (
    <ConfirmDialog
      open={guard.leaveDialog.open}
      onOpenChange={(open) => {
        if (!open) guard.leaveDialog.onKeepEditing()
      }}
      title="Leave with unsaved changes?"
      description={
        guard.leaveDialog.persisted
          ? 'This document has changes that are not saved yet. A draft is kept on this device and offered again when you come back.'
          : 'This document has changes that are not saved yet. Leaving now discards them.'
      }
      confirmLabel="Leave page"
      cancelLabel="Keep editing"
      tone="warning"
      onConfirm={guard.leaveDialog.onConfirm}
    />
  )
}
