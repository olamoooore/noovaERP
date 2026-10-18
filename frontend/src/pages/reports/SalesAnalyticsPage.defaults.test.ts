// @vitest-environment jsdom
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import { defaultFilters } from './SalesAnalyticsPage';

/**
 * #591 (RP2): the default report period must be built from the local calendar
 * date, not the UTC date.
 *
 * The pre-fix builder ran a local-midnight Date through toISOString(). For a
 * UTC+8 tenant at 03 Oct 2026 00:30 MYT (= 02 Oct 16:30 UTC) that produced
 * start_date=2026-09-30 (last day of the previous month) and
 * end_date=2026-10-02 (yesterday). Same root cause as #352/#484; the fix is
 * the shared formatLocalDate() helper from lib/reportApi.
 */
describe('SalesAnalyticsPage default period (#591)', () => {
  const originalTz = process.env.TZ;

  beforeAll(() => {
    process.env.TZ = 'Asia/Kuala_Lumpur';
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-03T00:30:00+08:00'));
  });

  afterAll(() => {
    vi.useRealTimers();
    if (originalTz === undefined) {
      delete process.env.TZ;
    } else {
      process.env.TZ = originalTz;
    }
  });

  it('starts on the 1st of the current local month and ends on the local today', () => {
    const filters = defaultFilters();

    expect(filters.start_date).toBe('2026-10-01');
    expect(filters.end_date).toBe('2026-10-03');
  });

  it('reproduces the pre-fix toISOString shift for the same instant', () => {
    const now = new Date();
    const naiveStart = new Date(now.getFullYear(), now.getMonth(), 1)
      .toISOString()
      .split('T')[0];

    expect(naiveStart).toBe('2026-09-30'); // last day of the previous month
    expect(now.toISOString().split('T')[0]).toBe('2026-10-02'); // yesterday
  });
});
