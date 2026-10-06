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
  options: { exchangeRate?: number | null } = {},
): Record<string, unknown> {
  return {
    ...form,
    bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
    ...(options.exchangeRate != null ? { exchange_rate: options.exchangeRate } : {}),
  };
}
