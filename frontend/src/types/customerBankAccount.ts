/**
 * #444: a customer bank account (Contact Master contact_bank_accounts).
 * account_number / iban arrive masked unless the viewer holds
 * contacts.bank_accounts.view.
 */
export interface CustomerBankAccount {
  id: string;
  contact_id: string;
  bank_name: string | null;
  account_name: string | null;
  account_number: string | null;
  account_type: string | null;
  routing_number: string | null;
  swift_code: string | null;
  iban: string | null;
  currency: string | null;
  branch_name: string | null;
  is_primary: boolean;
  is_active: boolean;
  deleted_at?: string | null;
}

export const customerBankAccountLabel = (account: CustomerBankAccount): string =>
  [account.bank_name, account.account_number, account.account_name].filter(Boolean).join(' · ') || 'Bank account';
