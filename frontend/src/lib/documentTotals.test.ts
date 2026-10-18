import { describe, expect, it } from 'vitest';
import { computeDocumentTotals } from './documentTotals';

describe('issue #289 — document totals mirror Invoice::calculateTotals()', () => {
  it('applies the document discount BEFORE tax: subtotal 100, 10% tax, discount 10 → total 99.00', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 1, unit_price: 100, tax_rate: 10, discount_amount: '' }],
      10,
    );
    expect(totals.subtotal).toBe(100);
    expect(totals.taxTotal).toBe(9); // 10 × (90/100)
    expect(totals.total).toBe(99);
  });

  it('matches for the percentage entry mode (10% of net subtotal)', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 1, unit_price: 100, tax_rate: 10 }],
      { mode: 'percentage', amount: 10 },
    );
    expect(totals.documentDiscountAmount).toBe(10);
    expect(totals.total).toBe(99);
  });

  it('with no document discount tax is the raw per-line tax sum (no pro-rating)', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 2, unit_price: 50, tax_rate: 10 }],
      0,
    );
    expect(totals.subtotal).toBe(100);
    expect(totals.taxTotal).toBe(10);
    expect(totals.total).toBe(110);
  });

  it('nets per-line discounts into the subtotal before the document discount', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 1, unit_price: 100, tax_rate: 10, discount_amount: 20 }],
      10,
    );
    expect(totals.grossSubtotal).toBe(100);
    expect(totals.lineDiscounts).toBe(20);
    expect(totals.subtotal).toBe(80);
    // lineTaxSum = (100-20)*0.1 = 8; ratio = (80-10)/80; tax = round(8*70/80,2)=7
    expect(totals.taxTotal).toBe(7);
    expect(totals.total).toBe(77);
  });

  it('ignores note/section lines', () => {
    const totals = computeDocumentTotals(
      [
        { line_type: 'section', description: 'Group A', quantity: 0, unit_price: 0 } as any,
        { line_type: 'product', quantity: 1, unit_price: 100, tax_rate: 10 },
        { line_type: 'note', quantity: 0, unit_price: 0 } as any,
      ],
      10,
    );
    expect(totals.total).toBe(99);
  });

  it('clamps an over-large document discount to the subtotal', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 1, unit_price: 100, tax_rate: 10 }],
      500,
    );
    expect(totals.documentDiscountAmount).toBe(100);
    expect(totals.taxTotal).toBe(0);
    expect(totals.total).toBe(0);
  });

  it('treats blank numeric inputs as zero', () => {
    const totals = computeDocumentTotals(
      [{ line_type: 'product', quantity: 1, unit_price: '', tax_rate: '', discount_amount: '' }],
      '' as any,
    );
    expect(totals.total).toBe(0);
  });
});
