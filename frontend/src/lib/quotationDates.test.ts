import { describe, expect, it } from 'vitest';
import { followValidUntil } from './quotationDates';

/**
 * #297 (Q11): the quote date drives Valid Until (date + validity period)
 * unless the operator overrode Valid Until manually.
 */
describe('followValidUntil', () => {
  it('follows the quote date plus the default 30-day period', () => {
    expect(followValidUntil('2026-01-15', '2026-01-10', false)).toBe('2026-02-14');
  });

  it('keeps a manually edited Valid Until', () => {
    expect(followValidUntil('2026-01-15', '2026-03-01', true)).toBe('2026-03-01');
  });

  it('keeps the current value when the quote date is empty', () => {
    expect(followValidUntil('', '2026-02-14', false)).toBe('2026-02-14');
  });

  it('honours a custom validity period', () => {
    expect(followValidUntil('2026-01-31', '', false, 45)).toBe('2026-03-17');
  });

  it('is calendar-safe across month boundaries', () => {
    expect(followValidUntil('2026-01-31', '', false)).toBe('2026-03-02');
  });
});
