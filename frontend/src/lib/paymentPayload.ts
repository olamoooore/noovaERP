// #487 (PAY-UX1): the ONE payload builder for recording (POST /payments) and
// editing (PUT /payments/{id}) a customer payment from RegisterPaymentDialog.

export interface PaymentFormState {
  customer_id: string;
  invoice_id: string;
  amount: string;
  payment_date: string;
  method: string;
  reference: string;
  notes: string;
  bank_account_id: string;
}

/**
 * The request body for the payment form. The deposit account is sent as a
 * number (or null → the default bank mapping); an edited FX rate (#490) is
 * sent as `exchange_rate`, otherwise the backend snapshots the rate itself.
 */
export function buildPaymentPayload(
  form: PaymentFormState,
  options: {
    exchangeRate?: number | null;
    /**
     * #489: a customer-first receipt (POST /payments/receipts) carries its
     * invoice allocations instead of a single invoice_id.
     */
    applications?: Array<{ invoice_id: number; amount: number }>;
    /**
     * #494: a short payment marked as fully paid — the difference is written
     * off to the account of the chosen reason. Omitted = keep open.
     */
    writeOff?: { reason: string; label?: string } | null;
  } = {},
): Record<string, unknown> {
  const { invoice_id, ...rest } = form;
  return {
    ...rest,
    ...(options.applications ? { applications: options.applications } : { invoice_id }),
    bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
    ...(options.exchangeRate != null ? { exchange_rate: options.exchangeRate } : {}),
    ...(options.writeOff
      ? {
          payment_difference: 'write_off',
          write_off_reason: options.writeOff.reason,
          ...(options.writeOff.label?.trim() ? { write_off_label: options.writeOff.label.trim() } : {}),
        }
      : {}),
  };
}
