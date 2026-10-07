export interface CustomerCreditNote {
  id: number;
  invoice_id: number;
  customer_id: number;
  credit_note_number: string;
  credit_note_date: string;
  total_amount: number;
  tax_amount: number;
  status: 'draft' | 'posted' | 'voided';
  reason: string;
  notes: string;
  created_at: string;
  /** #525: what happened to the credit (null for drafts). */
  outcome?: CreditNoteOutcome | null;
  /** #525: e-invoice status key (null for drafts). */
  einvoice_status?: string | null;
  currency_code?: string | null;
  legal_entity_id?: number | null;
  /** #528: refunds of the note's credit to the customer (detail endpoint). */
  refunds?: CreditNoteRefund[];
}

/** #528: an outbound refund of a credit note's remaining credit. */
export interface CreditNoteRefund {
  id: number;
  credit_note_id: number;
  payment_id: number | null;
  amount: number | string;
  currency_code: string | null;
  journal_entry_id: number | null;
  voided_at: string | null;
  reversal_journal_entry_id: number | null;
  created_at: string;
  payment?: { id: number; payment_number: string; payment_date: string; method: string | null } | null;
}

export type CreditNoteOutcomeState = 'applied' | 'partially_applied' | 'customer_credit' | 'voided';

export interface CreditNoteOutcome {
  state: CreditNoteOutcomeState;
  applied_amount: number;
  remaining_credit: number;
  applied_to: { invoice_id: number; invoice_number: string | null } | null;
  /** #495: credit applied to other invoices from "Outstanding credits". */
  applied_elsewhere?: number;
  /** #528: credit paid out to the customer by live refunds. */
  refunded_amount?: number;
}

export interface CreditNoteFilters {
  search?: string;
  status?: string;
  customer_id?: number;
  invoice_id?: number;
  einvoice_status?: string;
  outcome?: 'applied' | 'customer_credit';
}

export interface CreateCreditNotePayload {
  invoice_id: number;
  customer_id: number;
  credit_note_date: string;
  reason: string;
  notes: string;
  lines: Array<{
    invoice_line_id: number;
    product_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    tax_rate: number;
    tax_inclusive?: boolean;
    discount_amount?: number;
    account_id: number | null;
  }>;
}
