import { useState } from 'react'
import { useParams, useNavigate, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import { SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ChatterDock } from '@/components/chatter'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'

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
  pr_no?: string
  contract_no?: string
  buyer?: { id: string; display_name?: string; full_name?: string; name?: string }
  plant_code?: string
  company_code?: string
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
  requires_approval?: boolean
  approval_status?: 'not_required' | 'pending' | 'approved' | 'rejected' | 'expired' | 'withdrawn'
  approval_request_id?: number
}

interface InvoiceLine {
  id: number
  line_type?: 'product' | 'note' | 'section'
  product_id: number
  product?: {
    id: number
    name: string
    sku: string
    manufacturer_part_number?: string
  }
  model_number?: string
  product_notes?: string | null
  lot_number?: string | null
  expiry_date?: string | null
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
  const isAcmeTenant = useAuthStore((state) => state.tenantId) === '00000000-0000-0000-0000-000000000001'
  const queryClient = useQueryClient()
  const [templateDialogOpen, setTemplateDialogOpen] = useState(false)
  const [selectedTemplateId, setSelectedTemplateId] = useState('')
  const [isDownloadingPdf, setIsDownloadingPdf] = useState(false)
  const [chatterOpen, setChatterOpen] = useState(false)
  const { data: pdfTemplates = [], isLoading: templatesLoading } = useQuery({ queryKey: ['invoice-pdf-templates'], queryFn: async () => (await api.get('/template-builder/templates', { params: { document_type: 'invoice', status: 'published', per_page: 100 } })).data?.data || [], enabled: templateDialogOpen })

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`)
      return response.data as Invoice
    },
    enabled: !!id,
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/send`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      toast({ title: 'Invoice posted successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const submitForApprovalMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/submit-approval`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      toast({ title: 'Invoice submitted for approval' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to submit for approval',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const toggleRequiresApprovalMutation = useMutation({
    mutationFn: async (value: boolean) => {
      const response = await api.put(`/invoices/${id}`, { requires_approval: value })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      toast({ title: 'Invoice updated' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const sendInvoiceMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/acceptance-link`, {
        send_email: true,
        document_type: 'final_invoice',
      })
      return response.data
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      const url = data?.data?.url
      setSendDialogUrl(url ?? null)
      toast({
        title: 'Invoice sent to customer',
        description: url ? 'Acceptance link generated and emailed.' : 'Email sent with invoice PDF.',
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to send invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const [sendDialogOpen, setSendDialogOpen] = useState(false)
  const [sendDialogUrl, setSendDialogUrl] = useState<string | null>(null)

  const getStatusBadge = (status: string, approvalStatus?: string) => {
    const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      sent: { variant: 'default', label: 'Sent' },
      paid: { variant: 'default', label: 'Paid' },
      partial: { variant: 'outline', label: 'Partial' },
      overdue: { variant: 'destructive', label: 'Overdue' },
      cancelled: { variant: 'destructive', label: 'Cancelled' },
    }

    // Show approval badge overlay if approval is pending
    if (approvalStatus === 'pending') {
      return <Badge variant="outline" className="border-orange-500 text-orange-600">Pending Approval</Badge>
    }
    if (approvalStatus === 'approved') {
      return <Badge variant="default" className="bg-green-600">Approved</Badge>
    }
    if (approvalStatus === 'rejected') {
      return <Badge variant="destructive">Rejected</Badge>
    }

    const config = variants[status] || { variant: 'secondary', label: status }
    return <Badge variant={config.variant}>{config.label}</Badge>
  }

  const handlePrint = () => {
    window.print()
  }

  const handleDownload = async () => {
    if (!selectedTemplateId) return
    setIsDownloadingPdf(true)
    try {
      const response = await api.get(`/invoices/${id}/pdf`, { params: { template: selectedTemplateId }, responseType: 'blob' })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `invoice-${invoice?.invoice_number}.pdf`)
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTemplateDialogOpen(false)
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Download failed',
        description: 'Could not download the PDF. Please try again.',
      })
    } finally { setIsDownloadingPdf(false) }
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
      <div className="print:hidden">
        <Button variant="ghost" size="sm" onClick={() => {
           if (location.state?.from) {
             navigate(location.state.from);
           } else if (window.history.length > 1) {
             navigate(-1);
           } else {
             navigate('/invoices');
           }
        }} className="mb-3">
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Invoice {invoice.invoice_number}</h1>
            <p className="text-muted-foreground text-sm">
              Created on {formatDate(invoice.created_at)}
            </p>
          </div>
          <div className="flex items-center gap-3">
            {invoice.status === 'draft' && !invoice.requires_approval && (
              <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={!!invoice.requires_approval}
                  onChange={(e) => toggleRequiresApprovalMutation.mutate(e.target.checked)}
                  disabled={toggleRequiresApprovalMutation.isPending || invoice.approval_status === 'pending'}
                  className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                />
                <span className="text-muted-foreground">Requires Approval</span>
              </label>
            )}
            {invoice.status === 'draft' && invoice.requires_approval && invoice.approval_status !== 'pending' && (
              <Button
                variant="outline"
                onClick={() => submitForApprovalMutation.mutate()}
                disabled={submitForApprovalMutation.isPending}
              >
                {submitForApprovalMutation.isPending ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-current mr-2"></div>
                ) : (
                  <Icon icon="solar:send-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Send for Approval
              </Button>
            )}
            {invoice.status === 'draft' && (
              <Button
                onClick={() => postMutation.mutate()}
                disabled={postMutation.isPending}
              >
                {postMutation.isPending ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                ) : (
                  <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                )}
                Post Invoice
              </Button>
            )}
            {['posted', 'sent', 'partial', 'overdue', 'paid'].includes(invoice.status) && (
              <Button
                variant="outline"
                onClick={() => {
                  setSendDialogUrl(null)
                  setSendDialogOpen(true)
                }}
                disabled={sendInvoiceMutation.isPending}
              >
                {sendInvoiceMutation.isPending ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-current mr-2"></div>
                ) : (
                  <Icon icon="solar:letter-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Send Invoice
              </Button>
            )}
            <Button variant="outline" onClick={handlePrint}>
              <Icon icon="material-symbols:print" className="mr-2 h-4 w-4" />
              Print
            </Button>
            <Button variant="outline" onClick={() => setTemplateDialogOpen(true)}>
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
        {invoice.delivery_orders && invoice.delivery_orders.length > 0 && invoice.delivery_orders.map((d) => (
          <button
            key={d.id}
            onClick={() => navigate(`/delivery-orders/${d.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:package-bold" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{d.delivery_order_number}</div>
              <div className="text-xs text-muted-foreground">Delivery Order</div>
            </div>
          </button>
        ))}
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
              {getStatusBadge(invoice.status, invoice.approval_status)}
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
                  {(invoice.billing_address_snapshot || invoice.customer.billing_address) && (
                    <p className="text-sm text-muted-foreground mt-2 whitespace-pre-line">
                      {formatAddress(invoice.billing_address_snapshot || invoice.customer.billing_address)}
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
                <p className="font-semibold text-lg">Acme Pte Ltd</p>
                <p className="text-sm text-muted-foreground">#01-36, 50 Faber Walk</p>
                <p className="text-sm text-muted-foreground">Singapore 128994</p>
              </div>
            </div>
          </div>

          {/* Invoice Lines */}
          <div className="mb-8">
            <SalesLineColumnVisibility tableClass="invoice-detail-columns" columns={[
              'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Lot Number', 'Expiry Date', 'Qty', 'Unit Price', 'Tax', 'Total',
            ].map((label, index) => ({ label, index: index + 1 }))}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Model #</TableHead>
                  <TableHead>Product Notes</TableHead>
                  <TableHead>Lot Number</TableHead>
                  <TableHead>Expiry Date</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoice.lines?.map((line, index) => (
                  <TableRow key={line.id}>
                    {line.line_type === 'note' || line.line_type === 'section' ? <TableCell colSpan={11} className={line.line_type === 'section' ? 'bg-muted/60 font-semibold' : 'bg-muted/30 italic text-muted-foreground'}><div className="flex items-center justify-between gap-4"><span>{line.description}</span>{line.line_type === 'section' && <span className="whitespace-nowrap tabular-nums">{formatCurrency(getDocumentSectionTotal(invoice.lines || [], index, (item: any) => Number(item.line_total || 0)))}</span>}</div></TableCell> : <>
                    <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                    <TableCell>{line.description || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.lot_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.expiry_date ? formatDate(line.expiry_date) : '-'}</TableCell>
                    <TableCell className="text-right">{line.quantity}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.tax_amount)}</TableCell>
                    <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                    </>}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            </SalesLineColumnVisibility>
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

          <Dialog open={templateDialogOpen} onOpenChange={setTemplateDialogOpen}>
            <DialogContent><DialogHeader><DialogTitle>Download Invoice PDF</DialogTitle><DialogDescription>Select the template to use.</DialogDescription></DialogHeader>
              <Select value={selectedTemplateId} onValueChange={setSelectedTemplateId} disabled={templatesLoading}><SelectTrigger><SelectValue placeholder={templatesLoading ? 'Loading templates...' : 'Select a template'} /></SelectTrigger><SelectContent>{pdfTemplates.map((t: any) => <SelectItem key={t.id} value={String(t.id)}>{t.name}{t.is_system_default ? ' (Default)' : ''}</SelectItem>)}</SelectContent></Select>
              <DialogFooter><Button variant="outline" onClick={() => setTemplateDialogOpen(false)}>Cancel</Button><Button onClick={handleDownload} disabled={!selectedTemplateId || isDownloadingPdf || templatesLoading}>{isDownloadingPdf ? 'Downloading...' : 'Download'}</Button></DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={sendDialogOpen} onOpenChange={setSendDialogOpen}>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Send invoice to customer</DialogTitle>
                <DialogDescription>
                  We will email the customer the invoice PDF along with a secure acceptance link — no login required — so they can review, sign, pay, and track status.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-3 py-2">
                <div className="flex items-start gap-3 rounded-md border bg-muted/30 p-3">
                  <Icon icon="solar:user-circle-bold-duotone" className="mt-0.5 h-5 w-5 text-primary" />
                  <div className="text-sm">
                    <div className="font-medium">{invoice.customer?.name}</div>
                    <div className="text-muted-foreground">{invoice.customer?.email || 'No email on file'}</div>
                  </div>
                </div>
                <ul className="text-xs text-muted-foreground space-y-1">
                  <li>• Invoice PDF will be attached to the email</li>
                  <li>• Customer gets a unique signed acceptance link (no login)</li>
                  <li>• Customer can sign digitally and view payment options</li>
                  <li>• You will see delivery + acceptance status on the timeline</li>
                </ul>
                {sendDialogUrl && (
                  <div className="rounded-md border border-green-200 bg-green-50 p-3 dark:border-green-900 dark:bg-green-950/40">
                    <div className="text-xs font-medium text-green-700 dark:text-green-400">Acceptance link</div>
                    <a
                      href={sendDialogUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="break-all text-xs text-green-700 underline dark:text-green-300"
                    >
                      {sendDialogUrl}
                    </a>
                  </div>
                )}
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setSendDialogOpen(false)}>Cancel</Button>
                <Button
                  onClick={() => sendInvoiceMutation.mutate()}
                  disabled={sendInvoiceMutation.isPending || !invoice.customer?.email}
                >
                  {sendInvoiceMutation.isPending ? (
                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                  ) : (
                    <Icon icon="solar:plain-2-bold-duotone" className="mr-2 h-4 w-4" />
                  )}
                  Send to customer
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

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
          {(invoice.case_number || invoice.pr_no || invoice.contract_no || invoice.buyer || invoice.plant_code || invoice.company_code || invoice.date_of_case || invoice.case_location || invoice.patient_name || invoice.cost_center_id || invoice.delivery_date || invoice.po_number || invoice.doctor || invoice.requestor) && (
            <div className="mt-6 pt-6 border-t">
              <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-3">Case Information</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {invoice.case_number && (
                  <div>
                    <span className="text-xs text-muted-foreground">Case Number</span>
                    <p className="text-sm font-medium">{invoice.case_number}</p>
                  </div>
                )}
                {invoice.pr_no && <div><span className="text-xs text-muted-foreground">PR No</span><p className="text-sm font-medium">{invoice.pr_no}</p></div>}
                {invoice.contract_no && <div><span className="text-xs text-muted-foreground">Contract No</span><p className="text-sm font-medium">{invoice.contract_no}</p></div>}
                {invoice.buyer && <div><span className="text-xs text-muted-foreground">Buyer</span><p className="text-sm font-medium">{invoice.buyer.display_name || invoice.buyer.full_name || invoice.buyer.name || '-'}</p></div>}
                {invoice.plant_code && <div><span className="text-xs text-muted-foreground">Plant Code</span><p className="text-sm font-medium">{invoice.plant_code}</p></div>}
                {invoice.company_code && <div><span className="text-xs text-muted-foreground">Company Code</span><p className="text-sm font-medium">{invoice.company_code}</p></div>}
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
          {(invoice.customer_reference || invoice.internal_reference || invoice.attention_to || invoice.contact_email || invoice.contact_phone || invoice.company_registration_number || invoice.tax_registration_number || invoice.billing_contact || invoice.shipping_contact || invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
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
                {invoice.billing_contact && (
                  <div>
                    <span className="text-xs text-muted-foreground">Billing Contact</span>
                    <p className="text-sm font-medium">{invoice.billing_contact.display_name || '-'}</p>
                  </div>
                )}
                {invoice.shipping_contact && (
                  <div>
                    <span className="text-xs text-muted-foreground">Shipping Contact</span>
                    <p className="text-sm font-medium">{invoice.shipping_contact.display_name || '-'}</p>
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
                      <p className="text-xs text-muted-foreground mb-1">Billing Address</p>
                      <p className="text-sm whitespace-pre-wrap">{formatAddress(invoice.billing_address_snapshot)}</p>
                    </div>
                  )}
                  {invoice.shipping_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-xs text-muted-foreground mb-1">Delivery Address</p>
                      <p className="text-sm whitespace-pre-wrap">{formatAddress(invoice.shipping_address_snapshot)}</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Notes & Terms */}
          {(invoice.terms || (!isAcmeTenant && invoice.notes)) && (
            <div className="grid grid-cols-2 gap-8">
              {!isAcmeTenant && invoice.notes && (
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

      {/* Odoo-style inline Chatter — sticky at bottom of viewport, click header to expand */}
      <ChatterDock
        subjectType={String.raw`App\Models\Invoice`}
        subjectId={id as string}
        documentLabel={invoice.invoice_number}
        open={chatterOpen}
        onOpenChange={setChatterOpen}
      />
    </div>
  )
}
