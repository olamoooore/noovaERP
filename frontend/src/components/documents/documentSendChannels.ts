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
 *   quotation          -> POST /quotations/{id}/send (email, #334 — PDF +
 *                         public acceptance link)
 *
 * Sales orders and their order confirmation / pro-forma invoice (#405) have
 * no send path yet; it is to come from the shared send-and-accept service
 * (#394).
 */
export const PRINT_SEND_CHANNELS: Record<DocumentTemplateType, { email: boolean; whatsapp: boolean }> = {
  invoice: { email: true, whatsapp: true },
  delivery_order: { email: true, whatsapp: false },
  customer_statement: { email: true, whatsapp: true },
  quotation: { email: true, whatsapp: false },
  sales_order: { email: false, whatsapp: false },
  sales_order_confirmation: { email: false, whatsapp: false },
  sales_proforma_invoice: { email: false, whatsapp: false },
  credit_note: { email: false, whatsapp: false },
  receipt: { email: false, whatsapp: false },
}
