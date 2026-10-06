import { describe, expect, it } from 'vitest';
import { invoiceContactPayload } from './invoiceContactPayload';

describe('invoiceContactPayload (#300)', () => {
  it('sends a picked customer contact as its numeric id, never as a name', () => {
    const payload = invoiceContactPayload('billing', '42');
    expect(payload).toEqual({ billing_contact_id: null, billing_customer_contact_id: 42 });
    expect(Object.keys(payload).some((key) => key.endsWith('_name'))).toBe(false);
  });

  it('keeps an existing core contact UUID as the contact id', () => {
    const uuid = '0b7c1e1a-3f7e-4c1a-9a51-2f0e7c1b9d10';
    expect(invoiceContactPayload('doctor', uuid)).toEqual({
      doctor_contact_id: uuid,
      doctor_customer_contact_id: null,
    });
  });

  it('sends nothing for an empty selection', () => {
    expect(invoiceContactPayload('buyer', '')).toEqual({
      buyer_contact_id: null,
      buyer_customer_contact_id: null,
    });
  });
});
