import { Badge } from '@/components/ui/badge';
import { Icon } from '@iconify/react';
import { EINVOICE_STATUS_META, type EInvoiceStatusKey } from './einvoiceStatus';

interface EInvoiceStatusChipProps {
  status: EInvoiceStatusKey;
  uuid?: string | null;
  validationLink?: string | null;
  reason?: string | null;
  countdown?: string | null;
  className?: string;
}

export function EInvoiceStatusChip({
  status,
  uuid,
  validationLink,
  reason,
  countdown,
  className,
}: EInvoiceStatusChipProps) {
  const meta = EINVOICE_STATUS_META[status];
  const title = [
    meta?.label,
    countdown ? `(${countdown})` : null,
    status === 'valid' && uuid ? `UUID: ${uuid}` : null,
    reason ? `Reason: ${reason}` : null,
  ]
    .filter(Boolean)
    .join(' · ');

  return (
    <span className={`inline-flex items-center gap-1 ${className ?? ''}`} title={title}>
      <Badge variant="outline" className={meta?.className ?? ''}>
        {status === 'valid' && validationLink ? (
          <a
            href={validationLink}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 underline-offset-2 hover:underline"
            onClick={(event) => event.stopPropagation()}
          >
            {meta.label}
            <Icon icon="solar:link-bold" className="h-3 w-3" />
          </a>
        ) : (
          meta?.label ?? status
        )}
      </Badge>
      {status === 'buyer_rejection_requested' && countdown && (
        <span className="text-xs text-orange-700">{countdown}</span>
      )}
    </span>
  );
}
