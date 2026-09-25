import html2pdf from 'html2pdf.js';

interface PDFOptions {
  title: string;
  subtitle?: string;
  companyName?: string;
  companyLogo?: string;
  asOfDate?: string;
  compareDate?: string;
  filename: string;
}

export function generateReportPDF(
  tableElement: HTMLElement,
  options: PDFOptions
) {
  const {
    title,
    subtitle,
    companyName = 'NoovaERP',
    companyLogo,
    asOfDate,
    compareDate,
    filename,
  } = options;

  // Create a wrapper div for the PDF content
  const wrapper = document.createElement('div');
  wrapper.style.fontFamily = 'Arial, sans-serif';
  wrapper.style.padding = '40px';
  wrapper.style.maxWidth = '800px';
  wrapper.style.margin = '0 auto';

  // Header section
  const header = document.createElement('div');
  header.style.display = 'flex';
  header.style.justifyContent = 'space-between';
  header.style.alignItems = 'center';
  header.style.marginBottom = '30px';
  header.style.paddingBottom = '20px';
  header.style.borderBottom = '3px solid #2563eb';

  // Company info (left side)
  const companyInfo = document.createElement('div');
  if (companyLogo) {
    const logo = document.createElement('img');
    logo.src = companyLogo;
    logo.style.height = '50px';
    logo.style.marginBottom = '10px';
    companyInfo.appendChild(logo);
  }
  const companyNameEl = document.createElement('h2');
  companyNameEl.textContent = companyName;
  companyNameEl.style.margin = '0';
  companyNameEl.style.fontSize = '24px';
  companyNameEl.style.fontWeight = 'bold';
  companyNameEl.style.color = '#1e40af';
  companyInfo.appendChild(companyNameEl);
  header.appendChild(companyInfo);

  // Report info (right side)
  const reportInfo = document.createElement('div');
  reportInfo.style.textAlign = 'right';

  const titleEl = document.createElement('h1');
  titleEl.textContent = title;
  titleEl.style.margin = '0 0 8px 0';
  titleEl.style.fontSize = '20px';
  titleEl.style.fontWeight = 'bold';
  titleEl.style.color = '#111827';
  reportInfo.appendChild(titleEl);

  if (subtitle) {
    const subtitleEl = document.createElement('p');
    subtitleEl.textContent = subtitle;
    subtitleEl.style.margin = '0 0 8px 0';
    subtitleEl.style.fontSize = '14px';
    subtitleEl.style.color = '#6b7280';
    reportInfo.appendChild(subtitleEl);
  }

  if (asOfDate) {
    const dateEl = document.createElement('p');
    dateEl.textContent = `As of: ${asOfDate}`;
    dateEl.style.margin = '0';
    dateEl.style.fontSize = '14px';
    dateEl.style.color = '#374151';
    reportInfo.appendChild(dateEl);
  }

  if (compareDate) {
    const compareDateEl = document.createElement('p');
    compareDateEl.textContent = `Comparison: ${compareDate}`;
    compareDateEl.style.margin = '4px 0 0 0';
    compareDateEl.style.fontSize = '14px';
    compareDateEl.style.color = '#374151';
    reportInfo.appendChild(compareDateEl);
  }

  header.appendChild(reportInfo);
  wrapper.appendChild(header);

  // Clone the table element
  const tableClone = tableElement.cloneNode(true) as HTMLElement;

  // Drop controls (export/expand buttons) so they never render inside the PDF.
  tableClone.querySelectorAll('[data-no-export]').forEach((el) => el.remove());

  // Style the table for PDF
  const table = tableClone.querySelector('table') || tableClone;
  if (table instanceof HTMLElement) {
    table.style.width = '100%';
    table.style.borderCollapse = 'collapse';
    table.style.fontSize = '12px';
  }

  // Style all cells
  const cells = tableClone.querySelectorAll('th, td');
  cells.forEach((cell) => {
    const el = cell as HTMLElement;
    el.style.padding = '8px 12px';
    el.style.borderBottom = '1px solid #e5e7eb';
    el.style.textAlign = 'left';

    // Right-align numeric columns
    if (el.classList.contains('text-right') || el.classList.contains('font-mono')) {
      el.style.textAlign = 'right';
    }
  });

  // Style header row
  const headerCells = tableClone.querySelectorAll('th');
  headerCells.forEach((cell) => {
    const el = cell as HTMLElement;
    el.style.backgroundColor = '#f3f4f6';
    el.style.fontWeight = 'bold';
    el.style.color = '#111827';
    el.style.borderBottom = '2px solid #d1d5db';
  });

  // Style bold rows
  const boldRows = tableClone.querySelectorAll('.font-bold, [class*="font-bold"]');
  boldRows.forEach((row) => {
    const el = row as HTMLElement;
    el.style.fontWeight = 'bold';
    el.style.backgroundColor = '#f9fafb';
  });

  // Style section header rows
  const sectionHeaders = tableClone.querySelectorAll('[class*="bg-blue-50"], [class*="bg-primary"]');
  sectionHeaders.forEach((row) => {
    const el = row as HTMLElement;
    el.style.backgroundColor = '#eff6ff';
    el.style.fontWeight = 'bold';
  });

  wrapper.appendChild(tableClone);

  // Footer
  const footer = document.createElement('div');
  footer.style.marginTop = '30px';
  footer.style.paddingTop = '20px';
  footer.style.borderTop = '1px solid #e5e7eb';
  footer.style.display = 'flex';
  footer.style.justifyContent = 'space-between';
  footer.style.fontSize = '10px';
  footer.style.color = '#9ca3af';

  const generatedAt = document.createElement('span');
  generatedAt.textContent = `Generated: ${new Date().toLocaleString()}`;
  footer.appendChild(generatedAt);

  const pageNumber = document.createElement('span');
  pageNumber.textContent = 'NoovaERP - Financial Reporting';
  footer.appendChild(pageNumber);

  wrapper.appendChild(footer);

  // PDF options
  const pdfOptions = {
    margin: [10, 10, 10, 10],
    filename: `${filename}.pdf`,
    image: { type: 'jpeg', quality: 0.98 },
    html2canvas: {
      scale: 2,
      useCORS: true,
      letterRendering: true,
    },
    jsPDF: {
      unit: 'mm',
      format: 'a4',
      orientation: 'portrait' as const,
    },
    pagebreak: { mode: ['avoid-all', 'css', 'legacy'] },
  };

  // Generate PDF
  return html2pdf().set(pdfOptions).from(wrapper).save();
}

// Helper to extract comparison table data for PDF
export function extractComparisonTableData(
  comparisonTableRef: HTMLElement | null
): HTMLElement | null {
  if (!comparisonTableRef) return null;

  // Find the table element within the comparison table
  const table = comparisonTableRef.querySelector('table');
  return table || comparisonTableRef;
}
