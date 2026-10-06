/**
 * Issue #597 (RPT-UX2): the compact sales filter bar's pure logic — preset
 * periods and the chip list for active filters — kept out of the page so it
 * is unit-testable.
 */
import { addDays, endOfMonth, startOfMonth, startOfQuarter, startOfYear, subMonths } from 'date-fns';
import type { ReportFilters } from '@/types/reports';

export type PeriodPreset = 'this_month' | 'last_month' | 'qtd' | 'ytd' | 'last_12_months';

export const PERIOD_PRESETS: Array<{ value: PeriodPreset; label: string }> = [
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'qtd', label: 'QTD' },
  { value: 'ytd', label: 'YTD' },
  { value: 'last_12_months', label: 'Last 12 months' },
];

/** Local calendar date as `YYYY-MM-DD` (no UTC shift). */
const ymd = (date: Date): string => {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
};

/**
 * The date range a preset stands for, relative to `today`. Quarter and year
 * are calendar quarter / calendar year; "to date" ranges end today, and
 * "Last 12 months" is the rolling twelve months ending today.
 */
export const periodPresetRange = (
  preset: PeriodPreset,
  today: Date = new Date(),
): { start_date: string; end_date: string } => {
  switch (preset) {
    case 'last_month': {
      const previous = subMonths(today, 1);
      return { start_date: ymd(startOfMonth(previous)), end_date: ymd(endOfMonth(previous)) };
    }
    case 'qtd':
      return { start_date: ymd(startOfQuarter(today)), end_date: ymd(today) };
    case 'ytd':
      return { start_date: ymd(startOfYear(today)), end_date: ymd(today) };
    case 'last_12_months':
      return { start_date: ymd(addDays(subMonths(today, 12), 1)), end_date: ymd(today) };
    default:
      return { start_date: ymd(startOfMonth(today)), end_date: ymd(today) };
  }
};

/** The preset the current range matches, or null for a custom range. */
export const activePeriodPreset = (
  filters: Pick<ReportFilters, 'start_date' | 'end_date'>,
  today: Date = new Date(),
): PeriodPreset | null => {
  const match = PERIOD_PRESETS.find(({ value }) => {
    const range = periodPresetRange(value, today);
    return range.start_date === filters.start_date && range.end_date === filters.end_date;
  });
  return match?.value ?? null;
};

export type ChipFilterKey =
  | 'customer_id'
  | 'product_id'
  | 'category_id'
  | 'salesperson_id'
  | 'channel'
  | 'legal_entity_id'
  | 'tag'
  | 'currency_code'
  | 'comparison';

export interface FilterChip {
  key: ChipFilterKey;
  label: string;
}

interface Named {
  id: number;
  name: string;
}

export interface FilterChipLookups {
  customers?: Named[];
  products?: Named[];
  categories?: Named[];
  salespersons?: Named[];
  entities?: Named[];
}

const CHANNEL_LABELS: Record<string, string> = {
  pos: 'POS',
  admin: 'Admin',
  ecommerce: 'E-commerce',
  storefront: 'Storefront',
};

const COMPARISON_LABELS: Record<string, string> = {
  previous_period: 'Previous period',
  same_period_last_year: 'Same period last year',
  custom: 'Custom range',
};

/**
 * One chip per active (non-period) filter, in the bar's order. A name comes
 * from `picked` (what the user just chose in a search picker), then the
 * loaded option lists, then falls back to `#id` so a chip is never blank.
 */
export const activeFilterChips = (
  filters: ReportFilters,
  lookups: FilterChipLookups,
  picked: Partial<Record<ChipFilterKey, string>> = {},
): FilterChip[] => {
  const chips: FilterChip[] = [];
  const named = (key: ChipFilterKey, title: string, id: number | undefined, list?: Named[]) => {
    if (!id) return;
    const name = picked[key] ?? list?.find((item) => Number(item.id) === Number(id))?.name ?? `#${id}`;
    chips.push({ key, label: `${title}: ${name}` });
  };

  named('customer_id', 'Customer', filters.customer_id, lookups.customers);
  named('product_id', 'Product', filters.product_id, lookups.products);
  named('category_id', 'Category', filters.category_id, lookups.categories);
  named('salesperson_id', 'Salesperson', filters.salesperson_id, lookups.salespersons);
  if (filters.channel) {
    chips.push({ key: 'channel', label: `Channel: ${CHANNEL_LABELS[filters.channel] ?? filters.channel}` });
  }
  named('legal_entity_id', 'Entity', filters.legal_entity_id, lookups.entities);
  if (filters.tag) chips.push({ key: 'tag', label: `Tag: ${filters.tag}` });
  if (filters.currency_code) chips.push({ key: 'currency_code', label: `Currency: ${filters.currency_code}` });
  if (filters.comparison) {
    chips.push({ key: 'comparison', label: `Compare: ${COMPARISON_LABELS[filters.comparison] ?? filters.comparison}` });
  }

  return chips;
};
