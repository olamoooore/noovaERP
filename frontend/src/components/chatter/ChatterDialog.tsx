import React from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FullscreenDialogContent } from '@/components/ui/dialog-fullscreen'
import { ChatterPanel } from './ChatterPanel'

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  subjectType: string
  subjectId: number | string
  documentLabel?: string
  readOnly?: boolean
}

/**
 * Reusable Chatter dialog — wraps the ChatterPanel inside a fullscreen-capable
 * Dialog. Use this on any document detail page to enable Odoo-style chatter.
 */
export function ChatterDialog({
  open,
  onOpenChange,
  subjectType,
  subjectId,
  documentLabel,
  readOnly,
}: Props) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <FullscreenDialogContent className="max-w-3xl h-[80vh] flex flex-col p-0">
        <DialogHeader className="px-4 py-3 border-b">
          <DialogTitle className="flex items-center gap-2">
            <span>Chatter</span>
            {documentLabel && <span className="text-muted-foreground text-sm font-normal">— {documentLabel}</span>}
          </DialogTitle>
          <DialogDescription className="sr-only">
            Activity log, messages, scheduled activities and attachments for this document.
          </DialogDescription>
        </DialogHeader>
        <div className="flex-1 overflow-hidden px-4 pb-4">
          <ChatterPanel
            subjectType={subjectType}
            subjectId={subjectId}
            documentLabel={documentLabel}
            readOnly={readOnly}
            className="h-full"
          />
        </div>
      </FullscreenDialogContent>
    </Dialog>
  )
}
