/**
 * #300: the invoice form's contact pickers hold either a numeric
 * customer-contact id (picked from the customer's contact list) or a core
 * contact UUID (loaded from an existing invoice). Send the matching stable id
 * so the server never has to resolve a contact by name.
 */
export type InvoiceContactRole = 'billing' | 'shipping' | 'doctor' | 'requestor' | 'buyer';

export function invoiceContactPayload(role: InvoiceContactRole, value: string | null | undefined) {
  const raw = (value ?? '').trim();
  const isCoreContactUuid = raw.includes('-');
  const customerContactId = !isCoreContactUuid && /^\d+$/.test(raw) ? Number(raw) : null;

  return {
    [`${role}_contact_id`]: isCoreContactUuid ? raw : null,
    [`${role}_customer_contact_id`]: customerContactId,
  } as Record<string, string | number | null>;
}
