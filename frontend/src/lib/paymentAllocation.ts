// #489 (PAY-UX3): customer-first receipt allocation helpers.

export interface ReceiptInvoiceRow {
  id: number;
  invoice_number: string;
  invoice_date?: string | null;
  due_date?: string | null;
  balance: number | string;
  /** Foreign-currency invoices are paid from the invoice (#490), not a customer receipt. */
  foreign?: boolean;
}

const round2 = (value: number) => Math.round(value * 100) / 100;

const dueKey = (row: ReceiptInvoiceRow) => String(row.due_date || row.invoice_date || '9999-12-31').slice(0, 10);

/** Oldest due date first (invoice date when no due date), then id. */
export function sortOldestDueFirst<T extends ReceiptInvoiceRow>(rows: T[]): T[] {
  return [...rows].sort((a, b) => dueKey(a).localeCompare(dueKey(b)) || a.id - b.id);
}

/**
 * Spread `amount` across the invoices oldest-due first, each capped at its
 * open balance. Returns { invoiceId: applied } for the invoices that get
 * something; whatever is left over is the payment's unapplied credit.
 */
export function allocateOldestFirst(rows: ReceiptInvoiceRow[], amount: number): Record<number, number> {
  const result: Record<number, number> = {};
  let remaining = round2(Number.isFinite(amount) ? amount : 0);

  for (const row of sortOldestDueFirst(rows)) {
    if (remaining <= 0) break;
    if (row.foreign) continue;
    const balance = round2(Number(row.balance) || 0);
    if (balance <= 0) continue;
    const applied = round2(Math.min(remaining, balance));
    result[row.id] = applied;
    remaining = round2(remaining - applied);
  }

  return result;
}

/** Whole days past due on `today` (YYYY-MM-DD); 0 when not yet due. */
export function daysOverdue(dueDate: string | null | undefined, today: string): number {
  if (!dueDate) return 0;
  const due = Date.parse(String(dueDate).slice(0, 10));
  const now = Date.parse(today.slice(0, 10));
  if (Number.isNaN(due) || Number.isNaN(now)) return 0;
  return Math.max(0, Math.round((now - due) / 86_400_000));
}
