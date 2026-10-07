import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import type { CustomerDuplicateMatch, CustomerDuplicatePayload } from '@/lib/customerDuplicate'

/**
 * Issue #441: the duplicate guard (#435) answers a customer create/update
 * with a 409 listing the matching customers. This dialog shows the matches
 * and offers "Merge into existing" (the 409's merge_action hint) next to the
 * existing "save anyway" acknowledgement/override.
 */

interface CustomerDuplicateDialogProps {
  payload: CustomerDuplicatePayload | null
  onOpenChange: (open: boolean) => void
  onMergeInto: (match: CustomerDuplicateMatch) => void
  onSaveAnyway: (extra: { allow_duplicate?: true; duplicate_acknowledgement_reason?: string }) => void
  busy?: boolean
}

export function CustomerDuplicateDialog({ payload, onOpenChange, onMergeInto, onSaveAnyway, busy }: CustomerDuplicateDialogProps) {
  const [reason, setReason] = useState('')
  const open = payload !== null
  const strong = payload?.tier === 'strong'
  const createFirst = payload?.merge_action?.requires_create_first ?? true

  useEffect(() => {
    if (!open) setReason('')
  }, [open])

  const saveAnyway = () => {
    const extra: { allow_duplicate?: true; duplicate_acknowledgement_reason?: string } = {}
    if (strong) extra.allow_duplicate = true
    if (reason.trim() !== '') extra.duplicate_acknowledgement_reason = reason.trim()
    onSaveAnyway(extra)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>{strong ? 'Duplicate customer' : 'Possible duplicate customer'}</DialogTitle>
          <DialogDescription>{payload?.message}</DialogDescription>
        </DialogHeader>

        <ul className="space-y-2">
          {(payload?.matches ?? []).map((match) => (
            <li key={match.customer_id} className="flex items-center justify-between gap-3 rounded-md border p-3">
              <div className="min-w-0">
                <p className="font-medium truncate">{match.customer_name || `Customer #${match.customer_id}`}</p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {match.tier && <Badge variant={match.tier === 'strong' ? 'destructive' : 'secondary'}>{match.tier}</Badge>}
                  {(match.match_types ?? (match.match_type ? [match.match_type] : [])).map((type) => (
                    <Badge key={type} variant="outline">{type.replace(/_/g, ' ')}</Badge>
                  ))}
                </div>
              </div>
              <Button size="sm" variant="outline" disabled={busy} onClick={() => onMergeInto(match)}>
                Merge into existing
              </Button>
            </li>
          ))}
        </ul>

        <p className="text-xs text-muted-foreground">
          {createFirst
            ? 'Merge into existing saves this customer and immediately merges it into the selected one; its contacts and addresses move there.'
            : 'Merge into existing merges this customer into the selected one; its documents, contacts, tags and notes move there.'}
        </p>

        <div className="space-y-2">
          <Label htmlFor="customer-duplicate-reason">{strong ? 'Reason (optional)' : 'Reason to save anyway'}</Label>
          <Textarea id="customer-duplicate-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button disabled={busy || (!strong && reason.trim() === '')} onClick={saveAnyway}>
            Save anyway
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
