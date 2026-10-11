import html2pdf from 'html2pdf.js';
import { printPdfBlob } from './printPdf';

export interface ReportPdfOptions {
  title: string;
  subtitle?: string;
  companyName?: string;
  companyLogo?: string;
  asOfDate?: string;
  compareDate?: string;
  filename: string;
  /**
   * Complete rows (header first). When omitted, the DOM table inside the
   * target element is used — that table may only hold the current page.
   */
  rows?: string[][];
  /** Per-column alignment for explicit rows; inferred from the DOM otherwise. */
  columnAligns?: ColumnAlign[];
}

type ColumnAlign = 'left' | 'right' | 'center';

interface ExtractedTable {
  aligns: ColumnAlign[];
  rows: { header: boolean; cells: string[] }[];
}

const cleanText = (value: string | null) => (value ?? '').replace(/\s+/g, ' ').trim();

/**
 * Pull the report's first table into plain rows + per-column alignment.
 *
 * The PDF is built from this data instead of screenshotting the live DOM:
 * cloning the on-screen card (dark-mode classes, overflow wrappers, sticky
 * headers, fixed pixel widths) produced clipped, low-quality PDFs.
 */
const extractTable = (container: HTMLElement): ExtractedTable | null => {
  const table = container.querySelector('table');
  if (!table) return null;

  const aligns: ColumnAlign[] = Array.from(table.querySelectorAll('thead th')).map((th) =>
    th.className.includes('text-right')
      ? 'right'
      : th.className.includes('text-center')
        ? 'center'
        : 'left',
  );

  const rows: { header: boolean; cells: string[] }[] = [];
  table.querySelectorAll('tr').forEach((tr) => {
    const cells = Array.from(tr.querySelectorAll('th, td')).map((cell) => cleanText(cell.textContent));
    if (cells.some(Boolean)) rows.push({ header: !!tr.querySelector('th'), cells });
  });

  return rows.length > 0 ? { aligns, rows } : null;
};

const make = (tag: string, style: string, text?: string) => {
  const node = document.createElement(tag);
  node.style.cssText = style;
  if (text !== undefined) node.textContent = text;
  return node;
};

/**
 * Preload the logo before rendering. html2canvas silently drops images that
 * fail to load, which left the PDF header blank; on failure we fall back to
 * the tenant company name instead.
 */
const loadImage = (src: string): Promise<boolean> =>
  new Promise((resolve) => {
    const image = new Image();
    image.crossOrigin = 'anonymous';
    const finish = (ok: boolean) => resolve(ok);
    image.onload = () => finish(true);
    image.onerror = () => finish(false);
    window.setTimeout(() => finish(false), 4000);
    image.src = src;
  });

