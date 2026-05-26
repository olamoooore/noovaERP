export interface VendorCreditNote {
  id: number;
  bill_id: number;
  vendor_id: number;
  credit_note_number: string;
  credit_note_date: string;
  total_amount: number;
  tax_amount: number;
  status: 'draft' | 'posted';
  reason: string;
  notes: string;
  created_at: string;
}

export interface VendorCreditNoteFilters {
  status?: string;
  vendor_id?: number;
  bill_id?: number;
}

export interface CreateVendorCreditNotePayload {
  bill_id: number;
  vendor_id: number;
  credit_note_date: string;
  reason: string;
  notes: string;
  lines: Array<{
    bill_line_id: number;
    product_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    tax_rate: number;
    account_id: number | null;
  }>;
}
