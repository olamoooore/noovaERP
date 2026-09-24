import { describe, expect, it } from 'vitest';
import {
  formatCompactCurrency,
  formatCurrency,
  makeAxisTickFormatter,
  maxAbsOf,
  truncateLabel,
} from './chartUtils';

/**
 * Recharts invokes tick formatters as `tickFormatter(value, index)`. Passing a
 * formatter that takes a currency code as its second parameter straight to
 * Recharts therefore fed the tick INDEX in as the currency, throwing
 * "RangeError: Invalid currency code : 4" and blanking the chart.
 */
describe('axis tick formatters are safe when Recharts passes a tick index', () => {
  it('ignores a numeric second argument', () => {
    expect(() => formatCurrency(1234, 4 as unknown as string)).not.toThrow();
    expect(() => formatCompactCurrency(1234, 4 as unknown as string)).not.toThrow();
    expect(formatCurrency(1234, 4 as unknown as string)).toBe('$1,234');
  });

  it('does not throw for the 2-argument call Recharts makes', () => {
    for (const mode of ['currency', 'number', 'percent'] as const) {
      for (const max of [10, 5_000_000]) {
        const formatter = makeAxisTickFormatter(mode, max);
        expect(() => (formatter as any)(1234, 4)).not.toThrow();
        expect(typeof formatter(1234)).toBe('string');
      }
    }
  });

  it('switches to compact notation once the axis would be unreadable', () => {
    expect(makeAxisTickFormatter('currency', 500)(1234)).toBe('$1,234');
    expect(makeAxisTickFormatter('currency', 5_000_000)(3_700_000)).toBe('$3.7M');
    expect(makeAxisTickFormatter('number', 5_000_000)(1_250_000)).toBe('1.3M');
  });

  it('returns an empty string for non-finite ticks instead of throwing', () => {
    const formatter = makeAxisTickFormatter('currency', 5_000_000);
    expect(formatter(NaN)).toBe('');
    expect(formatter(Infinity)).toBe('');
  });
});

describe('maxAbsOf', () => {
  it('finds the largest magnitude across the given keys', () => {
    const data = [{ a: 10, b: -4000 }, { a: 250, b: 3 }];
    expect(maxAbsOf(data, ['a', 'b'])).toBe(4000);
  });

  it('is 0 for empty data or non-numeric values', () => {
    expect(maxAbsOf([], ['a'])).toBe(0);
    expect(maxAbsOf([{ a: undefined }, { a: 'x' }], ['a'])).toBe(0);
  });
});

describe('truncateLabel', () => {
  it('shortens long labels with an ellipsis', () => {
    expect(truncateLabel('Singapore General Hospital', 12)).toBe('Singapore G…');
  });

  it('leaves short labels alone and tolerates null', () => {
    expect(truncateLabel('Short', 12)).toBe('Short');
    expect(truncateLabel(null, 12)).toBe('');
    expect(truncateLabel(undefined, 12)).toBe('');
  });
});
