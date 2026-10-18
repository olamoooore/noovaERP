import { describe, expect, it } from 'vitest';
import { deriveEInvoiceStatus } from './einvoiceStatus';

describe('deriveEInvoiceStatus (#667)', () => {
  it('shows a document queued for auto-submit as Queued, not "Not submitted"', () => {
    expect(deriveEInvoiceStatus('queued', null)).toBe('queued');
  });

  it('shows a terminally failed auto-submit document as a failure', () => {
    expect(deriveEInvoiceStatus('failed', null)).toBe('invalid');
  });
});
