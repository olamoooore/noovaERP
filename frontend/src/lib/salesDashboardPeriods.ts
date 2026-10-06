import { formatLocalDate } from '@/lib/reportApi';

/**
 * #358 (S10): period resolution for the Sales Dashboard.
 *
 * Every period the user can pick resolves to the date range the KPI row and
 * the charts use, plus the comparison range the KPI row is measured against.
 *
 * Comparison rules:
 *  - today            → yesterday
 *  - week (to date)   → the same days of the previous week
 *  - month / year     → the previous month / year; if the selected month or
 *                       year is still running it is shown to date and
 *                       compared with the same span of the previous one, so a
 *                       half-finished period is never set against a full one
 *  - custom           → the range of the same length immediately before it
 */

export type SalesDashboardPeriod = 'today' | 'week' | 'month' | 'year' | 'custom';

export interface DateRange {
  start_date: string;
  end_date: string;
}

export interface PeriodSelection {
  period: SalesDashboardPeriod;
  /** 0-11, used by `month` */
  month: number;
  /** used by `month` and `year` */
  year: number;
  /** YYYY-MM-DD, used by `custom` */
  customStart: string;
  /** YYYY-MM-DD, used by `custom` */
  customEnd: string;
}

export interface ResolvedPeriod {
  current: DateRange;
  previous: DateRange;
  label: string;
  previousLabel: string;
}

export type PeriodResolution =
  | { ok: true; value: ResolvedPeriod }
  | { ok: false; error: string };

export const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const DAY_MS = 24 * 60 * 60 * 1000;

const addDays = (date: Date, days: number) =>
  new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);

const startOfDay = (date: Date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());

/** Parses YYYY-MM-DD as a local calendar date; null when malformed. */
export function parseLocalDate(value: string): Date | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return null;
  const y = Number(match[1]);
  const m = Number(match[2]);
  const d = Number(match[3]);
  const date = new Date(y, m - 1, d);
  if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) return null;
  return date;
}

const range = (start: Date, end: Date): DateRange => ({
  start_date: formatLocalDate(start),
  end_date: formatLocalDate(end),
});

/** Whole days between two local dates, inclusive of both ends. */
const inclusiveDays = (start: Date, end: Date) =>
  Math.round((startOfDay(end).getTime() - startOfDay(start).getTime()) / DAY_MS) + 1;

const lastDayOfMonth = (year: number, month: number) => new Date(year, month + 1, 0).getDate();

const formatShort = (date: Date) =>
  `${date.getDate()} ${MONTH_NAMES[date.getMonth()].slice(0, 3)} ${date.getFullYear()}`;

export function resolveSalesDashboardPeriod(
  selection: PeriodSelection,
  now: Date = new Date()
): PeriodResolution {
  const today = startOfDay(now);

  switch (selection.period) {
    case 'today': {
      const yesterday = addDays(today, -1);
      return {
        ok: true,
        value: {
          current: range(today, today),
          previous: range(yesterday, yesterday),
          label: 'Today',
          previousLabel: 'Yesterday',
        },
      };
    }

    case 'week': {
      // Week starts on Sunday, matching reportApi.getDateRangePresets().thisWeek.
      const weekStart = addDays(today, -today.getDay());
      return {
        ok: true,
        value: {
          current: range(weekStart, today),
          previous: range(addDays(weekStart, -7), addDays(today, -7)),
          label: 'This week to date',
          previousLabel: 'Same days last week',
        },
      };
    }

    case 'month': {
      const { year, month } = selection;
      const start = new Date(year, month, 1);
      const fullEnd = new Date(year, month + 1, 0);
      const running = start <= today && today <= fullEnd;
      const end = running ? today : fullEnd;

      const prevYear = month === 0 ? year - 1 : year;
      const prevMonth = month === 0 ? 11 : month - 1;
      const prevStart = new Date(prevYear, prevMonth, 1);
      const prevEnd = running
        ? new Date(prevYear, prevMonth, Math.min(today.getDate(), lastDayOfMonth(prevYear, prevMonth)))
        : new Date(prevYear, prevMonth + 1, 0);

      const name = `${MONTH_NAMES[month]} ${year}`;
      const prevName = `${MONTH_NAMES[prevMonth]} ${prevYear}`;
      return {
        ok: true,
        value: {
          current: range(start, end),
          previous: range(prevStart, prevEnd),
          label: running ? `${name} to date` : name,
          previousLabel: running ? `${prevName}, same days` : prevName,
        },
      };
    }

    case 'year': {
      const { year } = selection;
      const start = new Date(year, 0, 1);
      const fullEnd = new Date(year, 11, 31);
      const running = start <= today && today <= fullEnd;
      const end = running ? today : fullEnd;

      const prevStart = new Date(year - 1, 0, 1);
      const prevEnd = running
        ? new Date(
            year - 1,
            today.getMonth(),
            Math.min(today.getDate(), lastDayOfMonth(year - 1, today.getMonth()))
          )
        : new Date(year - 1, 11, 31);

      return {
        ok: true,
        value: {
          current: range(start, end),
          previous: range(prevStart, prevEnd),
          label: running ? `${year} to date` : String(year),
          previousLabel: running ? `${year - 1}, same span` : String(year - 1),
        },
      };
    }

    case 'custom': {
      const start = parseLocalDate(selection.customStart);
      const end = parseLocalDate(selection.customEnd);
      if (!start || !end) {
        return { ok: false, error: 'Choose both a start date and an end date.' };
      }
      if (start > end) {
        return { ok: false, error: 'The start date must be on or before the end date.' };
      }
      const days = inclusiveDays(start, end);
      const prevEnd = addDays(start, -1);
      const prevStart = addDays(start, -days);
      return {
        ok: true,
        value: {
          current: range(start, end),
          previous: range(prevStart, prevEnd),
          label: `${formatShort(start)} – ${formatShort(end)}`,
          previousLabel: `Previous ${days} day${days === 1 ? '' : 's'}`,
        },
      };
    }
  }
}

/**
 * Percentage change from `previous` to `current`; null when there is no
 * previous figure to compare with (a change from zero has no percentage).
 */
export function percentChange(current: number, previous: number): number | null {
  if (!previous) return null;
  return ((current - previous) / Math.abs(previous)) * 100;
}
