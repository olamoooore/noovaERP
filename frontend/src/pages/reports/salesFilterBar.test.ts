import { describe, expect, it } from 'vitest';
import { activeFilterChips, activePeriodPreset, periodPresetRange } from './salesFilterBar';

// Wed 15 Oct 2026 (Q4).
const today = new Date(2026, 9, 15);

/**
 * Issue #597 (RPT-UX2): preset periods for the compact sales filter bar.
 */
describe('periodPresetRange (#597)', () => {
  it('This month runs from the 1st to today', () => {
    expect(periodPresetRange('this_month', today)).toEqual({ start_date: '2026-10-01', end_date: '2026-10-15' });
  });

  it('Last month is the whole previous calendar month', () => {
    expect(periodPresetRange('last_month', today)).toEqual({ start_date: '2026-09-01', end_date: '2026-09-30' });
    expect(periodPresetRange('last_month', new Date(2026, 0, 10))).toEqual({
      start_date: '2025-12-01',
      end_date: '2025-12-31',
    });
  });

  it('QTD starts on the first day of the calendar quarter', () => {
    expect(periodPresetRange('qtd', today)).toEqual({ start_date: '2026-10-01', end_date: '2026-10-15' });
    expect(periodPresetRange('qtd', new Date(2026, 4, 20))).toEqual({ start_date: '2026-04-01', end_date: '2026-05-20' });
  });

  it('YTD starts on 1 January', () => {
    expect(periodPresetRange('ytd', today)).toEqual({ start_date: '2026-01-01', end_date: '2026-10-15' });
  });

  it('Last 12 months is the rolling twelve months ending today', () => {
    expect(periodPresetRange('last_12_months', today)).toEqual({ start_date: '2025-10-16', end_date: '2026-10-15' });
  });

  it('recognises which preset the current range is', () => {
    expect(activePeriodPreset({ start_date: '2026-09-01', end_date: '2026-09-30' }, today)).toBe('last_month');
    expect(activePeriodPreset({ start_date: '2026-10-01', end_date: '2026-10-15' }, today)).toBe('this_month');
    expect(activePeriodPreset({ start_date: '2026-09-02', end_date: '2026-09-30' }, today)).toBeNull();
  });
});

describe('activeFilterChips (#597)', () => {
  const lookups = {
    customers: [{ id: 1, name: 'Hospital Y' }],
    products: [{ id: 7, name: 'Implant' }],
    categories: [{ id: 4, name: 'Implants' }],
    salespersons: [{ id: 3, name: 'Salesperson X' }],
    entities: [{ id: 2, name: 'Entity MY' }],
  };

  it('names every active filter, and none for the defaults', () => {
    expect(activeFilterChips({ start_date: '2026-10-01', end_date: '2026-10-15', interval: 'day' }, lookups)).toEqual([]);

    const chips = activeFilterChips(
      {
        start_date: '2026-07-01',
        end_date: '2026-09-30',
        customer_id: 1,
        product_id: 7,
        category_id: 4,
        salesperson_id: 3,
        channel: 'pos',
        legal_entity_id: 2,
        tag: 'vip',
        currency_code: 'USD',
        comparison: 'previous_period',
      },
      lookups,
    );

    expect(chips.map((chip) => chip.label)).toEqual([
      'Customer: Hospital Y',
      'Product: Implant',
      'Category: Implants',
      'Salesperson: Salesperson X',
      'Channel: POS',
      'Entity: Entity MY',
      'Tag: vip',
      'Currency: USD',
      'Compare: Previous period',
    ]);
    expect(chips.map((chip) => chip.key)).toContain('customer_id');
  });

  it('falls back to the id when a name is not loaded, and prefers a picked label', () => {
    const chips = activeFilterChips({ customer_id: 99, product_id: 8 }, lookups, { customer_id: 'Picked Clinic' });
    expect(chips.map((chip) => chip.label)).toEqual(['Customer: Picked Clinic', 'Product: #8']);
  });
});
