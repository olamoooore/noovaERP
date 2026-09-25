/**
 * Fiscal-year helpers for report date defaults.
 *
 * Reports previously defaulted to the CALENDAR year (1 Jan – today) even
 * when the tenant's fiscal year starts in another month (Acme: 1 Aug /
 * 1 Sep), so the Profit & Loss default range did not match the client's
 * yearly reporting periods. These helpers derive the fiscal years that
 * contain a given date from the tenant's company settings.
 */

export interface FiscalYearSettings {
  fiscal_year_start: string;
  fiscal_year_end: string;
  fiscal_year_label?: string;
  fiscal_year_start_month?: number;
  fiscal_year_start_day?: number;
  lock_date?: string | null;
}

export interface FiscalYearOption {
  label: string;
  start: string;
  end: string;
}

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/**
 * Build the fiscal-year options ending with the year containing `today`
 * (most recent first). `startMonth` is 1-12, `startDay` 1-31 (clamped to 28
 * so short months never overflow).
 */
export function fiscalYearOptions(
  startMonth: number,
  startDay: number,
  count = 6,
  today = new Date(),
): FiscalYearOption[] {
  const monthIndex = Math.min(Math.max(startMonth, 1), 12) - 1;
  const safeDay = Math.min(Math.max(startDay, 1), 28);

  const startFor = (year: number) => new Date(year, monthIndex, safeDay);

  let cursor = today.getFullYear();
  if (startFor(cursor) > today) {
    cursor -= 1;
  }

  const options: FiscalYearOption[] = [];
  for (let i = 0; i < count; i++) {
    const start = startFor(cursor - i);
    const end = startFor(cursor - i + 1);
    end.setDate(end.getDate() - 1);
    options.push({
      label: `FY${end.getFullYear()}`,
      start: iso(start),
      end: iso(end),
    });
  }

  return options;
}

export function fiscalYearForRange(
  options: FiscalYearOption[],
  start: string,
  end: string,
): FiscalYearOption | undefined {
  return options.find((option) => option.start === start && option.end === end);
}

/**
 * The fiscal year to compare against: the year immediately preceding the
 * selected one, or for a custom range the most recent fiscal year that ended
 * before the range starts.
 */
export function priorFiscalYear(
  options: FiscalYearOption[],
  selected: FiscalYearOption | undefined,
  rangeStart: string,
): FiscalYearOption | undefined {
  if (options.length === 0) {
    return undefined;
  }

  if (selected) {
    const index = options.findIndex((option) => option.label === selected.label);
    if (index >= 0) {
      return options[index + 1];
    }
  }

  return options.find((option) => option.end < rangeStart);
}

export function formatFiscalYearOption(option: FiscalYearOption): string {
  const fmt = (value: string) => {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  };

  return `${option.label} (${fmt(option.start)} – ${fmt(option.end)})`;
}
