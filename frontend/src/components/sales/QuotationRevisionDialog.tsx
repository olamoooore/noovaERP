import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { QUOTATION_REJECT_REASONS, QUOTATION_REVISION_REASON } from './quotationRevisionReasons'

/**
 * Issue #336 (Q12): both actions call `POST /quotations/{id}/reject`
 * (#178/#179), which voids this version and opens an auto-versioned
 * replacement draft (v+1) in the same correction group. "Reject" records why
 * the customer turned the quote down; "New revision" records a seller-driven
 * change under the fixed `revision` reason code.
 */
export type QuotationRevisionMode = 'reject' | 'revision'

const COPY: Record<QuotationRevisionMode, { title: string; description: string; confirm: string; icon: string }> = {
  reject: {
    title: 'Reject quotation',
    description:
      'Records the customer’s rejection. This version is cancelled and kept in the history, and a new draft version is opened for you to revise and re-send.',
    confirm: 'Reject quotation',
    icon: 'solar:close-circle-bold-duotone',
  },
  revision: {
    title: 'New revision',
    description:
      'Cancels this version (kept in the history) and opens a new draft version with the same lines for you to change and re-send.',
    confirm: 'Create revision',
    icon: 'solar:copy-bold-duotone',
  },
}

export function QuotationRevisionDialog({
  mode,
  quotationNumber,
  open,
  pending,
  onOpenChange,
  onSubmit,
}: {
  mode: QuotationRevisionMode
  quotationNumber: string
  open: boolean
  pending: boolean
  onOpenChange: (open: boolean) => void
  onSubmit: (payload: { reason_code: string; reason_text: string }) => void
}) {
  const [reasonCode, setReasonCode] = useState('')
  const [reasonText, setReasonText] = useState('')
  const copy = COPY[mode]
  const effectiveCode = mode === 'revision' ? QUOTATION_REVISION_REASON : reasonCode

  const handleOpenChange = (next: boolean) => {
    if (pending) return
    if (!next) {
      setReasonCode('')
      setReasonText('')
    }
    onOpenChange(next)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon={copy.icon} className="h-5 w-5" />
            {copy.title} {quotationNumber}
          </DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {mode === 'reject' && (
            <div className="space-y-2">
              <Label htmlFor="quotation-reject-reason">Reason code *</Label>
              <Select value={reasonCode} onValueChange={setReasonCode} disabled={pending}>
                <SelectTrigger id="quotation-reject-reason" aria-label="Reason code">
                  <SelectValue placeholder="Select a reason…" />
                </SelectTrigger>
                <SelectContent>
                  {QUOTATION_REJECT_REASONS.map((reason) => (
                    <SelectItem key={reason.value} value={reason.value}>
                      {reason.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}
          <div className="space-y-2">
            <Label htmlFor="quotation-revision-text">
              {mode === 'reject' ? 'Details' : 'Details of the change'}
            </Label>
            <Textarea
              id="quotation-revision-text"
              value={reasonText}
              maxLength={500}
              rows={3}
              disabled={pending}
              placeholder={mode === 'reject' ? 'What did the customer say?' : 'What changes in this revision?'}
              onChange={(e) => setReasonText(e.target.value)}
            />
            <p className="text-right text-xs text-muted-foreground">{reasonText.length}/500</p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={pending}>
            Keep as is
          </Button>
          <Button
            variant={mode === 'reject' ? 'destructive' : 'default'}
            disabled={pending || !effectiveCode}
            onClick={() => onSubmit({ reason_code: effectiveCode, reason_text: reasonText.trim() })}
          >
            {pending ? 'Working…' : copy.confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
