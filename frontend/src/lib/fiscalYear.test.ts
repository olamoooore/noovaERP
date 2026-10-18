import { describe, expect, it } from 'vitest';
import {
  fiscalYearForRange,
  fiscalYearOptions,
  priorFiscalYear,
} from './fiscalYear';

/**
 * Regression: the Profit & Loss compare period defaulted to a stale/wrong
 * range (e.g. 1 Aug 2021 – 31 Jul 2022) instead of the fiscal year before
 * the selected reporting period. Reporting periods must follow the tenant's
 * fiscal-year setting (Acme: 1 Aug locally, 1 Sep on the server).
 */
describe('fiscalYearOptions', () => {
  it('builds September-start fiscal years (server convention)', () => {
    const options = fiscalYearOptions(9, 1, 6, new Date(2026, 8, 25)); // 25 Sep 2026

    expect(options[0]).toEqual({ label: 'FY2027', start: '2026-09-01', end: '2027-08-31' });
    expect(options[1]).toEqual({ label: 'FY2026', start: '2025-09-01', end: '2026-08-31' });
    expect(options[5]).toEqual({ label: 'FY2022', start: '2021-09-01', end: '2022-08-31' });
  });

  it('builds August-start fiscal years (local convention)', () => {
    const options = fiscalYearOptions(8, 1, 3, new Date(2026, 8, 25)); // 25 Sep 2026

    expect(options[0]).toEqual({ label: 'FY2027', start: '2026-08-01', end: '2027-07-31' });
    expect(options[1]).toEqual({ label: 'FY2026', start: '2025-08-01', end: '2026-07-31' });
  });

  it('rolls back a year before the fiscal year has started', () => {
    const options = fiscalYearOptions(9, 1, 2, new Date(2026, 7, 15)); // 15 Aug 2026

    expect(options[0]).toEqual({ label: 'FY2026', start: '2025-09-01', end: '2026-08-31' });
  });
});

describe('priorFiscalYear', () => {
  const options = fiscalYearOptions(8, 1, 6, new Date(2026, 8, 25)); // FY2027 … FY2022

  it('returns the fiscal year immediately before the selected one', () => {
    expect(priorFiscalYear(options, options[0], options[0].start)?.label).toBe('FY2026');
    expect(priorFiscalYear(options, options[2], options[2].start)?.label).toBe('FY2024');
  });

  it('returns the year ending before a custom range start', () => {
    // Custom range starting 25 Sep 2026 -> FY2026 (ended 31 Jul 2026).
    expect(priorFiscalYear(options, undefined, '2026-09-25')?.label).toBe('FY2026');
  });

  it('never returns a five-years-back year for the current period', () => {
    const prior = priorFiscalYear(options, fiscalYearForRange(options, options[0].start, options[0].end), options[0].start);
    expect(prior?.start).not.toBe('2021-08-01');
    expect(prior?.start).toBe('2025-08-01');
  });

  it('returns undefined for the oldest option', () => {
    expect(priorFiscalYear(options, options[5], options[5].start)).toBeUndefined();
  });
});
