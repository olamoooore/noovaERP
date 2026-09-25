/**
 * Notification display text.
 *
 * Older rows were stored with a raw JSON payload as the body
 * ("Event: invoice.customer_accepted\n\n{...}") before the backend started
 * humanising them. The bell and notifications page rendered the stored text
 * verbatim, so those legacy rows showed JSON. This derives readable text from
 * the event + `data` payload whenever the stored body still contains JSON.
 */

export interface NotificationTextInput {
  event?: string | null;
  subject?: string | null;
  body?: string | null;
  data?: Record<string, any> | null;
}

export interface NotificationText {
  title: string;
  body: string;
}

const humaniseEvent = (event?: string | null) =>
  String(event ?? '')
    .replace(/[._]/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());

const isRawJsonBody = (body?: string | null) => {
  if (!body) return false;
  const trimmed = body.trim();
  // The legacy fallback body was "Event: <event>" followed by a pretty JSON
  // object — detect that rather than valid prose that merely contains braces.
  return trimmed.startsWith('Event:') && /[{[]/.test(trimmed);
};

export function notificationText(item: NotificationTextInput): NotificationText {
  const data = item.data ?? {};
  const event = String(item.event ?? '');
  const number = data.invoice_number ?? data.document_number;
  const amount = data.amount ?? data.total_amount;

  if (!isRawJsonBody(item.body)) {
    return {
      title: item.subject || humaniseEvent(event),
      body: item.body || humaniseEvent(event),
    };
  }

  // Legacy JSON body — rebuild readable text from the event + payload.
  switch (event) {
    case 'invoice.customer_accepted':
    case 'invoice.accepted': {
      const signer = data.signer_name ?? data.customer_name ?? 'The customer';
      const email = data.signer_email ? ` (${data.signer_email})` : '';
      return {
        title: number ? `Invoice ${number} accepted by the customer` : 'Invoice accepted by the customer',
        body: `${signer}${email} accepted invoice ${number ?? ''}${amount ? ` for ${amount}` : ''}.`.replace('  ', ' ').trim(),
      };
    }
    case 'invoice.payment_proof_uploaded':
    case 'invoice.evidence_uploaded':
    case 'invoice.evidence_uploaded_by_customer': {
      const file = data.file_name ?? 'a document';
      return {
        title: number ? `Payment proof uploaded for invoice ${number}` : 'Payment proof uploaded',
        body: `${data.customer_name ?? data.signer_name ?? 'The customer'} uploaded ${file}${number ? ` against invoice ${number}` : ''}.`,
      };
    }
    default: {
      // Generic cleanup: drop the JSON blob and render readable "Label: value" lines.
      const lines = Object.entries(data)
        .filter(([key, value]) => !['subject', 'body'].includes(key) && value !== null && value !== '')
        .map(([key, value]) => `${humaniseEvent(key)}: ${Array.isArray(value) ? value.join(', ') : String(value)}`);

      return {
        title: item.subject || humaniseEvent(event),
        body: [humaniseEvent(event), ...lines].join('\n'),
      };
    }
  }
}
