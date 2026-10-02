/**
 * Shared, framework-agnostic document-total maths for sales documents
 * (invoices + quotations).
 *
 * This mirrors the backend `Invoice::calculateTotals()` EXACTLY so the figure
 * shown on screen always matches the figure the server persists. The important
 * detail is the ORDER of operations: a document-level discount is applied
 * BEFORE tax and the line tax is then pro-rated by the taxable ratio
 * (taxable / subtotal), rounded to 2 decimal places — never "subtotal + tax −
 * discount" (issue #289).
 *
 * Backend reference formula:
 *   subtotal   = Σ (line_total − tax_amount)            // net of per-line discount
 *   lineTaxSum = Σ tax_amount
 *   discount   = document-level discount_amount
 *   if discount > 0 and subtotal > 0:
 *       taxableAmount = max(subtotal − discount, 0)
 *       taxRatio      = taxableAmount / subtotal
 *       taxAmount     = round(lineTaxSum × taxRatio, 2)
 *   else:
 *       taxAmount     = lineTaxSum
 *   total   = subtotal − discount + taxAmount
 */

/** A number that may be empty (`''`) or null while a field is being typed. */
export type NumericInput = number | '' | null | undefined;

/**
 * Minimal line shape the maths needs. Both the invoice and quotation line
 * editors build rows with (at least) these fields; extra fields are ignored.
 * Note/section lines are excluded from the product totals via `line_type`.
 */
export interface DocumentTotalsLine {
  line_type?: 'product' | 'note' | 'section' | string;
  quantity?: NumericInput;
  unit_price?: NumericInput;
  tax_rate?: NumericInput;
  discount_amount?: NumericInput;
}

/**
 * The document-level discount. Either a resolved fixed amount (mirrors the
 * backend's `discount_amount`), or the raw entry mode + value from the editor
 * (percentage is resolved against the net subtotal, matching the server).
 */
export type DocumentDiscountInput =
  | number
  | {
      mode?: 'fixed' | 'percentage';
      /** Raw entered value: a currency amount when fixed, a percent when percentage. */
      amount?: NumericInput;
    };

export interface DocumentTotals {
  /** Σ qty × unit_price over product lines, BEFORE any discount. */
  grossSubtotal: number;
  /** Σ per-line discount_amount over product lines. */
  lineDiscounts: number;
  /** Net subtotal = grossSubtotal − lineDiscounts (the server's `subtotal`). */
  subtotal: number;
  /** Σ per-line tax (on the per-line-discounted base), BEFORE doc discount. */
  lineTaxSum: number;
  /** The resolved + clamped document discount actually applied. */
  documentDiscountAmount: number;
  /** Pro-rated, 2dp-rounded tax after the document discount. */
  taxTotal: number;
  /** subtotal − documentDiscountAmount + taxTotal. */
  total: number;
}

const toNumber = (value: NumericInput): number => {
  if (value === '' || value === null || value === undefined) return 0;
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
};

const round2 = (value: number): number => Math.round((value + Number.EPSILON) * 100) / 100;

const isProductLine = (line: DocumentTotalsLine): boolean => {
  const type = line.line_type ?? 'product';
  return type !== 'note' && type !== 'section';
};

/**
 * Compute the on-screen document totals. Pure: no React, no side effects.
 *
 * @param lines    Product/note/section rows (note/section are ignored).
 * @param discount Either a resolved fixed amount, or `{ mode, amount }` from the
 *                 discount input. Percentage is applied to the net subtotal.
 */
export function computeDocumentTotals(
  lines: DocumentTotalsLine[],
  discount: DocumentDiscountInput = 0,
): DocumentTotals {
  const productLines = (lines ?? []).filter(isProductLine);

  const grossSubtotal = productLines.reduce(
    (sum, line) => sum + toNumber(line.quantity) * toNumber(line.unit_price),
    0,
  );
  const lineDiscounts = productLines.reduce(
    (sum, line) => sum + toNumber(line.discount_amount),
    0,
  );
  const subtotal = grossSubtotal - lineDiscounts;
  const lineTaxSum = productLines.reduce((sum, line) => {
    const base = toNumber(line.quantity) * toNumber(line.unit_price) - toNumber(line.discount_amount);
    return sum + base * (toNumber(line.tax_rate) / 100);
  }, 0);

  // Resolve the requested document discount (fixed amount or percentage of the
  // net subtotal), then clamp into [0, subtotal] exactly as the server does.
  let requested: number;
  if (typeof discount === 'number') {
    requested = Number.isFinite(discount) ? discount : 0;
  } else {
    const amount = toNumber(discount?.amount);
    requested = discount?.mode === 'percentage' ? subtotal * (amount / 100) : amount;
  }
  const documentDiscountAmount = Math.min(Math.max(requested, 0), Math.max(subtotal, 0));

  const taxTotal =
    documentDiscountAmount > 0 && subtotal > 0
      ? round2(lineTaxSum * (Math.max(subtotal - documentDiscountAmount, 0) / subtotal))
      : lineTaxSum;

  const total = subtotal - documentDiscountAmount + taxTotal;

  return {
    grossSubtotal,
    lineDiscounts,
    subtotal,
    lineTaxSum,
    documentDiscountAmount,
    taxTotal,
    total,
  };
}
