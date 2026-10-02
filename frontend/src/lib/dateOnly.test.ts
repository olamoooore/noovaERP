import { describe, expect, it } from 'vitest';
import { addCalendarDays, diffCalendarDays, isValidProductQuantity, todayDateOnly } from './dateOnly';

describe('issue #71 WRK-A — date-only due-date arithmetic (HR71-02)', () => {
  it('computes 08/09/2026 + 60 = 07/11/2026 deterministically', () => {
    expect(addCalendarDays('2026-09-08', 60)).toBe('2026-11-07');
  });

  it('treats +0 days as due date == invoice date', () => {
    expect(addCalendarDays('2026-09-08', 0)).toBe('2026-09-08');
  });

  it('is timezone-safe across positive and negative UTC offsets', () => {
    // The implementation uses Date.UTC only; a locally-parsed-midnight +
    // toISOString() implementation would shift across these TZs.
    const original = process.env.TZ;
    for (const tz of ['Pacific/Kiritimati', 'Pacific/Midway', 'UTC']) {
      process.env.TZ = tz;
      expect(addCalendarDays('2026-09-08', 60)).toBe('2026-11-07');
      expect(addCalendarDays('2026-01-31', 0)).toBe('2026-01-31');
    }
    if (original !== undefined) process.env.TZ = original;
  });

  it('round-trips a month-boundary save/reload without drift', () => {
    const due = addCalendarDays('2026-01-31', 1);
    expect(due).toBe('2026-02-01');
    expect(diffCalendarDays('2026-01-31', due)).toBe(1);
  });

  it('diffCalendarDays inverts addCalendarDays', () => {
    expect(diffCalendarDays('2026-09-08', addCalendarDays('2026-09-08', 45))).toBe(45);
  });

  it('todayDateOnly returns a YYYY-MM-DD business value', () => {
    expect(todayDateOnly(new Date(2026, 8, 8))).toBe('2026-09-08');
  });
});

describe('issue #71 WRK-D — explicit quantity domain (HR71-08)', () => {
  it('rejects blank rather than coercing to 1', () => {
    expect(isValidProductQuantity('')).toBe(false);
  });
  it('rejects zero and negative', () => {
    expect(isValidProductQuantity(0)).toBe(false);
    expect(isValidProductQuantity(-2)).toBe(false);
  });
  it('accepts integers and 4dp decimals (fractional units)', () => {
    expect(isValidProductQuantity(1)).toBe(true);
    expect(isValidProductQuantity(2.5)).toBe(true);
    expect(isValidProductQuantity(0.0001)).toBe(true);
  });
  it('rejects precision beyond 4 decimals', () => {
    expect(isValidProductQuantity(1.00001)).toBe(false);
  });
});

describe('#484 — payment date defaults use the LOCAL date (not UTC)', () => {
  it('at 07:30 Asia/Kuala_Lumpur returns the local day, not the UTC day', () => {
    const original = process.env.TZ;
    // 07:30 MYT (UTC+8) === 23:30 UTC on the PREVIOUS calendar day.
    const at0730Local = new Date('2026-10-02T23:30:00Z');
    for (const [tz, expected] of [
      ['Asia/Kuala_Lumpur', '2026-10-03'],
      ['UTC', '2026-10-02'],
    ] as const) {
      process.env.TZ = tz;
      expect(todayDateOnly(at0730Local)).toBe(expected);
    }
    if (original !== undefined) process.env.TZ = original;
  });
});
