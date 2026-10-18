import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { formatTrendPeriod } from './salesAnalyticsRows';

/**
 * Issue #594 (RP5): weekly trend periods arrive as `IYYY-IW` (`2026-39`).
 * `formatTrendPeriod` only knew `YYYY-MM-DD` and `YYYY-MM`, so week keys fell
 * through and rendered raw in the table, the exports and the chart axis.
 */
describe('formatTrendPeriod (#594)', () => {
  const originalTz = process.env.TZ;

  beforeAll(() => {
    // Day labels render through date-fns' local calendar; pin the zone so the
    // expectation does not depend on the machine running the suite.
    process.env.TZ = 'Asia/Kuala_Lumpur';
  });

  afterAll(() => {
    if (originalTz === undefined) {
      delete process.env.TZ;
    } else {
      process.env.TZ = originalTz;
    }
  });

  it('renders an ISO week key as a labelled Monday–Sunday week', () => {
    // ISO week 39 of 2026 runs Mon 21 Sep – Sun 27 Sep.
    expect(formatTrendPeriod('2026-39', 'week')).toBe('Week 39 (21–27 Sep 2026)');
  });

  it('detects an unambiguous week key (13–53) without the interval', () => {
    expect(formatTrendPeriod('2026-39')).toBe('Week 39 (21–27 Sep 2026)');
  });

  it('spells out both sides of a week that crosses a month and year boundary', () => {
    expect(formatTrendPeriod('2026-01', 'week')).toBe('Week 1 (29 Dec 2025 – 4 Jan 2026)');
  });

  it('keeps the month label for a YYYY-MM month period', () => {
    expect(formatTrendPeriod('2026-09', 'month')).toBe('Sep 2026');
  });

  it('keeps the day label for a YYYY-MM-DD day period', () => {
    expect(formatTrendPeriod('2026-09-22', 'day')).toBe('22 Sep 2026');
  });

  it('falls back to the raw value for an unrecognised period', () => {
    expect(formatTrendPeriod('bogus', 'week')).toBe('bogus');
  });
});
