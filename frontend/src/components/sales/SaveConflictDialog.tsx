import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  copyableConflictText,
  copyTextToClipboard,
  diffConflictFields,
  type SaveConflict,
} from '@/lib/recordConflict'

interface SaveConflictDialogProps {
  open: boolean
  conflict: SaveConflict | null
  /** Render the overwrite action only when the user may force the save. */
  canOverwrite?: boolean
  onOpenChange: (open: boolean) => void
  onReloadTheirs: () => void
  onOverwrite: () => void
}

function renderValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value, null, 1)
    } catch {
      return String(value)
    }
  }
  return String(value)
}

/**
 * Issue #906 (SALES-PLAT / UX2): the 409 save-conflict dialog.
 *
 * Shows the server's current values ("theirs") next to the attempted payload
 * ("mine") so the user can choose: reload the server version, overwrite it
 * when their role permits, or copy their own lines away before reloading.
 */
export function SaveConflictDialog({
  open,
  conflict,
  canOverwrite = true,
  onOpenChange,
  onReloadTheirs,
  onOverwrite,
}: SaveConflictDialogProps) {
  const [copied, setCopied] = useState(false)

  useEffect(() => {
    if (!open) setCopied(false)
  }, [open])

  const editorName = conflict?.activeLock?.user_name?.trim()
  const diffs = diffConflictFields(conflict?.theirs ?? null, conflict?.mine ?? null)

  const handleCopy = async () => {
    const copiedOk = await copyTextToClipboard(copyableConflictText(conflict))
    setCopied(copiedOk)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl" data-testid="save-conflict-dialog">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 text-amber-500" />
            Save conflict — this document changed
          </DialogTitle>
          <DialogDescription>
            {editorName
              ? `${editorName} saved this document after you loaded it.`
              : conflict?.message ?? 'This document was changed by another user.'}
            {' '}Compare their values with your changes, then choose what to keep.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[50vh] overflow-auto rounded-md border" data-testid="conflict-compare">
          <div className="grid grid-cols-2 gap-px border-b bg-muted/60 text-xs font-medium">
            <div className="px-3 py-2">Their version</div>
            <div className="px-3 py-2">Your changes</div>
          </div>

          {diffs.length === 0 ? (
            <p className="px-3 py-4 text-sm text-muted-foreground">
              No field-level differences — the other user changed something outside the fields you edited.
            </p>
          ) : (
            diffs.map((diff) => (
              <div key={diff.key} className="grid grid-cols-2 gap-px border-b last:border-b-0">
                <div className="px-3 py-2">
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {diff.label}
                  </p>
                  <pre className="whitespace-pre-wrap break-words font-mono text-xs">{renderValue(diff.theirs)}</pre>
                </div>
                <div className="bg-amber-50/60 px-3 py-2 dark:bg-amber-950/20">
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {diff.label}
                  </p>
                  <pre className="whitespace-pre-wrap break-words font-mono text-xs">{renderValue(diff.mine)}</pre>
                </div>
              </div>
            ))
          )}
        </div>

        <DialogFooter className="flex-col gap-2 sm:flex-row sm:justify-between">
          <Button type="button" variant="outline" onClick={onReloadTheirs}>
            <Icon icon="solar:refresh-bold-duotone" className="mr-2 h-4 w-4" />
            Reload theirs
          </Button>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Button type="button" variant="secondary" onClick={handleCopy}>
              <Icon icon="solar:copy-bold-duotone" className="mr-2 h-4 w-4" />
              {copied ? 'Copied' : 'Copy my lines'}
            </Button>
            {canOverwrite && (
              <Button type="button" variant="destructive" onClick={onOverwrite}>
                <Icon icon="solar:danger-bold-duotone" className="mr-2 h-4 w-4" />
                Overwrite theirs with mine
              </Button>
            )}
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
