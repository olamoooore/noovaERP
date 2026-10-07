import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { formatCurrency, formatDate } from '@/lib/utils';
import { matchedOnLabel, type DuplicateCheckResult } from '@/lib/duplicateCheck';

/**
 * Issue #327: the server refused to create a likely duplicate (same customer
 * reference or PO number as an existing invoice for this customer). The user
 * either cancels or confirms "create anyway", which resends the save with
 * confirm_duplicate and is recorded on the invoice's history.
 */
export function DuplicateInvoiceDialog({
  check,
  onCancel,
  onConfirm,
  pending = false,
}: {
  check: DuplicateCheckResult | null;
  onCancel: () => void;
  onConfirm: () => void;
  pending?: boolean;
}) {
  return (
    <Dialog open={check !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Likely duplicate invoice</DialogTitle>
          <DialogDescription>
            This customer already has an invoice with the same reference or PO number. Check it before creating
            another one.
          </DialogDescription>
        </DialogHeader>
        <ul className="space-y-2 text-sm" data-testid="duplicate-invoice-matches">
          {(check?.matches ?? []).map((match) => (
            <li
              key={match.id}
              className={
                match.level === 'likely'
                  ? 'rounded-md border border-red-200 bg-red-50 px-3 py-2 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300'
                  : 'rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300'
              }
            >
              <span className="font-semibold">{match.invoice_number}</span>
              {' · '}
              {match.invoice_date ? formatDate(match.invoice_date) : 'No date'}
              {' · '}
              {formatCurrency(match.total, match.currency_code ?? undefined)}
              {' · '}
              {matchedOnLabel(match)}
            </li>
          ))}
        </ul>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="button" onClick={onConfirm} disabled={pending}>
            Create anyway
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
