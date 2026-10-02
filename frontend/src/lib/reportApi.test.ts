import { describe, expect, it } from 'vitest';
import { formatLocalDate } from './reportApi';

/**
 * #352 (S4): dates are built at local midnight; the API must receive the local
 * calendar date. The pre-fix implementation used toISOString(), which in a
 * UTC+ timezone shifted every range one day back. Run this suite with
 * TZ=Asia/Singapore to see the difference (CI usually runs in UTC).
 */
describe('formatLocalDate', () => {
  it('formats the local calendar date, not the UTC date', () => {
    expect(formatLocalDate(new Date(2026, 9, 1))).toBe('2026-10-01');
    expect(formatLocalDate(new Date(2026, 9, 31, 23, 59, 59))).toBe('2026-10-31');
    expect(formatLocalDate(new Date(2026, 0, 1, 0, 0, 0))).toBe('2026-01-01');
  });

  it('pads month and day', () => {
    expect(formatLocalDate(new Date(2026, 2, 5))).toBe('2026-03-05');
  });
});
