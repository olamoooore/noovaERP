import { describe, expect, it } from 'vitest';
import reportApi, { formatLocalDate } from './reportApi';

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

/**
 * #353 (S5): the thisWeek preset mutated the shared `today` object, so later
 * presets read the moved date — thisYear ended at the start of the current
 * week instead of today (year-to-date KPI lost the week), and thisQuarter
 * could land in the previous quarter when the week started in the previous
 * month/quarter. Wed 2026-10-14 exercises it (week start Sun 2026-10-11).
 */
describe('reportApi.getDateRangePresets', () => {
  it('does not let thisWeek move the other presets', () => {
    const presets = reportApi.getDateRangePresets(new Date(2026, 9, 14));

    expect(presets.thisWeek).toEqual({ start_date: '2026-10-11', end_date: '2026-10-14' });
    expect(presets.thisYear).toEqual({ start_date: '2026-01-01', end_date: '2026-10-14' });
    expect(presets.thisQuarter).toEqual({ start_date: '2026-10-01', end_date: '2026-12-31' });
    expect(presets.thisMonth).toEqual({ start_date: '2026-10-01', end_date: '2026-10-31' });
    expect(presets.today).toEqual({ start_date: '2026-10-14', end_date: '2026-10-14' });
  });

  it('keeps thisQuarter in the current quarter when the week crosses a month boundary', () => {
    // Sunday 2026-11-01: the week starts on the 1st, still in Q4.
    const presets = reportApi.getDateRangePresets(new Date(2026, 10, 1));

    expect(presets.thisWeek.start_date).toBe('2026-11-01');
    expect(presets.thisQuarter).toEqual({ start_date: '2026-10-01', end_date: '2026-12-31' });
    expect(presets.thisYear.end_date).toBe('2026-11-01');
  });
});
