import { type RefObject } from 'react';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { generateReportPDF } from '@/lib/pdfGenerator';
import { downloadCsv } from '@/lib/reportCsv';
import { cn } from '@/lib/utils';

interface ReportExportToolbarProps {
  /** Container holding the report (its first <table> is used for CSV/PDF). */
  targetRef: RefObject<HTMLElement | null>;
  title: string;
  filename: string;
  subtitle?: string;
  companyName?: string;
  asOfDate?: string;
  compareDate?: string;
  /** Optional explicit rows (header + data). Falls back to the DOM table. */
  rows?: string[][];
  className?: string;
}

const extractTableRows = (container: HTMLElement | null): string[][] => {
  if (!container) return [];
  const table = container.querySelector('table');
  if (!table) return [];
  const rows: string[][] = [];
  table.querySelectorAll('tr').forEach((tr) => {
    const cells = Array.from(tr.querySelectorAll('th, td')).map((cell) =>
      (cell.textContent ?? '').replace(/\s+/g, ' ').trim(),
    );
    if (cells.some((cell) => cell !== '')) rows.push(cells);
  });
  return rows;
};

/**
 * Report export/print actions (CSV, PDF, Print).
 *
 * Always rendered on the report itself so downloads are available whether or
 * not the comparison view is enabled — exports previously lived only inside
 * the comparison panel and silently disappeared without it.
 */
export function ReportExportToolbar({
  targetRef,
  title,
  filename,
  subtitle,
  companyName,
  asOfDate,
  compareDate,
  rows,
  className,
}: ReportExportToolbarProps) {
  const getRows = () => (rows && rows.length > 0 ? rows : extractTableRows(targetRef.current));

  const handleCsv = () => {
    const data = getRows();
    if (data.length === 0) return;
    downloadCsv(`${filename}.csv`, data, { title, subtitle, companyName, asOfDate, compareDate });
  };

  const handlePdf = async () => {
    if (!targetRef.current) return;
    await generateReportPDF(targetRef.current, {
      title,
      subtitle,
      companyName,
      asOfDate,
      compareDate,
      filename,
    });
  };

  return (
    <div className={cn('flex items-center gap-2', className)} data-no-export>
      <HeaderActionButton variant="outline" icon="solar:file-text-linear" onClick={handleCsv}>
        CSV
      </HeaderActionButton>
      <HeaderActionButton variant="outline" icon="solar:file-download-bold" onClick={handlePdf}>
        PDF
      </HeaderActionButton>
      <HeaderActionButton variant="outline" icon="solar:printer-linear" onClick={() => window.print()}>
        Print
      </HeaderActionButton>
    </div>
  );
}
