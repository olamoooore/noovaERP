import { useState } from 'react'
import { useParams, useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'

interface Invoice {
  id: number
  invoice_number: string
  customer_id: number
  customer?: {
    id: number
    code?: string
    name: string
    email: string
    phone: string
    billing_address: string
  }
  invoice_date: string
  due_date: string
  status: 'draft' | 'sent' | 'paid' | 'partial' | 'overdue' | 'cancelled'
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  paid_amount: number
  balance: number
  notes: string
  terms: string
  date_of_case?: string
  case_number?: string
  case_location?: string
  patient_name?: string
  cost_center_id?: string
  po_number?: string
  doctor?: { id: number; display_name?: string; full_name?: string; name?: string }
  requestor?: { id: number; display_name?: string; full_name?: string; name?: string }
  delivery_date?: string
  internal_reference?: string
  customer_reference?: string
  attention_to?: string
  contact_email?: string
  contact_phone?: string
  company_registration_number?: string
  tax_registration_number?: string
  billing_address_snapshot?: string
  shipping_address_snapshot?: string
  billing_contact?: { id: string; display_name?: string }
  shipping_contact?: { id: string; display_name?: string }
  created_at: string
  updated_at: string
  created_by?: {
    id: number
    name: string
  }
  lines: InvoiceLine[]
  payments: Payment[]
  sales_order?: { id: number; sales_order_number: string }
  quotation?: { id: number; quotation_number: string }
  delivery_orders?: { id: number; delivery_order_number: string; status: string }[]
}

interface InvoiceLine {
  id: number
  product_id: number
  product?: {
    id: number
    name: string
    sku: string
    manufacturer_part_number?: string
  }
  model_number?: string
  description: string
  quantity: number
  unit_price: number
  tax_rate: number
  tax_amount: number
  discount_amount: number
  line_total: number
}

interface Payment {
  id: number
  payment_number: string
  payment_date: string
  method: string
  amount: number
  reference: string
  notes: string
  created_at: string
}

// Helper function to format address (handles both string and JSON formats)
function formatAddress(address: string | null | undefined): string {
  if (!address) return '';
  
  try {
    // Try to parse as JSON
    const parsed = JSON.parse(address);
    const parts = [];
    
    if (parsed.first_name || parsed.last_name) {
      parts.push(`${parsed.first_name || ''} ${parsed.last_name || ''}`.trim());
    }
    if (parsed.address) parts.push(parsed.address);
    if (parsed.city) parts.push(parsed.city);
    if (parsed.state) parts.push(parsed.state);
    if (parsed.postal_code) parts.push(parsed.postal_code);
    if (parsed.country) parts.push(parsed.country);
    
    return parts.filter(Boolean).join('\n');
  } catch {
    // If not JSON, return as-is (plain text address)
    return address;
  }
}

export function InvoiceDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  const { toast } = useToast()

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`)
      return response.data as Invoice
    },
    enabled: !!id,
  })

  const getStatusBadge = (status: string) => {
    const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      sent: { variant: 'default', label: 'Sent' },
      paid: { variant: 'default', label: 'Paid' },
      partial: { variant: 'outline', label: 'Partial' },
      overdue: { variant: 'destructive', label: 'Overdue' },
      cancelled: { variant: 'destructive', label: 'Cancelled' },
    }
    const config = variants[status] || { variant: 'secondary', label: status }
    return <Badge variant={config.variant}>{config.label}</Badge>
  }

  const handlePrint = () => {
    window.print()
  }

  const handleDownload = async () => {
    try {
      const response = await api.get(`/invoices/${id}/pdf`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `invoice-${invoice?.invoice_number}.pdf`)
      document.body.appendChild(link)
      link.click()
      link.remove()
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Download failed',
        description: 'Could not download the PDF. Please try again.',
      })
    }
  }

  if (isLoading) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <div className="flex items-center justify-center h-64">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      </div>
    )
  }

  if (!invoice) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="material-symbols:receipt-long-off" className="h-16 w-16 text-muted-foreground mb-4" />
            <h2 className="text-xl font-semibold mb-2">Invoice Not Found</h2>
            <p className="text-muted-foreground mb-4">The invoice you're looking for doesn't exist or has been deleted.</p>
            <Button onClick={() => navigate('/invoices')}>
              <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
              Back to Invoices
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between print:hidden">
        <div className="flex items-center gap-4">
          <Button variant="outline" onClick={() => {
             if (location.state?.from) {
               navigate(location.state.from);
             } else {
               navigate('/invoices');
             }
          }}>
            <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
            Back
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Invoice {invoice.invoice_number}</h1>
            <p className="text-muted-foreground text-sm">
              Created on {formatDate(invoice.created_at)}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handlePrint}>
            <Icon icon="material-symbols:print" className="mr-2 h-4 w-4" />
            Print
          </Button>
          <Button variant="outline" onClick={handleDownload}>
            <Icon icon="material-symbols:download" className="mr-2 h-4 w-4" />
            Download PDF
          </Button>
          {['posted', 'sent', 'partial', 'overdue'].includes(invoice.status) && invoice.balance > 0 && (
            <Button onClick={() => navigate(`/invoices/${id}/payment`)}>
              <Icon icon="material-symbols:payment" className="mr-2 h-4 w-4" />
              Record Payment
            </Button>
          )}
        </div>
      </div>

      {/* Smart Buttons */}
      <div className="flex gap-3 print:hidden">
        {invoice.quotation && (
          <button
            onClick={() => navigate(`/quotations/${invoice.quotation!.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-purple-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{invoice.quotation.quotation_number}</div>
              <div className="text-xs text-muted-foreground">Quotation</div>
            </div>
          </button>
        )}
        {invoice.sales_order && (
          <button
            onClick={() => navigate(`/sales/orders/${invoice.sales_order!.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:cart-large-2-bold-duotone" className="h-5 w-5 text-green-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{invoice.sales_order.sales_order_number}</div>
              <div className="text-xs text-muted-foreground">Sales Order</div>
            </div>
          </button>
        )}
        {invoice.delivery_orders && invoice.delivery_orders.length > 0 && (
          <button
            onClick={() => navigate('/delivery-orders')}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:package-bold" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{invoice.delivery_orders.length}</div>
              <div className="text-xs text-muted-foreground">Delivery Order{invoice.delivery_orders.length !== 1 ? 's' : ''}</div>
            </div>
          </button>
        )}
      </div>

      {/* Invoice Content */}
      <Card className="print:shadow-none">
        <CardContent className="p-8">
          {/* Invoice Header */}
          <div className="flex justify-between items-start mb-8">
            <div>
              <h2 className="text-3xl font-bold mb-2">INVOICE</h2>
              <p className="text-muted-foreground">#{invoice.invoice_number}</p>
            </div>
            <div className="text-right">
              {getStatusBadge(invoice.status)}
              <div className="mt-4 space-y-1 text-sm">
                <div className="flex justify-between gap-8">
                  <span className="text-muted-foreground">Invoice Date:</span>
                  <span>{formatDate(invoice.invoice_date || invoice.created_at)}</span>
                </div>
                <div className="flex justify-between gap-8">
                  <span className="text-muted-foreground">Due Date:</span>
                  <span>{formatDate(invoice.due_date || invoice.invoice_date || invoice.created_at)}</span>
                </div>
              </div>
            </div>
          </div>

          {/* Bill To / From */}
          <div className="grid grid-cols-2 gap-8 mb-8">
            <div className="p-4 bg-muted/50 rounded-lg">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-2">Bill To</h3>
              {invoice.customer ? (
                <div className="space-y-1">
                  {invoice.customer.code && (
                    <p className="text-sm font-mono text-muted-foreground">{invoice.customer.code}</p>
                  )}
                  <p className="font-semibold text-lg">{invoice.customer.name}</p>
                  {invoice.customer.email && (
                    <p className="text-sm text-muted-foreground flex items-center gap-1">
                      <Icon icon="material-symbols:email" className="h-4 w-4" />
                      {invoice.customer.email}
                    </p>
                  )}
                  {invoice.customer.phone && (
                    <p className="text-sm text-muted-foreground flex items-center gap-1">
                      <Icon icon="material-symbols:phone" className="h-4 w-4" />
                      {invoice.customer.phone}
                    </p>
                  )}
                  {invoice.customer.billing_address && (
                    <p className="text-sm text-muted-foreground mt-2 whitespace-pre-line">
                      {formatAddress(invoice.customer.billing_address)}
                    </p>
                  )}
                </div>
              ) : (
                <p className="text-muted-foreground">Walk-in Customer</p>
              )}
            </div>
            <div className="p-4 bg-muted/50 rounded-lg">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-2">From</h3>
              <div className="space-y-1">
                <p className="font-semibold text-lg">NoovaERP</p>
                <p className="text-sm text-muted-foreground">Your Company Address</p>
                <p className="text-sm text-muted-foreground">contact@noovaerp.com</p>
              </div>
            </div>
          </div>

          {/* Invoice Lines */}
          <div className="mb-8">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoice.lines?.map((line) => (
                  <TableRow key={line.id}>
                    <TableCell>
                      <div>
                        <p className="font-medium">{line.description}</p>
                        {(line.model_number || line.product?.manufacturer_part_number || line.product?.sku) && (
                          <p className="text-xs text-muted-foreground">
                            {line.model_number ? `Model: ${line.model_number}` : line.product?.manufacturer_part_number ? `Part #: ${line.product.manufacturer_part_number}` : `SKU: ${line.product?.sku}`}
                          </p>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-right">{line.quantity}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.tax_amount)}</TableCell>
                    <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {/* Totals */}
          <div className="flex justify-end mb-8">
            <div className="w-80 space-y-2">
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(invoice.subtotal)}</span>
              </div>
              {invoice.tax_amount > 0 && (
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax</span>
                  <span>{formatCurrency(invoice.tax_amount)}</span>
                </div>
              )}
              {invoice.discount_amount > 0 && (
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Discount</span>
                  <span className="text-green-600">-{formatCurrency(invoice.discount_amount)}</span>
                </div>
              )}
              <Separator />
              <div className="flex justify-between text-lg font-bold">
                <span>Total</span>
                <span>{formatCurrency(invoice.total)}</span>
              </div>
              {invoice.paid_amount > 0 && (
                <>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Paid</span>
                    <span className="text-green-600">{formatCurrency(invoice.paid_amount)}</span>
                  </div>
                  <div className="flex justify-between text-sm font-semibold">
                    <span className="text-muted-foreground">Balance Due</span>
                    <span className={invoice.balance > 0 ? 'text-red-600' : 'text-green-600'}>
                      {formatCurrency(invoice.balance)}
                    </span>
                  </div>
                </>
              )}
            </div>
          </div>

          {/* Payment History */}
          {invoice.payments && invoice.payments.length > 0 && (
            <div className="mb-8">
              <h3 className="text-lg font-semibold mb-4">Payment History</h3>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Method</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoice.payments.map((payment) => (
                    <TableRow key={payment.id}>
                      <TableCell>{formatDate(payment.payment_date)}</TableCell>
                      <TableCell className="capitalize">{payment.method.replace('_', ' ')}</TableCell>
                      <TableCell>{payment.reference || '-'}</TableCell>
                      <TableCell className="text-right text-green-600">
                        {formatCurrency(payment.amount)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          {/* Case Information */}
          {(invoice.case_number || invoice.date_of_case || invoice.case_location || invoice.patient_name || invoice.cost_center_id || invoice.delivery_date || invoice.po_number || invoice.doctor || invoice.requestor) && (
            <div className="mt-6 pt-6 border-t">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-3">Case Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {invoice.case_number && (
                  <div>
                    <span className="text-xs text-muted-foreground">Case Number</span>
                    <p className="text-sm font-medium">{invoice.case_number}</p>
                  </div>
                )}
                {invoice.date_of_case && (
                  <div>
                    <span className="text-xs text-muted-foreground">Date of Case</span>
                    <p className="text-sm font-medium">{formatDate(invoice.date_of_case)}</p>
                  </div>
                )}
                {invoice.case_location && (
                  <div>
                    <span className="text-xs text-muted-foreground">Case Location</span>
                    <p className="text-sm font-medium">{invoice.case_location}</p>
                  </div>
                )}
                {invoice.patient_name && (
                  <div>
                    <span className="text-xs text-muted-foreground">Patient Name</span>
                    <p className="text-sm font-medium">{invoice.patient_name}</p>
                  </div>
                )}
                {invoice.cost_center_id && (
                  <div>
                    <span className="text-xs text-muted-foreground">Cost Center</span>
                    <p className="text-sm font-medium">{invoice.cost_center_id}</p>
                  </div>
                )}
                {invoice.delivery_date && (
                  <div>
                    <span className="text-xs text-muted-foreground">Delivery Date</span>
                    <p className="text-sm font-medium">{formatDate(invoice.delivery_date)}</p>
                  </div>
                )}
                {invoice.po_number && (
                  <div>
                    <span className="text-xs text-muted-foreground">PO Number</span>
                    <p className="text-sm font-medium">{invoice.po_number}</p>
                  </div>
                )}
                {invoice.doctor && (
                  <div>
                    <span className="text-xs text-muted-foreground">Doctor</span>
                    <p className="text-sm font-medium">{invoice.doctor.display_name || invoice.doctor.full_name || invoice.doctor.name || '-'}</p>
                  </div>
                )}
                {invoice.requestor && (
                  <div>
                    <span className="text-xs text-muted-foreground">Requestor</span>
                    <p className="text-sm font-medium">{invoice.requestor.display_name || invoice.requestor.full_name || invoice.requestor.name || '-'}</p>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Commercial Snapshot */}
          {(invoice.customer_reference || invoice.internal_reference || invoice.attention_to || invoice.contact_email || invoice.contact_phone || invoice.company_registration_number || invoice.tax_registration_number || invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
            <div className="mt-6 pt-6 border-t">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-3">Commercial Snapshot</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {invoice.customer_reference && (
                  <div>
                    <span className="text-xs text-muted-foreground">Customer Reference</span>
                    <p className="text-sm font-medium">{invoice.customer_reference}</p>
                  </div>
                )}
                {invoice.internal_reference && (
                  <div>
                    <span className="text-xs text-muted-foreground">Internal Reference</span>
                    <p className="text-sm font-medium">{invoice.internal_reference}</p>
                  </div>
                )}
                {invoice.attention_to && (
                  <div>
                    <span className="text-xs text-muted-foreground">Attention To</span>
                    <p className="text-sm font-medium">{invoice.attention_to}</p>
                  </div>
                )}
                {invoice.contact_email && (
                  <div>
                    <span className="text-xs text-muted-foreground">Contact Email</span>
                    <p className="text-sm font-medium">{invoice.contact_email}</p>
                  </div>
                )}
                {invoice.contact_phone && (
                  <div>
                    <span className="text-xs text-muted-foreground">Contact Phone</span>
                    <p className="text-sm font-medium">{invoice.contact_phone}</p>
                  </div>
                )}
                {invoice.company_registration_number && (
                  <div>
                    <span className="text-xs text-muted-foreground">Company Registration</span>
                    <p className="text-sm font-medium">{invoice.company_registration_number}</p>
                  </div>
                )}
                {invoice.tax_registration_number && (
                  <div>
                    <span className="text-xs text-muted-foreground">Tax Registration</span>
                    <p className="text-sm font-medium">{invoice.tax_registration_number}</p>
                  </div>
                )}
              </div>
              {(invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                  {invoice.billing_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-xs text-muted-foreground mb-1">Billing Address {invoice.billing_contact ? `(Contact: ${invoice.billing_contact.display_name})` : ''}</p>
                      <p className="text-sm whitespace-pre-wrap">{formatAddress(invoice.billing_address_snapshot)}</p>
                    </div>
                  )}
                  {invoice.shipping_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-xs text-muted-foreground mb-1">Delivery Address {invoice.shipping_contact ? `(Contact: ${invoice.shipping_contact.display_name})` : ''}</p>
                      <p className="text-sm whitespace-pre-wrap">{formatAddress(invoice.shipping_address_snapshot)}</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Notes & Terms */}
          {(invoice.notes || invoice.terms) && (
            <div className="grid grid-cols-2 gap-8">
              {invoice.notes && (
                <div>
                  <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-2">Notes</h3>
                  <p className="text-sm whitespace-pre-wrap">{invoice.notes}</p>
                </div>
              )}
              {invoice.terms && (
                <div>
                  <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-2">Terms & Conditions</h3>
                  <p className="text-sm whitespace-pre-wrap">{invoice.terms}</p>
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Footer */}
      <div className="text-center text-sm text-muted-foreground print:hidden">
        <p>Thank you for your business!</p>
      </div>
    </div>
  )
}
