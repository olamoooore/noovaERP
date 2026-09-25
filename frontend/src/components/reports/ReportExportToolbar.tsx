import { useState, type RefObject } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import {
  companyBrandingQueryOptions,
  toCompanyBranding,
  useCompanyBranding,
} from '@/hooks/use-company-branding';
import { generateReportPDF, printReportPDF, type ReportPdfOptions } from '@/lib/pdfGenerator';
import { downloadCsv } from '@/lib/reportCsv';
import { cn } from '@/lib/utils';

interface ReportExportToolbarProps {
  /** Container holding the report (its first <table> is used for CSV/PDF). */
  targetRef: RefObject<HTMLElement | null>;
  title: string;
  filename: string;
  subtitle?: string;
  /** Overrides the tenant branding fetched automatically when omitted. */
  companyName?: string;
  companyLogo?: string;
  asOfDate?: string;
  compareDate?: string;
  /** Complete rows (header first) — keeps exports off the paginated DOM table. */
  rows?: string[][];
  /** Per-column alignment for the explicit rows. */
  columnAligns?: ReportPdfOptions['columnAligns'];
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
 * Print no longer calls window.print() (which dumped the whole SPA shell);
 * it renders the same clean report PDF as the PDF button and prints those
 * bytes through the browser's PDF viewer.
 */
export function ReportExportToolbar({
  targetRef,
  title,
  filename,
  subtitle,
  companyName,
  companyLogo,
  asOfDate,
  compareDate,
  rows,
  columnAligns,
  className,
}: ReportExportToolbarProps) {
  const [busy, setBusy] = useState<'pdf' | 'print' | null>(null);
  const queryClient = useQueryClient();
  // Fall back to the tenant's own logo/company name, never the platform brand.
  const branding = useCompanyBranding();

  /**
   * Resolve branding at click time: if the background query hasn't landed yet
   * (fast first click), await it so every export is branded.
   */
  const resolveBranding = async () => {
    let resolved = branding;
    if (!resolved.companyName && !resolved.companyLogo) {
      const data = await queryClient.ensureQueryData(companyBrandingQueryOptions);
      resolved = toCompanyBranding(data);
    }
    return {
      name: companyName ?? resolved.companyName,
      logo: companyLogo ?? resolved.companyLogo,
    };
  };

  const getRows = () => (rows && rows.length > 0 ? rows : extractTableRows(targetRef.current));

  const handleCsv = async () => {
    const data = getRows();
    if (data.length === 0) return;
    const { name } = await resolveBranding();
    downloadCsv(`${filename}.csv`, data, { title, subtitle, companyName: name, asOfDate, compareDate });
  };

  const handlePdf = async () => {
    if (!targetRef.current || busy) return;
    setBusy('pdf');
    try {
      const { name, logo } = await resolveBranding();
      await generateReportPDF(targetRef.current, {
        title,
        subtitle,
        companyName: name,
        companyLogo: logo,
        asOfDate,
        compareDate,
        filename,
        rows,
        columnAligns,
      });
    } finally {
      setBusy(null);
    }
  };

  const handlePrint = async () => {
    if (!targetRef.current || busy) return;
    setBusy('print');
    try {
      const { name, logo } = await resolveBranding();
      await printReportPDF(targetRef.current, {
        title,
        subtitle,
        companyName: name,
        companyLogo: logo,
        asOfDate,
        compareDate,
        filename,
        rows,
        columnAligns,
      });
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className={cn('flex items-center gap-2', className)} data-no-export>
      <HeaderActionButton
        variant="outline"
        icon="solar:file-text-linear"
        disabled={busy !== null}
        onClick={handleCsv}
      >
        CSV
      </HeaderActionButton>
      <HeaderActionButton
        variant="outline"
        icon="solar:file-download-bold"
        loading={busy === 'pdf'}
        disabled={busy !== null}
        onClick={handlePdf}
      >
        PDF
      </HeaderActionButton>
      <HeaderActionButton
        variant="outline"
        icon="solar:printer-linear"
        loading={busy === 'print'}
        disabled={busy !== null}
        onClick={handlePrint}
      >
        Print
      </HeaderActionButton>
    </div>
  );
}
