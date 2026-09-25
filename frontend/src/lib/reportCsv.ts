export type CsvMeta = {
  title: string;
  subtitle?: string;
  companyName?: string;
  asOfDate?: string;
  compareDate?: string;
};

const csvCell = (value: unknown) => `"${String(value ?? '').replace(/"/g, '""')}"`;

/**
 * A report-shaped CSV: a title/context block (report, company, period, run
 * time), a blank line, then the table. A UTF-8 BOM keeps Excel/Sheets from
 * mangling currency symbols. Plain CSV cannot carry fonts/colours, so this
 * block is what makes the download read like a report instead of a bare dump.
 */
export const toReportCsv = (rows: string[][], meta: CsvMeta): string => {
  const head: string[][] = [[meta.title]];
  if (meta.companyName) head.push([meta.companyName]);
  if (meta.subtitle) head.push([meta.subtitle]);

  const period: string[] = [];
  if (meta.asOfDate) period.push(`As of: ${meta.asOfDate}`);
  if (meta.compareDate) period.push(`Compare: ${meta.compareDate}`);
  if (period.length) head.push([period.join('    ')]);

  head.push([`Generated: ${new Date().toLocaleString()}`]);
  head.push([]);

  const body = [...head, ...rows];
  return '\uFEFF' + body.map((row) => row.map(csvCell).join(',')).join('\r\n');
};

export const downloadCsv = (filename: string, rows: string[][], meta: CsvMeta): void => {
  const blob = new Blob([toReportCsv(rows, meta)], { type: 'text/csv;charset=utf-8;' });
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('download', filename);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};
