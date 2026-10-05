import type { DocumentTemplateType } from './DocumentTemplateDialog'

/**
 * Issue #618 — the deliverable template types and the send channels that
 * actually exist server-side for each one. A type with no send endpoint hides
 * that action rather than faking it.
 *
 *   invoice            -> POST /invoices/{id}/deliver (email | whatsapp)
 *   delivery_order     -> POST /sales/delivery-orders/{id}/customer-link (email)
 *   customer_statement -> POST /accounting/customer-statements/send
 *                         (email | whatsapp, #607 Sales statement surface)
 */
export const PRINT_SEND_CHANNELS: Record<DocumentTemplateType, { email: boolean; whatsapp: boolean }> = {
  invoice: { email: true, whatsapp: true },
  delivery_order: { email: true, whatsapp: false },
  customer_statement: { email: true, whatsapp: true },
  quotation: { email: false, whatsapp: false },
  sales_order: { email: false, whatsapp: false },
  credit_note: { email: false, whatsapp: false },
  receipt: { email: false, whatsapp: false },
}
