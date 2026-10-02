export interface CustomerCreditNote {
  id: number;
  invoice_id: number;
  customer_id: number;
  credit_note_number: string;
  credit_note_date: string;
  total_amount: number;
  tax_amount: number;
  status: 'draft' | 'posted';
  reason: string;
  notes: string;
  created_at: string;
}

export interface CreditNoteFilters {
  search?: string;
  status?: string;
  customer_id?: number;
  invoice_id?: number;
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
