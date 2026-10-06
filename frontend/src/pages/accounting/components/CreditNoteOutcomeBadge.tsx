import { Badge } from '@/components/ui/badge';
import type { CreditNoteOutcomeState } from '@/types/creditNote';

/**
 * #525 (CN-UX4): what happened to a credit note's credit — applied to its
 * invoice, left as customer credit, or released by a void.
 */
const CREDIT_NOTE_OUTCOME_META: Record<CreditNoteOutcomeState, { label: string; className: string }> = {
  applied: { label: 'Applied to invoice', className: 'bg-green-100 text-green-800 border-green-200' },
  partially_applied: { label: 'Partly applied', className: 'bg-blue-50 text-blue-700 border-blue-200' },
  customer_credit: { label: 'Customer credit', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  voided: { label: 'Released (voided)', className: 'bg-gray-100 text-gray-600 border-gray-200' },
};

export function CreditNoteOutcomeBadge({ state }: { state: CreditNoteOutcomeState }) {
  const meta = CREDIT_NOTE_OUTCOME_META[state];
  if (!meta) return null;
  return (
    <Badge variant="outline" className={meta.className}>
      {meta.label}
    </Badge>
  );
}
