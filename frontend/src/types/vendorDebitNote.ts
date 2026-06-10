export interface VendorDebitNote {
  id: number;
  bill_id: number;
  vendor_id: number;
  debit_note_number: string;
  debit_note_date: string;
  total_amount: number;
  tax_amount: number;
  status: 'draft' | 'posted';
  reason: string;
  notes: string;
  created_at: string;
}

export interface VendorDebitNoteFilters {
  status?: string;
  vendor_id?: number;
  bill_id?: number;
  search?: string;
}

export interface CreateVendorDebitNotePayload {
  bill_id: number;
  vendor_id: number;
  debit_note_date: string;
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
