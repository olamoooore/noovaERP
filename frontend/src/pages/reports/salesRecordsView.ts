import type {
  SalesRecordsData,
  SalesRecordsMonthTotal,
  SalesRecordsRow,
} from '@/types/reports';

/**
 * #1020: pure view logic for the Sales Records sheet — interleaving the
 * monthly total rows and flattening the report for CSV/PDF export.
 */

/** Sheet style: whole dollars unless cents exist. */
export const salesRecordsMoney = (value: number) =>
  Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export const salesRecordsQty = (value: number | undefined) =>
  Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 });

export type SalesRecordsDisplayItem =
  | { kind: 'row'; row: SalesRecordsRow }
  | { kind: 'total'; total: SalesRecordsMonthTotal };

/**
 * Rows stay chronological; after each month's rows the matching "{Month}"
 * total row is inserted (the sheet's "May Total" line).
 */
export function buildSalesRecordsDisplayItems(
  rows: SalesRecordsRow[],
  monthTotals: SalesRecordsMonthTotal[],
): SalesRecordsDisplayItem[] {
  const totalsByMonth = new Map(monthTotals.map((month) => [month.month, month]));
  const items: SalesRecordsDisplayItem[] = [];
  let currentMonth: string | null = null;

  for (const row of rows) {
    if (currentMonth !== row.month) {
      if (currentMonth) {
        const total = totalsByMonth.get(currentMonth);
        if (total) items.push({ kind: 'total', total });
      }
      currentMonth = row.month;
    }
    items.push({ kind: 'row', row });
  }

  if (currentMonth) {
    const total = totalsByMonth.get(currentMonth);
    if (total) items.push({ kind: 'total', total });
  }

  return items;
}

/** Header-first matrix for ReportExportToolbar (CSV/PDF/Excel/Word). */
export function buildSalesRecordsExportRows(data: SalesRecordsData | undefined): string[][] {
  if (!data) return [];

  const columns = data.columns;
  const items = buildSalesRecordsDisplayItems(data.rows, data.month_totals);

  const header = [
    'Customer Name',
    'Date of Invoice/DO',
    'Invoice/DO Number',
    'Lot Number',
    ...columns.flatMap((column) => [`${column.label} Qty`, `${column.label} S$`]),
    'Total S$',
    'Bonusing Scheme',
    'Due Date',
    'Status',
  ];
  const rows: string[][] = [header];

  for (const item of items) {
    if (item.kind === 'row') {
      rows.push([
        item.row.customer_name,
        item.row.date,
        item.row.document_number,
        item.row.lot_number,
        ...columns.flatMap((column) => {
          const value = item.row.values[column.key];
          return [salesRecordsQty(value?.quantity), salesRecordsMoney(value?.value ?? 0)];
        }),
        salesRecordsMoney(item.row.total),
        item.row.bonus_scheme ?? '',
        item.row.due_date ?? '',
        item.row.status,
      ]);
    } else {
      rows.push([
        `${item.total.label} Total`,
        '',
        '',
        '',
        ...columns.flatMap((column) => {
          const value = item.total.values[column.key];
          return [salesRecordsQty(value?.quantity), salesRecordsMoney(value?.value ?? 0)];
        }),
        salesRecordsMoney(item.total.total),
        '',
        '',
        '',
      ]);
    }
  }

  return rows;
}
