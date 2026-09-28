/**
 * Excel (.xlsx) and Word (.docx) report exports.
 *
 * The heavy generator libraries are imported dynamically so they only load
 * when a user actually clicks the corresponding export button. Both helpers
 * return the produced Blob (tests use this) and also trigger a browser
 * download when the environment supports it.
 */

export interface ReportFileOptions {
  filename: string
  title: string
  subtitle?: string
  /** Tenant company name printed above the table in the Word export. */
  companyName?: string;
}

const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

const triggerDownload = (blob: Blob, filename: string) => {
  if (typeof document === 'undefined' || typeof URL?.createObjectURL !== 'function') return;
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};

/** Pad/trim every row to the header width so both formats get a clean grid. */
const normalizeRows = (rows: string[][]): string[][] => {
  const width = Math.max(...rows.map((row) => row.length), 0);
  return rows.map((row) => Array.from({ length: width }, (_, index) => row[index] ?? ''));
};

export async function downloadReportXlsx(rows: string[][], options: ReportFileOptions): Promise<Blob | null> {
  if (!rows.length) return null;

  const XLSX = await import('xlsx');
  const grid = normalizeRows(rows);
  const worksheet = XLSX.utils.aoa_to_sheet(grid);

  // Sensible column widths so numbers and names are readable on open.
  worksheet['!cols'] = grid[0].map((_, column) => {
    const longest = grid.reduce(
      (max, row) => Math.max(max, String(row[column] ?? '').length),
      8,
    );
    return { wch: Math.min(45, longest + 2) };
  });

  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, worksheet, 'Report');
  const buffer = XLSX.write(workbook, { bookType: 'xlsx', type: 'array' }) as ArrayBuffer;
  const blob = new Blob([buffer], { type: XLSX_MIME });

  triggerDownload(blob, `${options.filename}.xlsx`);
  return blob;
}

export async function downloadReportDocx(rows: string[][], options: ReportFileOptions): Promise<Blob | null> {
  if (!rows.length) return null;

  const {
    AlignmentType,
    Document,
    HeadingLevel,
    Packer,
    Paragraph,
    Table,
    TableCell,
    TableRow,
    TextRun,
    WidthType,
  } = await import('docx');

  const grid = normalizeRows(rows);
  const columnWidth = Math.max(1, Math.floor(100 / grid[0].length));

  const cell = (text: string, bold: boolean) =>
    new TableCell({
      width: { size: columnWidth, type: WidthType.PERCENTAGE },
      children: [
        new Paragraph({
          children: [new TextRun({ text, bold, size: bold ? 18 : 17 })],
        }),
      ],
    });

  const table = new Table({
    width: { size: 100, type: WidthType.PERCENTAGE },
    rows: grid.map((row, rowIndex) =>
      new TableRow({
        tableHeader: rowIndex === 0,
        children: row.map((value) => cell(value, rowIndex === 0)),
      }),
    ),
  });

  const children = [
    ...(options.companyName
      ? [
          new Paragraph({
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: options.companyName, bold: true, size: 22 })],
          }),
        ]
      : []),
    new Paragraph({
      heading: HeadingLevel.HEADING_1,
      alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: options.title, bold: true })],
    }),
    ...(options.subtitle
      ? [
          new Paragraph({
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: options.subtitle, size: 18, color: '666666' })],
          }),
        ]
      : []),
    new Paragraph({ text: '' }),
    table,
  ];

  const document = new Document({ sections: [{ children }] });
  // Re-wrap so the MIME type is explicit regardless of the library default.
  const blob = new Blob([await Packer.toBlob(document)], { type: DOCX_MIME });

  triggerDownload(blob, `${options.filename}.docx`);
  return blob;
}
