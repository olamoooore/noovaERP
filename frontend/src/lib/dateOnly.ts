// Date-only business-value helpers for accounting documents (issue #71 WRK-A).
//
// HR71-02: Invoice Date / Due Date are date-only business values that must
// never shift because of client timezone or UTC serialization. Every function
// here operates on 'YYYY-MM-DD' strings using UTC calendar arithmetic only —
// it never constructs a locally-parsed midnight and re-serializes with
// toISOString(), which was the original shift-on-negative-UTC-offset bug.

export function todayDateOnly(now: Date = new Date()): string {
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export function addCalendarDays(invDate: string, days: number): string {
  const [y, m, d] = String(invDate).split('-').map(Number);
  if (!y || !m || !d) return invDate;
  const dt = new Date(Date.UTC(y, m - 1, d + (Number.isFinite(days) ? days : 0)));
  const yy = dt.getUTCFullYear();
  const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(dt.getUTCDate()).padStart(2, '0');
  return `${yy}-${mm}-${dd}`;
}

export function diffCalendarDays(fromDate: string, toDate: string): number {
  const [fy, fm, fd] = String(fromDate).split('-').map(Number);
  const [ty, tm, td] = String(toDate).split('-').map(Number);
  if (!fy || !fm || !fd || !ty || !tm || !td) return 0;
  const ms = Date.UTC(ty, tm - 1, td) - Date.UTC(fy, fm - 1, fd);
  return Math.round(ms / 86400000);
}

// Approved quantity domain (issue #71 WRK-D / HR71-08): a product line
// quantity must be a finite number strictly greater than 0, with at most
// 4 decimal places. Blank / zero / negative / NaN / over-precision are
// invalid and must be surfaced, never silently coerced to 1.
export function isValidProductQuantity(raw: number | ''): boolean {
  if (raw === '' || !Number.isFinite(Number(raw))) return false;
  const num = Number(raw);
  if (num <= 0) return false;
  return Math.round(num * 10000) === num * 10000;
}
