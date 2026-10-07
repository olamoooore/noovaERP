/**
 * Issue #327: the shared duplicate-invoice verdict (InvoiceDuplicateCheck)
 * returned by POST /invoices — `likely` (same customer reference / PO, needs
 * confirmation) or `possible` (same total within 7 days, warning only).
 */
export interface DuplicateInvoiceMatch {
  id: number;
  invoice_number: string;
  invoice_date: string | null;
  total: number;
  currency_code: string | null;
  status: string;
  level: 'likely' | 'possible';
  matched_on: string[];
}

export interface DuplicateCheckResult {
  level: 'none' | 'possible' | 'likely';
  matches: DuplicateInvoiceMatch[];
  confirmed: boolean;
}

/** The likely-duplicate verdict from a refused create, or null. */
export function likelyDuplicateFromError(error: unknown): DuplicateCheckResult | null {
  const data = (error as { response?: { data?: { code?: string; duplicate_check?: DuplicateCheckResult } } })?.response?.data;
  return data?.code === 'DUPLICATE_INVOICE_LIKELY' && data.duplicate_check ? data.duplicate_check : null;
}

/** Warning text for a created invoice that resembles another, or undefined. */
export function duplicateWarning(payload: { duplicate_check?: DuplicateCheckResult | null } | null | undefined): string | undefined {
  const check = payload?.duplicate_check;
  if (!check || check.level !== 'possible' || check.matches.length === 0) return undefined;
  return `Possible duplicate of ${check.matches.map((match) => match.invoice_number).join(', ')} (same total within 7 days).`;
}

export function matchedOnLabel(match: DuplicateInvoiceMatch): string {
  return match.matched_on
    .map((field) => (field === 'po_number' ? 'same PO number' : field === 'customer_reference' ? 'same customer reference' : 'same total'))
    .join(', ');
}
