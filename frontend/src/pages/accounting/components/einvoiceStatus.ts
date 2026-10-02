/**
 * #670 (EINV-UX1): the e-invoice status as a first-class document state.
 *
 * The effective status can come from the compliance document (its own
 * lifecycle) or from the latest submission — an accepted document with an
 * open buyer rejection request is NOT "valid", so the submission wins when
 * present.
 */
export type EInvoiceStatusKey =
  | 'not_submitted'
  | 'queued'
  | 'submitted'
  | 'valid'
  | 'invalid'
  | 'cancelled'
  | 'buyer_rejection_requested';

export const EINVOICE_STATUS_META: Record<EInvoiceStatusKey, { label: string; className: string }> = {
  not_submitted: { label: 'Not submitted', className: 'bg-gray-100 text-gray-700 border-gray-200' },
  queued: { label: 'Queued', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  submitted: { label: 'Submitted', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  valid: { label: 'Valid', className: 'bg-green-100 text-green-800 border-green-200' },
  invalid: { label: 'Invalid', className: 'bg-red-50 text-red-700 border-red-200' },
  cancelled: { label: 'Cancelled', className: 'bg-gray-100 text-gray-600 border-gray-200' },
  buyer_rejection_requested: { label: 'Buyer rejection requested', className: 'bg-orange-50 text-orange-700 border-orange-200' },
};

export function deriveEInvoiceStatus(
  documentStatus?: string | null,
  submissionStatus?: string | null,
): EInvoiceStatusKey {
  const submission = (submissionStatus ?? '').toLowerCase();

  if (submission) {
    if (submission === 'rejected' || submission === 'failed') return 'invalid';
    if (submission === 'cancelled') return 'cancelled';
    if (submission === 'rejection_requested' || submission === 'rejection_declined_pending_expiry') return 'buyer_rejection_requested';
    if (submission === 'accepted') return 'valid';
    if (submission === 'ack' || submission === 'acknowledged') return 'submitted';
    if (submission === 'pending' || submission === 'queued') return 'queued';
  }

  switch ((documentStatus ?? '').toLowerCase()) {
    case 'accepted':
      return 'valid';
    case 'submitted':
      return 'submitted';
    case 'rejected':
    case 'failed':
      return 'invalid';
    case 'cancelled':
      return 'cancelled';
    case 'pending':
      return 'queued';
    default:
      return 'not_submitted';
  }
}

/** LHDN's buyer rejection window is 72 hours (see MyInvoiceStatusSync). */
export const BUYER_REJECTION_WINDOW_HOURS = 72;

export function buyerRejectionDeadline(submission?: {
  submission_status?: string;
  submitted_at?: string | null;
  acknowledged_at?: string | null;
  status_details?: Record<string, unknown> | null;
} | null): Date | null {
  if (!submission) return null;

  const status = (submission.submission_status ?? '').toLowerCase();
  if (status !== 'rejection_requested' && status !== 'rejection_declined_pending_expiry') {
    return null;
  }

  const details = (submission.status_details ?? {}) as Record<string, unknown>;
  const explicit = details.rejection_deadline ?? details.rejection_expires_at ?? details.expires_at;
  if (typeof explicit === 'string' && explicit) {
    const parsed = new Date(explicit);
    if (!Number.isNaN(parsed.getTime())) return parsed;
  }

  const basis = submission.acknowledged_at ?? submission.submitted_at;
  if (!basis) return null;

  const parsed = new Date(basis);
  if (Number.isNaN(parsed.getTime())) return null;

  return new Date(parsed.getTime() + BUYER_REJECTION_WINDOW_HOURS * 60 * 60 * 1000);
}

export function formatCountdown(deadline: Date | null, now: Date = new Date()): string | null {
  if (!deadline) return null;

  const remaining = deadline.getTime() - now.getTime();
  if (remaining <= 0) return 'window expired';

  const hours = Math.floor(remaining / (60 * 60 * 1000));
  const days = Math.floor(hours / 24);

  if (days >= 1) return `${days}d ${hours % 24}h left`;
  const minutes = Math.floor((remaining % (60 * 60 * 1000)) / (60 * 1000));
  return `${hours}h ${minutes}m left`;
}
