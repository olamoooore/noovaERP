// #486 — payment-method options come from the tenant method master
// (GET /accounting/payment-methods), not a hardcoded 5-item array.
// The backend FALLBACK list keeps UI working for unseeded tenants.

export interface PaymentMethodRow {
  code: string;
  label: string;
  mapping_key?: string | null;
  requires_reference?: boolean;
  requires_cheque_fields?: boolean;
  default_bank_account_id?: number | null;
  country_code?: string | null;
  is_active?: boolean;
}

export interface MethodOption {
  value: string;
  label: string;
  icon: string;
  requiresReference: boolean;
}

const ICONS: Record<string, string> = {
  cash: 'solar:wallet-bold-duotone',
  bank_transfer: 'solar:banknote-bold-duotone',
  card: 'solar:card-2-bold-duotone',
  check: 'solar:document-bold-duotone',
  gateway: 'solar:card-recive-bold-duotone',
  other: 'solar:question-circle-bold-duotone',
  duitnow: 'solar:qr-code-bold-duotone',
  paynow: 'solar:qr-code-bold-duotone',
  fpx: 'solar:banknote-bold-duotone',
  grabpay: 'solar:wallet-money-bold-duotone',
};

const FALLBACK_ICONS = 'solar:wallet-bold-duotone';

/** Historical hardcoded list — fallback when the master is unseeded/empty. */
export const FALLBACK_METHOD_OPTIONS: MethodOption[] = [
  { value: 'cash', label: 'Cash', icon: ICONS['cash'], requiresReference: false },
  { value: 'bank_transfer', label: 'Bank Transfer', icon: ICONS['bank_transfer'], requiresReference: false },
  { value: 'card', label: 'Card', icon: ICONS['card'], requiresReference: false },
  { value: 'check', label: 'Check', icon: ICONS['check'], requiresReference: false },
  { value: 'other', label: 'Other', icon: ICONS['other'], requiresReference: false },
];

export function methodIcon(code: string): string {
  return ICONS[code] ?? FALLBACK_ICONS;
}

export function buildMethodOptions(
  rows: PaymentMethodRow[] | undefined | null,
  fallback: MethodOption[] = FALLBACK_METHOD_OPTIONS,
): MethodOption[] {
  if (!rows || rows.length === 0) return fallback;
  return rows.map((row) => ({
    value: row.code,
    label: row.label,
    icon: methodIcon(row.code),
    requiresReference: Boolean(row.requires_reference),
  }));
}
