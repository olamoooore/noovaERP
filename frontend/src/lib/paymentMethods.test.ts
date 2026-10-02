import { describe, expect, it } from 'vitest';
import {
  FALLBACK_METHOD_OPTIONS,
  buildMethodOptions,
  methodIcon,
  type PaymentMethodRow,
} from './paymentMethods';

const MASTER: PaymentMethodRow[] = [
  { code: 'cash', label: 'Cash', mapping_key: 'cash' },
  { code: 'check', label: 'Cheque', mapping_key: 'cheque_clearing', requires_reference: true, requires_cheque_fields: true },
  { code: 'duitnow', label: 'DuitNow', mapping_key: 'bank' },
  { code: 'paynow', label: 'PayNow', mapping_key: 'bank' },
];

describe('#486 — method options are built from the tenant master', () => {
  it('uses master rows (labels, codes) when present', () => {
    const options = buildMethodOptions(MASTER);
    expect(options.map((o) => o.value)).toEqual(['cash', 'check', 'duitnow', 'paynow']);
    expect(options.find((o) => o.value === 'duitnow')?.label).toBe('DuitNow');
    expect(options.find((o) => o.value === 'check')?.label).toBe('Cheque');
  });

  it('carries requires_reference from the master (Cheque needs one)', () => {
    const options = buildMethodOptions(MASTER);
    expect(options.find((o) => o.value === 'check')?.requiresReference).toBe(true);
    expect(options.find((o) => o.value === 'cash')?.requiresReference).toBe(false);
  });

  it('falls back to the historical list when the master is empty/unseeded', () => {
    expect(buildMethodOptions([])).toEqual(FALLBACK_METHOD_OPTIONS);
    expect(buildMethodOptions(undefined)).toEqual(FALLBACK_METHOD_OPTIONS);
    expect(FALLBACK_METHOD_OPTIONS.map((o) => o.value)).toEqual([
      'cash',
      'bank_transfer',
      'card',
      'check',
      'other',
    ]);
  });

  it('assigns known icons and a safe default for unknown rails', () => {
    expect(methodIcon('cash')).toBe('solar:wallet-bold-duotone');
    expect(methodIcon('duitnow')).toBe('solar:qr-code-bold-duotone');
    expect(methodIcon('brand-new-rail')).toBe('solar:wallet-bold-duotone');
  });
});
