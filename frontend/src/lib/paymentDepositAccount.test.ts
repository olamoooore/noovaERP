import { beforeEach, describe, expect, it } from 'vitest';
import {
  clearDepositMemory,
  defaultDepositAccountId,
  readDepositMemory,
  rememberDeposit,
  type DepositAccountOption,
} from './paymentDepositAccount';

const OPTIONS: DepositAccountOption[] = [
  { id: 7, account_name: 'Cash On Hand', gl_account_code: '1010' },
  { id: 8, account_name: 'Maybank Operating', gl_account_code: '1020' },
  { id: 9, account_name: 'CIMB Collections', gl_account_code: '1021' },
];

describe('#485 — deposit account defaulting + per-user memory', () => {
  beforeEach(() => {
    clearDepositMemory('user-1');
    clearDepositMemory('user-2');
  });

  it('prefers the GL code convention per method (cash → 1010, others → 1020)', () => {
    expect(defaultDepositAccountId('cash', OPTIONS, {})).toBe(7);
    expect(defaultDepositAccountId('bank_transfer', OPTIONS, {})).toBe(8);
    expect(defaultDepositAccountId('card', OPTIONS, {})).toBe(8);
  });

  it('returns the remembered account for the method when still offered', () => {
    const memory = { bank_transfer: 9, cash: 7 };
    expect(defaultDepositAccountId('bank_transfer', OPTIONS, memory)).toBe(9);
    expect(defaultDepositAccountId('cash', OPTIONS, memory)).toBe(7);
  });

  it('ignores a remembered account that is no longer offered', () => {
    expect(defaultDepositAccountId('bank_transfer', OPTIONS, { bank_transfer: 999 })).toBe(8);
  });

  it('falls back to the first option when no GL code matches the convention', () => {
    const odd: DepositAccountOption[] = [{ id: 3, account_name: 'Petty Cash', gl_account_code: '9999' }];
    expect(defaultDepositAccountId('cash', odd, {})).toBe(3);
  });

  it('returns null when no accounts are configured (backend default mapping)', () => {
    expect(defaultDepositAccountId('cash', [], { cash: 7 })).toBeNull();
  });

  it('remembers per method and per user in isolation', () => {
    rememberDeposit('user-1', 'bank_transfer', 9);
    rememberDeposit('user-2', 'bank_transfer', 8);

    expect(readDepositMemory('user-1')).toEqual({ bank_transfer: 9 });
    expect(readDepositMemory('user-2')).toEqual({ bank_transfer: 8 });
    expect(defaultDepositAccountId('bank_transfer', OPTIONS, readDepositMemory('user-1'))).toBe(9);
    expect(defaultDepositAccountId('bank_transfer', OPTIONS, readDepositMemory('user-2'))).toBe(8);
  });

  it('merges remembered methods instead of overwriting them', () => {
    rememberDeposit('user-1', 'bank_transfer', 9);
    rememberDeposit('user-1', 'cash', 7);
    expect(readDepositMemory('user-1')).toEqual({ bank_transfer: 9, cash: 7 });
  });
});
