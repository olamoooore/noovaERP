import { Navigate, useParams } from 'react-router-dom'

/**
 * #487 (PAY-UX1): the standalone "Record Payment" page is retired. Its URL
 * (/invoices/:id/payment) stays as a redirect to the invoice detail page,
 * which opens the shared RegisterPaymentDialog for that invoice.
 */
export function InvoicePaymentPage() {
  const { id } = useParams<{ id: string }>()

  return <Navigate to={id ? `/invoices/${id}?register_payment=1` : '/invoices'} replace />
}
