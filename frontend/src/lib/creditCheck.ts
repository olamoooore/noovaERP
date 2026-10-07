/**
 * Issue #326: the credit verdict invoice create/post enforce
 * (CreditCheckService on base-currency exposure) plus the tenant policy.
 */
export interface CreditCheckVerdict {
  approved: boolean;
  reason: string;
  credit_limit: number;
  current_balance: number;
  available_credit: number | null;
  document_total: number;
  policy: 'block' | 'warn';
  blocked: boolean;
}

/**
 * Issue #326: the warning text for an invoice saved or posted under the
 * `warn` credit policy, or undefined when the check passed / is absent.
 */
export function creditWarning(payload: { credit_check?: CreditCheckVerdict | null } | null | undefined): string | undefined {
  const check = payload?.credit_check;
  if (!check || check.approved) return undefined;
  return `Credit warning: ${check.reason}`;
}