const buildReportDocument = async (
  container: HTMLElement,
  options: ReportPdfOptions,
): Promise<HTMLElement> => {
  const { title, subtitle, companyName, companyLogo, asOfDate, compareDate } = options;

  const wrapper = make(
    'div',
    'font-family:Arial,Helvetica,sans-serif;color:#111827;background:#ffffff;padding:4px 2px;max-width:780px;margin:0 auto;',
  );

  // ── Header ────────────────────────────────────────────────────────────────
  const header = make(
    'div',
    'display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:3px solid #2E8B74;padding-bottom:14px;margin-bottom:16px;',
  );

  // Tenant branding: logo when it actually loads, otherwise the tenant company
  // name. Never the platform brand — NoovaERP is the ERP, not the issuer.
  const logoOk = companyLogo ? await loadImage(companyLogo) : false;

  const left = make('div', 'min-width:160px;');
  if (logoOk && companyLogo) {
    const logo = document.createElement('img');
    logo.src = companyLogo;
    logo.style.cssText = 'height:64px;max-width:280px;object-fit:contain;margin-bottom:8px;';
    left.appendChild(logo);
  } else if (companyName) {
    left.appendChild(make('div', 'font-size:20px;font-weight:bold;color:#2E8B74;margin-top:6px;', companyName));
  }
  header.appendChild(left);

  const right = make('div', 'text-align:right;');
  right.appendChild(make('div', 'font-size:15px;font-weight:bold;color:#111827;margin-bottom:4px;', title));
  if (subtitle) right.appendChild(make('div', 'font-size:11px;color:#4b5563;margin-bottom:2px;', subtitle));
  if (asOfDate) right.appendChild(make('div', 'font-size:11px;color:#4b5563;', `As of: ${asOfDate}`));
  if (compareDate) right.appendChild(make('div', 'font-size:11px;color:#4b5563;', `Comparison: ${compareDate}`));
  header.appendChild(right);
  wrapper.appendChild(header);

  // ── Table ─────────────────────────────────────────────────────────────────
  const explicitRows = options.rows && options.rows.length > 0 ? options.rows : null;
  const extracted = explicitRows
    ? {
        aligns: options.columnAligns ?? [],
        rows: explicitRows.map((cells, index) => ({ header: index === 0, cells })),
      }
    : extractTable(container);
  if (extracted) {
    const columnCount = Math.max(...extracted.rows.map((row) => row.cells.length));
    const fontSize = columnCount > 7 ? '8.5px' : columnCount > 5 ? '9.5px' : '10.5px';

    const table = make('table', `width:100%;border-collapse:collapse;font-size:${fontSize};`);
    const thead = document.createElement('thead');
    const tbody = document.createElement('tbody');

    extracted.rows.forEach((row) => {
      const tr = document.createElement('tr');
      row.cells.forEach((value, index) => {
        const cell = document.createElement(row.header ? 'th' : 'td');
        const align = extracted.aligns[index] ?? 'left';
        cell.style.cssText = row.header
          ? 'padding:6px 7px;text-align:left;background:#f3f4f6;font-weight:bold;color:#111827;border-bottom:2px solid #d1d5db;'
          : `padding:5px 7px;text-align:${align};border-bottom:1px solid #e5e7eb;${
              align === 'right' ? 'white-space:nowrap;' : 'overflow-wrap:anywhere;'
            }`;
        cell.textContent = value;
        tr.appendChild(cell);
      });
      (row.header ? thead : tbody).appendChild(tr);
    });

    table.appendChild(thead);
    if (tbody.childNodes.length > 0) table.appendChild(tbody);
    wrapper.appendChild(table);
  }

  // ── Footer ────────────────────────────────────────────────────────────────
  const footer = make(
    'div',
    'display:flex;justify-content:space-between;gap:12px;margin-top:18px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:9px;color:#9ca3af;',
  );
  footer.appendChild(make('span', '', `Generated: ${new Date().toLocaleString()}`));
  if (companyName) footer.appendChild(make('span', '', companyName));
  wrapper.appendChild(footer);

  return wrapper;
};

const buildPdfOptions = (filename: string) => ({
  margin: [10, 10, 12, 10] as [number, number, number, number],
  filename: `${filename}.pdf`,
  image: { type: 'jpeg' as const, quality: 0.98 },
  html2canvas: {
    scale: 2,
    useCORS: true,
    letterRendering: true,
    backgroundColor: '#ffffff',
    logging: false,
  },
  jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' as const },
  pagebreak: { mode: ['css', 'legacy'], avoid: 'tr' },
});

/** Build the report PDF and return it as a Blob (used for download + print). */
export async function generateReportPdfBlob(
  container: HTMLElement,
  options: ReportPdfOptions,
): Promise<Blob> {
  const wrapper = await buildReportDocument(container, options);
  return html2pdf().set(buildPdfOptions(options.filename)).from(wrapper).output('blob');
}

/** Download the report as PDF. */
export async function generateReportPDF(
  container: HTMLElement,
  options: ReportPdfOptions,
): Promise<void> {
  const blob = await generateReportPdfBlob(container, options);

  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.setAttribute('download', `${options.filename}.pdf`);
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}

/** Print the report (the generated PDF, not the whole SPA window). */
export async function printReportPDF(
  container: HTMLElement,
  options: ReportPdfOptions,
): Promise<void> {
  const blob = await generateReportPdfBlob(container, options);
  printPdfBlob(blob);
}
