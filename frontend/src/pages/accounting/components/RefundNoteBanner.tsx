import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Icon } from '@iconify/react';
import { formatCurrency } from '@/lib/utils';
import { useMyInvoisRefundNoteIssuance } from '@/pages/accounting/components/useEInvoice';

export interface RefundNotePreviewProps {
  customerName?: string | null;
  invoiceNumber?: string | null;
  amount: number;
  currency?: string | null;
  date?: string | null;
}

/**
 * #825 (EINV-UX8): the refund note as it will be reported to MyInvois —
 * LHDN type 04 (`MyInvoiceSubmissionProvider` maps `refund_note => 04`),
 * referencing the original invoice the money is returned against.
 */
export function RefundNotePreview({
  customerName,
  invoiceNumber,
  amount,
  currency,
  date,
}: RefundNotePreviewProps) {
  const issuedDate = date ?? new Date().toISOString().slice(0, 10);

  return (
    <div className="mt-2 rounded-md border bg-background/60 p-3 text-sm" data-testid="refund-note-preview">
      <div className="flex items-center justify-between gap-4">
        <span className="font-medium">Refund Note (LHDN type 04)</span>
        <span className="text-xs text-muted-foreground">{issuedDate}</span>
      </div>
      <div className="mt-2 space-y-1">
        <div className="flex justify-between gap-4">
          <span className="text-muted-foreground">Original invoice</span>
          <span className="font-medium">{invoiceNumber || '-'}</span>
        </div>
        <div className="flex justify-between gap-4">
          <span className="text-muted-foreground">Buyer</span>
          <span className="font-medium">{customerName || '-'}</span>
        </div>
        <div className="flex justify-between gap-4">
          <span className="text-muted-foreground">Refund amount</span>
          <span className="font-medium">
            {currency ? `${currency} ` : ''}
            {formatCurrency(Math.abs(amount))}
          </span>
        </div>
      </div>
    </div>
  );
}

/**
 * #825 (EINV-UX8): "Refund note will be issued to LHDN" warning shown while a
 * customer refund is recorded. Hidden unless the refund will really be
 * reported (#816 trigger rules: MY entity, plugin enabled, auto_submit on).
 */
export default function RefundNoteBanner({
  customerName,
  invoiceNumber,
  amount,
  currency,
  date,
}: RefundNotePreviewProps) {
  const { enabled } = useMyInvoisRefundNoteIssuance();

  if (!enabled) {
    return null;
  }

  return (
    <Alert>
      <Icon icon="solar:document-add-bold-duotone" className="h-4 w-4" />
      <AlertTitle>Refund note will be issued to LHDN</AlertTitle>
      <AlertDescription>
        <p className="text-muted-foreground">
          This refund is reported to MyInvois as a refund note (type 04) referencing the original
          invoice.
        </p>
        <RefundNotePreview
          customerName={customerName}
          invoiceNumber={invoiceNumber}
          amount={amount}
          currency={currency}
          date={date}
        />
      </AlertDescription>
    </Alert>
  );
}
