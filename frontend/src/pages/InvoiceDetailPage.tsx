import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate, useLocation, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { sendBundleToastDescription } from '@/lib/sendBundle'
import { cn, formatCurrency, formatDate } from '@/lib/utils'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { ChatterDock } from '@/components/chatter'
import { AuditTab } from '@/components/audit/AuditTab'
import { InvoiceJournalTab } from '@/components/accounting/InvoiceJournalTab'
import { InvoiceWriteOffDialog } from '@/components/accounting/InvoiceWriteOffDialog'
import { EInvoiceReadinessPanel } from '@/components/accounting/EInvoiceReadinessPanel'
import { StockMovesTab } from '@/components/inventory/StockMovesTab'
import { StockCogsBadge } from '@/components/inventory/StockCogsBadge'
import { TagEditor } from '@/components/tags/TagEditor'
import { DocumentLinkChip } from '@/components/documents/DocumentLinkChip'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { PageHeader } from '@/components/layout/PageHeader'
import api from '@/lib/api'
import { getVisibleInvoiceNotes } from '@/lib/invoicePresentation'
import { useAuthStore } from '@/store/authStore'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Textarea } from '@/components/ui/textarea'
import { RegisterPaymentDialog } from '@/components/payment/RegisterPaymentDialog'
import { UnappliedCreditsCard } from '@/components/payment/PaymentAllocations'
import { OutstandingCreditNotesCard } from '@/components/payment/OutstandingCreditNotes'
import { isInvoicePayable, useCanRegisterPayment } from '@/lib/registerPayment'
import {
  InvoiceDeliveryProgressSummary,
  InvoiceLineDeliveryChip,
  type InvoiceDeliveryProgress,
} from '@/components/sales/DocumentProgressChip'
import { creditWarning } from '@/lib/creditCheck'

interface Invoice {
  id: number
  invoice_number: string
  /** Issue #331: a down-payment invoice (held in Customer Deposit). */
  is_down_payment?: boolean
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
  // Issue #324: master payment term + instalment schedule.
  payment_term?: { id: number; name: string } | null
  instalments?: Array<{
    sequence: number
    due_date: string | null
    percent: number
    amount: number
    paid: number
    outstanding: number
  }>
  status: 'draft' | 'posted' | 'sent' | 'paid' | 'partial' | 'overdue' | 'cancelled' | 'written_off'
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  paid_amount: number
  balance: number
  // Issue #879/#886: bad-debt write-offs on this invoice, exposed by the
  // detail endpoint so the page can chip and link each journal entry.
  written_off_amount?: number
  write_offs?: Array<{
    id: number
    invoice_id: number
    amount: number
    recovered_amount: number
    reason: string
    account_id: number
    journal_entry_id: number | null
    created_by: number | null
    created_at: string
    updated_at: string
  }>
  // MRD pricelist-multicurrency FR-5.1/5.4: document currency + snapshot.
  currency_code?: string | null
  // #875: the invoice's legal entity (#495 offers only same-entity credit).
  legal_entity_id?: number | null
  base_currency_code?: string | null
  exchange_rate?: number | string | null
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
  // Issue #688 (INV-UX2): "Delivered x/y", header and per line.
  delivery_progress?: InvoiceDeliveryProgress | null
  requires_approval?: boolean
  approval_status?: 'not_required' | 'pending' | 'approved' | 'rejected' | 'expired' | 'withdrawn'
  approval_request_id?: number
  can_approve?: boolean
  latest_rejection?: {
    id: number
    request_number: string
    reason: string | null
    reviewed_by: string | null
    reviewed_at: string | null
  } | null
  evidence?: Array<{
    id: number
    type: string
    file_name: string | null
    file_path: string | null
    mime_type: string | null
    size_bytes: number | null
    notes: string | null
    signer_name: string | null
    signer_email: string | null
    signed_at: string | null
    confirmed_at: string | null
    confirmed_by?: { id: number; name: string } | null
    created_at: string
  }>
  evidence_confirmed?: boolean
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
  /** Issue #331: set on a final invoice's "Less: down payment" line. */
  down_payment_invoice_id?: number | null
  /** Issue #332: revenue split across analytic accounts (totals 100%). */
  analytic_distribution?: Array<{
    analytic_account_id: number
    percentage: number
    code?: string | null
    name?: string | null
  }> | null
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

/**
 * Issue #328: whether the invoice has an overdue open balance — the invoice
 * due date, or (instalment terms) any open instalment's due date, is past.
 * The server re-checks with the follow-up report's overdue set.
 */
function isInvoiceOverdue(
  invoice: Pick<Invoice, 'status' | 'balance' | 'due_date' | 'instalments'>,
  today: string = new Date().toISOString().slice(0, 10),
): boolean {
  if (!['posted', 'sent', 'partial', 'overdue'].includes(invoice.status) || !(Number(invoice.balance) > 0)) return false
  const open = (invoice.instalments ?? []).filter((row) => Number(row.outstanding) > 0)
  if (open.length > 1) return open.some((row) => !!row.due_date && row.due_date.slice(0, 10) < today)
  return !!invoice.due_date && invoice.due_date.slice(0, 10) < today
}

export function InvoiceDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  const { toast } = useToast()
  const isAcmeTenant = useAuthStore((state) => state.tenantId) === '00000000-0000-0000-0000-000000000001'
  const role = useAuthStore((state) => state.role)
  const { hasPermission } = useAuthStore()
  // Posting to the GL is an accounting action — sales may create/approve/send,
  // never post.
  const canPost = hasPermission('accounting.post')
  const queryClient = useQueryClient()
  const [chatterOpen, setChatterOpen] = useState(false)

  const { data: invoice, isLoading } = useQuery({
    queryKey: ['invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`)
      return response.data as Invoice
    },
    enabled: !!id,
  })

  // D7: pre-flight checklist for the Post badge (drafts only).
  const { data: preflight } = useQuery<{
    ready: boolean
    passed: number
    total: number
    checks: Array<{ key: string; label: string; status: 'pass' | 'warn' | 'fail'; detail: string }>
  }>({
    queryKey: ['invoice-preflight', id],
    queryFn: async () => (await api.get(`/invoices/${id}/preflight`)).data,
    enabled: !!id && invoice?.status === 'draft',
  })

  // Document-currency primary; base equivalent at the snapshot rate (FR-5.4).
  const docCurrency = invoice?.currency_code || undefined
  const money = (value: number) => formatCurrency(value, docCurrency)
  const baseCurrency = invoice?.base_currency_code || undefined
  const fxRate = Number(invoice?.exchange_rate ?? 1) || 1
  const showBaseEquivalent = Boolean(docCurrency && baseCurrency && docCurrency !== baseCurrency)
  const baseMoney = (value: number) => formatCurrency(value * fxRate, baseCurrency)

  const submitApprovalMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/submit-approval`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['approvals'] })
      toast({ title: 'Invoice submitted for approval', description: 'Reviewers have been notified.' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to submit for approval',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const withdrawApprovalMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/withdraw-approval`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['approvals'] })
      toast({ title: 'Approval request withdrawn' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to withdraw approval',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      // GL posting endpoint (accounting.post). The legacy /send route posts too
      // and is gated identically server-side.
      const response = await api.post(`/invoices/${id}/post`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoice-preflight', id] })
      // Issue #326: a post under the `warn` credit policy carries the verdict.
      toast({ title: 'Invoice posted successfully', description: creditWarning(data) })
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'An error occurred'
      const awaitingApproval =
        error.response?.data?.code === 'APPROVAL_REQUIRED' ||
        /awaiting approval|requires approval/i.test(message)
      toast({
        variant: awaitingApproval ? 'default' : 'destructive',
        title: awaitingApproval ? 'Invoice awaiting approval' : 'Failed to post invoice',
        description: awaitingApproval
          ? 'This invoice needs to be approved before it can be posted.'
          : message,
      })
    },
  })

  const createDeliveryOrderMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/create-delivery-order`)
      return response.data
    },
    onSuccess: (data) => {
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'This invoice must be approved before it can be delivered.',
        })
        return
      }
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      const deliveryOrderId = data?.data?.id
      toast({ title: 'Delivery order created from invoice' })
      if (deliveryOrderId) {
        navigate(`/delivery-orders/${deliveryOrderId}`)
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create delivery order',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      const response = await api.delete(`/invoices/${id}`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast({ title: 'Invoice deleted' })
      setDeleteDialogOpen(false)
      navigate('/invoices')
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not delete invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const resetToDraftMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/reset-to-draft`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast({
        title: 'Invoice reset to draft',
        description: 'The ledger entry was reversed and stock restored.',
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not reset to draft',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  // D8: one-click Send bundle — acceptance link, pay-now link (when payable)
// and the PDF all go out in a single email with a payment QR.
  const sendInvoiceMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/send-bundle`, {
        recipient_email: sendRecipientEmail.trim() || undefined,
        save_customer_email: saveCustomerEmail || undefined,
      })
      return response.data
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      const url = data?.data?.url
      setSendDialogUrl(url ?? null)
      const paymentUrl = data?.data?.payment_url
      setSendDialogPaymentUrl(paymentUrl ?? null)
      toast({
        title: 'Invoice sent to customer',
        // #345: only what was actually included, plus why anything was skipped.
        description: sendBundleToastDescription(data),
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

  // #618: the shared print/send menu reuses the existing invoice delivery
  // service for its Email and WhatsApp actions (channel-selectable endpoint).
  const deliverInvoiceMutation = useMutation({
    mutationFn: async (channel: 'email' | 'whatsapp') => {
      const response = await api.post(`/invoices/${id}/deliver`, { channel })
      return response.data
    },
    onSuccess: (_data, channel) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      toast({
        title: channel === 'whatsapp' ? 'Invoice sent via WhatsApp' : 'Invoice emailed to the customer',
      })
    },
    onError: (error: any, channel) => {
      toast({
        variant: 'destructive',
        title: channel === 'whatsapp' ? 'Failed to send via WhatsApp' : 'Failed to email invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  // Issue #328: manual follow-up reminder for an overdue invoice. The
  // server picks the follow-up level/template and honours the customer's
  // communication preferences (a skip comes back with its reason).
  const reminderMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/invoices/${id}/followup-reminder`)
      return response.data
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      const sent = data?.data?.outcome === 'sent'
      toast({
        variant: sent ? 'default' : 'destructive',
        title: sent ? 'Payment reminder sent' : 'Reminder not sent',
        description: sent ? undefined : data?.data?.reason ?? data?.message,
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Reminder not sent',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const [sendDialogOpen, setSendDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [resetDialogOpen, setResetDialogOpen] = useState(false)
  const [writeOffDialogOpen, setWriteOffDialogOpen] = useState(false)
  // #491: register a payment in place (existing dialog + POST /payments).
  // #487: the retired /invoices/:id/payment page redirects here with
  // ?register_payment=1 — open the shared dialog once, then drop the flag.
  const [searchParams, setSearchParams] = useSearchParams()
  const [registerPaymentOpen, setRegisterPaymentOpen] = useState(() => searchParams.get('register_payment') === '1')
  useEffect(() => {
    if (searchParams.get('register_payment') !== '1') return
    const next = new URLSearchParams(searchParams)
    next.delete('register_payment')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])
  const canRegisterPayment = useCanRegisterPayment()
  const [sendDialogUrl, setSendDialogUrl] = useState<string | null>(null)
  const [sendDialogPaymentUrl, setSendDialogPaymentUrl] = useState<string | null>(null)
  const [sendRecipientEmail, setSendRecipientEmail] = useState('')
  const [saveCustomerEmail, setSaveCustomerEmail] = useState(false)
  const [approvalComment, setApprovalComment] = useState('')

  const approveMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/approvals/${invoice?.approval_request_id}/approve`, {
        notes: approvalComment,
      })
      return response.data
    },
    onSuccess: () => {
      setApprovalComment('')
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast({ title: 'Invoice approved', description: 'It can now be posted.' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not approve',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const declineMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/approvals/${invoice?.approval_request_id}/reject`, {
        reason: approvalComment,
      })
      return response.data
    },
    onSuccess: () => {
      setApprovalComment('')
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast({ title: 'Invoice declined', description: 'The submitter has been notified.' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not decline',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleDecline = () => {
    if ((approvalComment.trim().length || 0) < 10) {
      toast({
        variant: 'destructive',
        title: 'A reason is required',
        description: 'Please add a comment (at least 10 characters) explaining the decline.',
      })
      return
    }
    declineMutation.mutate()
  }

  const confirmEvidenceMutation = useMutation({
    mutationFn: async (evidenceId: number) => {
      const response = await api.post(`/invoices/${id}/evidence/${evidenceId}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      toast({ title: 'Evidence confirmed', description: 'The customer’s evidence has been verified.' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not confirm evidence',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const [viewingEvidence, setViewingEvidence] = useState<number | null>(null)

  const viewEvidence = async (evidenceId: number) => {
    setViewingEvidence(evidenceId)
    try {
      const response = await api.get(`/invoices/${id}/evidence/${evidenceId}/download`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60_000)
    } catch {
      toast({ variant: 'destructive', title: 'Could not open the evidence file' })
    } finally {
      setViewingEvidence(null)
    }
  }

  const getStatusBadge = (status: string, approvalStatus?: string) => {
    const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      sent: { variant: 'default', label: 'Sent' },
      paid: { variant: 'default', label: 'Paid' },
      partial: { variant: 'outline', label: 'Partial' },
      overdue: { variant: 'destructive', label: 'Overdue' },
      written_off: { variant: 'outline', label: 'Written off' },
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

  const visibleNotes = getVisibleInvoiceNotes(invoice.notes)

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="print:hidden">
        <PageHeader
          title={`Invoice ${invoice.invoice_number}`}
          description={`Created on ${formatDate(invoice.created_at)}`}
          onBack={() => {
            if (location.state?.from) {
              navigate(location.state.from)
            } else if (window.history.length > 1) {
              navigate(-1)
            } else {
              navigate('/invoices')
            }
          }}
          actions={<>
            {/* Send first, subtle green — the most common customer action. */}
            {((invoice.status === 'draft' && invoice.approval_status === 'approved') ||
              ['posted', 'sent', 'partial', 'overdue', 'paid'].includes(invoice.status)) && (
              <HeaderActionButton
                variant="outline"
                icon="solar:letter-bold-duotone"
                className="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800"
                loading={sendInvoiceMutation.isPending}
                onClick={() => {
                  setSendDialogUrl(null)
                  setSendDialogPaymentUrl(null)
                  setSendRecipientEmail(invoice.customer?.email ?? '')
                  setSaveCustomerEmail(false)
                  setSendDialogOpen(true)
                }}
                disabled={sendInvoiceMutation.isPending}
              >
                Send
              </HeaderActionButton>
            )}
            {/* An invoice may be delivered across several delivery orders
                (split shipments); the action stays available. */}
            {!['cancelled', 'voided', 'reversed'].includes(invoice.status) && (
              <HeaderActionButton
                variant="outline"
                icon="solar:box-bold-duotone"
                loading={createDeliveryOrderMutation.isPending}
                onClick={() => createDeliveryOrderMutation.mutate()}
                disabled={createDeliveryOrderMutation.isPending}
              >
                Create Delivery Order
              </HeaderActionButton>
            )}
            {invoice.status === 'draft' && canPost && (
              <HeaderActionButton
                icon="solar:check-circle-bold-duotone"
                loading={postMutation.isPending}
                onClick={() => postMutation.mutate()}
                disabled={postMutation.isPending}
              >
                Post Invoice
              </HeaderActionButton>
            )}
            {/* D7: pre-flight checklist — problems are visible before Post,
                not only as an error after it. */}
            {invoice.status === 'draft' && preflight && (
              <Popover>
                <PopoverTrigger asChild>
                  <button
                    type="button"
                    title="Pre-flight checklist"
                    className={cn(
                      'inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors',
                      preflight.ready
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300'
                        : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300',
                    )}
                  >
                    <Icon
                      icon={preflight.ready ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold'}
                      className="h-4 w-4"
                    />
                    {preflight.ready
                      ? `Ready ${preflight.passed}/${preflight.total}`
                      : `${preflight.total - preflight.passed} issue${preflight.total - preflight.passed === 1 ? '' : 's'}`}
                  </button>
                </PopoverTrigger>
                <PopoverContent align="end" className="w-80 p-0">
                  <div className="border-b px-3 py-2 text-xs font-semibold">
                    Pre-flight checklist · {preflight.passed}/{preflight.total} ready
                  </div>
                  <ul className="max-h-72 overflow-y-auto p-1.5 text-xs">
                    {preflight.checks.map((check) => (
                      <li key={check.key} className="flex gap-2 rounded-md p-2 hover:bg-muted/50">
                        <Icon
                          icon={
                            check.status === 'pass'
                              ? 'solar:check-circle-bold'
                              : check.status === 'warn'
                                ? 'solar:danger-triangle-bold'
                                : 'solar:close-circle-bold'
                          }
                          className={cn(
                            'mt-0.5 h-4 w-4 shrink-0',
                            check.status === 'pass'
                              ? 'text-emerald-600'
                              : check.status === 'warn'
                                ? 'text-amber-600'
                                : 'text-red-600',
                          )}
                        />
                        <div className="min-w-0">
                          <div className="font-medium">{check.label}</div>
                          <div className="text-muted-foreground">{check.detail}</div>
                        </div>
                      </li>
                    ))}
                  </ul>
                </PopoverContent>
              </Popover>
            )}
            {/* Approval channel: required invoices must be submitted before
                they can be posted; pending ones can be withdrawn. */}
            {invoice.status === 'draft' &&
              invoice.requires_approval &&
              !['pending', 'approved'].includes(invoice.approval_status ?? '') && (
                <HeaderActionButton
                  icon="solar:clipboard-check-bold-duotone"
                  loading={submitApprovalMutation.isPending}
                  onClick={() => submitApprovalMutation.mutate()}
                  disabled={submitApprovalMutation.isPending}
                >
                  Submit for Approval
                </HeaderActionButton>
              )}
            {invoice.approval_status === 'pending' && (
              <HeaderActionButton
                variant="outline"
                icon="solar:undo-left-round-bold-duotone"
                loading={withdrawApprovalMutation.isPending}
                onClick={() => withdrawApprovalMutation.mutate()}
                disabled={withdrawApprovalMutation.isPending}
              >
                Withdraw Approval
              </HeaderActionButton>
            )}
            {invoice.status === 'draft' && (
              <HeaderActionButton
                variant="outline"
                icon="solar:pen-bold-duotone"
                onClick={() => navigate(`/invoices/${invoice.id}/edit`)}
              >
                Edit
              </HeaderActionButton>
            )}
            {canRegisterPayment && isInvoicePayable(invoice) && (
              <HeaderActionButton
                icon="solar:card-bold-duotone"
                onClick={() => setRegisterPaymentOpen(true)}
              >
                Register payment
              </HeaderActionButton>
            )}
            {/* Issue #886 (UX4): write off (part of) an open receivable as bad
                debt — accounting-only, mirrors the accounting.post API gate. */}
            {canPost && ['posted', 'sent', 'partial', 'overdue'].includes(invoice.status) && invoice.balance > 0 && (
              <HeaderActionButton
                variant="outline"
                icon="solar:danger-triangle-bold-duotone"
                className="border-rose-200 text-rose-700 hover:bg-rose-50 hover:text-rose-800 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950/40"
                onClick={() => setWriteOffDialogOpen(true)}
              >
                Write off
              </HeaderActionButton>
            )}
            {/* Issue #328: follow-up reminder once the invoice is overdue. */}
            {hasPermission('invoices.send') && isInvoiceOverdue(invoice) && (
              <HeaderActionButton
                variant="outline"
                icon="solar:bell-bing-bold-duotone"
                loading={reminderMutation.isPending}
                disabled={reminderMutation.isPending}
                onClick={() => reminderMutation.mutate()}
              >
                Send reminder
              </HeaderActionButton>
            )}
            {/* #618: one print/send menu shared with every sales document. */}
            <PrintSendMenu
              documentType="invoice"
              pdfPath={`/invoices/${id}/pdf`}
              filename={`invoice-${invoice?.invoice_number || id}.pdf`}
              title="Invoice"
              resolutionPath={`/documents/invoice/resolution?document_id=${id}`}
              onEmail={() => deliverInvoiceMutation.mutate('email')}
              onWhatsApp={() => deliverInvoiceMutation.mutate('whatsapp')}
            />
            {/* Reverses the GL entry + restores stock — Owner/Admin only,
                mirroring the documents.reset_to_draft permission. */}
            {(role === 'Owner' || role === 'Admin') &&
              ['posted', 'sent', 'partial', 'overdue', 'cancelled'].includes(invoice.status) && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:undo-left-round-bold-duotone"
                  loading={resetToDraftMutation.isPending}
                  className="border-amber-300 text-amber-700 hover:bg-amber-50 hover:text-amber-800 dark:border-amber-900 dark:text-amber-400 dark:hover:bg-amber-950/40"
                  onClick={() => setResetDialogOpen(true)}
                  disabled={resetToDraftMutation.isPending}
                >
                  Reset to Draft
                </HeaderActionButton>
              )}
            {/* Destructive action always last. */}
            {['draft', 'cancelled'].includes(invoice.status) && (
              <HeaderActionButton
                variant="outline"
                icon="solar:trash-bin-trash-bold-duotone"
                className="w-9 px-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                loading={deleteMutation.isPending}
                aria-label="Delete invoice"
                title="Delete"
                onClick={() => setDeleteDialogOpen(true)}
                disabled={deleteMutation.isPending}
              />
            )}
          </>}
        />
        {/* Issue #696 (INV-D3): stock & COGS reconciliation badge. */}
        <StockCogsBadge
          documentType="invoice"
          documentId={Number(id)}
          permission="invoices.view"
          className="mt-2"
        />
      </div>

      {/* Approval actions — shown to the reviewer while the invoice awaits their decision */}
      {invoice.can_approve && (
        <div className="print:hidden space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
          <div className="flex items-center gap-2">
            <Icon icon="solar:clipboard-check-bold-duotone" className="h-5 w-5 text-amber-600 dark:text-amber-400" />
            <span className="text-sm font-semibold text-amber-900 dark:text-amber-200">
              This invoice is awaiting your approval
            </span>
          </div>
          <Textarea
            value={approvalComment}
            onChange={(e) => setApprovalComment(e.target.value)}
            placeholder="Add a comment (required when declining)…"
            className="min-h-[80px] bg-white dark:bg-background"
          />
          <div className="flex items-center gap-2">
            <Button
              onClick={() => approveMutation.mutate()}
              disabled={approveMutation.isPending || declineMutation.isPending}
              className="h-8 rounded-full bg-emerald-600 px-4 text-xs text-white hover:bg-emerald-700"
            >
              <Icon icon="solar:check-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
              {approveMutation.isPending ? 'Approving…' : 'Approve'}
            </Button>
            <Button
              variant="outline"
              onClick={handleDecline}
              disabled={approveMutation.isPending || declineMutation.isPending}
              className="h-8 rounded-full border-red-300 px-4 text-xs text-red-700 hover:bg-red-50 hover:text-red-800 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
            >
              <Icon icon="solar:close-circle-bold-duotone" className="mr-1.5 h-4 w-4" />
              {declineMutation.isPending ? 'Declining…' : 'Decline'}
            </Button>
          </div>
        </div>
      )}

      {/* Approved — the invoice is ready to be sent to the customer or posted */}
      {invoice.status === 'draft' && invoice.approval_status === 'approved' && (
        <div className="print:hidden flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
            <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
          </div>
          <div className="min-w-0 flex-1 space-y-1">
            <span className="text-sm font-semibold text-emerald-900 dark:text-emerald-200">
              Invoice approved — ready to send or post
            </span>
            <p className="text-sm text-emerald-800 dark:text-emerald-300">
              Send it to the customer for acceptance and evidence, or post it directly.
            </p>
          </div>
        </div>
      )}

      {/* Rejection notice — the invoice is back to draft after a manager rejection */}
      {invoice.status === 'draft' && invoice.latest_rejection && (
        <div className="print:hidden flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/50">
            <Icon icon="solar:close-circle-bold-duotone" className="h-5 w-5 text-red-600 dark:text-red-400" />
          </div>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-semibold text-red-900 dark:text-red-200">
                Approval rejected — returned to draft
              </span>
              <Badge variant="outline" className="border-red-300 font-mono text-[10px] text-red-700 dark:border-red-800 dark:text-red-300">
                {invoice.latest_rejection.request_number}
              </Badge>
            </div>
            <p className="text-sm text-red-800 dark:text-red-300">
              {invoice.latest_rejection.reviewed_by && (
                <span className="font-medium">{invoice.latest_rejection.reviewed_by}: </span>
              )}
              {invoice.latest_rejection.reason || 'No reason provided.'}
            </p>
            <p className="text-xs text-red-700/80 dark:text-red-400/80">
              Amend the invoice, then use <span className="font-medium">Send for Approval</span> to resubmit.
            </p>
          </div>
          <Icon
            icon="solar:pen-bold-duotone"
            className="hidden sm:block h-5 w-5 shrink-0 text-red-500/70"
          />
        </div>
      )}

      {/* #671 (EINV-UX2): e-invoice gaps (TIN, classification, address)
          shown before Post, with customer fixes in place. */}
      {id && (
        <EInvoiceReadinessPanel
          invoiceId={id}
          enabled={invoice.status === 'draft'}
          canEditCustomer={hasPermission('customers.write')}
        />
      )}

      <Tabs defaultValue="document" className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full print:hidden">
          <TabsTrigger value="document" className="gap-2 rounded-full px-4">
            Document
          </TabsTrigger>
          <TabsTrigger value="journal" className="gap-2 rounded-full px-4">
            <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4" />
            Journal
          </TabsTrigger>
          <TabsTrigger value="audit" className="gap-2 rounded-full px-4">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            Audit
          </TabsTrigger>
          <TabsTrigger value="stock-moves" className="gap-2 rounded-full px-4">
            <Icon icon="solar:box-bold-duotone" className="h-4 w-4" />
            Stock moves
          </TabsTrigger>
        </TabsList>

        <TabsContent value="document" className="space-y-6 mt-6">
      {/* Linked documents */}
      <div className="flex flex-wrap items-center gap-2 print:hidden">
        <TagEditor subjectType="invoice" subjectId={id as string} />
        {invoice.quotation && (
          <DocumentLinkChip
            kind="quotation"
            label={invoice.quotation.quotation_number}
            onClick={() => navigate(`/quotations/${invoice.quotation!.id}`)}
          />
        )}
        {invoice.delivery_orders && invoice.delivery_orders.length > 0 && invoice.delivery_orders.map((d) => (
          <DocumentLinkChip
            key={d.id}
            kind="delivery_order"
            label={d.delivery_order_number}
            onClick={() => navigate(`/delivery-orders/${d.id}`)}
          />
        ))}
        <InvoiceDeliveryProgressSummary progress={invoice.delivery_progress} />
        {(invoice.write_offs ?? []).filter((writeOff) => writeOff.journal_entry_id).map((writeOff) => (
          <Link
            key={`write-off-${writeOff.id}`}
            to={`/accounting/core/journal-entries?entry_id=${writeOff.journal_entry_id}`}
            title={writeOff.reason}
            className="inline-flex h-8 items-center gap-1.5 rounded-full border border-rose-300/60 bg-rose-50 px-3 text-xs font-medium text-rose-700 transition-colors hover:border-rose-400 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300"
          >
            <Icon icon="solar:danger-triangle-bold-duotone" className="h-3.5 w-3.5" />
            Written off {money(Number(writeOff.amount))}
          </Link>
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
              {invoice.is_down_payment && (
                <Badge variant="outline" className="mt-2" data-testid="invoice-down-payment-badge">
                  Down payment
                </Badge>
              )}
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
                {invoice.payment_term?.name && (
                  <div className="flex justify-between gap-8">
                    <span className="text-muted-foreground">Payment Term:</span>
                    <span>{invoice.payment_term.name}</span>
                  </div>
                )}
              </div>
              {(invoice.instalments?.length ?? 0) > 1 && (
                <table className="mt-3 ml-auto text-xs" data-testid="invoice-instalments">
                  <thead>
                    <tr className="text-muted-foreground">
                      <th className="px-2 text-left font-medium">Instalment due</th>
                      <th className="px-2 text-right font-medium">Amount</th>
                      <th className="px-2 text-right font-medium">Outstanding</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoice.instalments!.map((row) => (
                      <tr key={row.sequence}>
                        <td className="px-2 text-left">{row.due_date ? formatDate(row.due_date) : '—'}</td>
                        <td className="px-2 text-right">{money(Number(row.amount))}</td>
                        <td className="px-2 text-right">{money(Number(row.outstanding))}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
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
                  <p className="text-lg font-semibold">
                    <Link
                      to={`/customers/${invoice.customer.id}`}
                      className="text-primary underline-offset-2 hover:underline"
                    >
                      {invoice.customer.name}
                    </Link>
                  </p>
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
                    <p className="text-sm text-muted-foreground mt-2 whitespace-pre-line break-words">
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
                    <TableCell className="font-medium">
                      {line.product ? (
                        <Link
                          to={`/products/${line.product.id}`}
                          className="text-primary underline-offset-2 hover:underline"
                          title={`Open ${line.product.name}`}
                        >
                          {line.product.name}
                        </Link>
                      ) : (
                        line.description || '-'
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {line.product ? (
                        <Link
                          to={`/products/${line.product.id}`}
                          className="font-mono text-xs underline-offset-2 hover:text-primary hover:underline"
                        >
                          {line.product.sku || '-'}
                        </Link>
                      ) : (
                        '-'
                      )}
                    </TableCell>
                    <TableCell>
                      <div>{line.description || '-'}</div>
                      {line.down_payment_invoice_id && (
                        <div className="mt-1 text-xs text-muted-foreground" data-testid={`invoice-line-down-payment-${line.id}`}>
                          Deducted from Customer Deposit
                        </div>
                      )}
                      {line.analytic_distribution && line.analytic_distribution.length > 0 && (
                        <div className="mt-1 text-xs text-muted-foreground" data-testid={`invoice-line-analytic-${line.id}`}>
                          Analytic:{' '}
                          {line.analytic_distribution
                            .map((share) => `${share.name || share.code || `#${share.analytic_account_id}`} ${Number(share.percentage)}%`)
                            .join(' · ')}
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.lot_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.expiry_date ? formatDate(line.expiry_date) : '-'}</TableCell>
                    <TableCell className="text-right">
                      <div>{line.quantity}</div>
                      <div className="mt-1 print:hidden">
                        <InvoiceLineDeliveryChip progress={invoice.delivery_progress} lineId={line.id} />
                      </div>
                    </TableCell>
                    <TableCell className="text-right">{money(line.unit_price)}</TableCell>
                    <TableCell className="text-right">{money(line.tax_amount)}</TableCell>
                    <TableCell className="text-right font-medium">{money(line.line_total)}</TableCell>
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
                <span>{money(invoice.subtotal)}</span>
              </div>
              {invoice.tax_amount > 0 && (
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax</span>
                  <span>{money(invoice.tax_amount)}</span>
                </div>
              )}
              {invoice.discount_amount > 0 && (
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Discount</span>
                  <span className="text-green-600">-{money(invoice.discount_amount)}</span>
                </div>
              )}
              <Separator />
              <div className="flex justify-between text-lg font-bold">
                <span>Total</span>
                <span>{money(invoice.total)}</span>
              </div>
              {showBaseEquivalent && (
                <div className="flex justify-between text-sm text-muted-foreground">
                  <span>Base equivalent ({baseCurrency})</span>
                  <span>{baseMoney(invoice.total)}</span>
                </div>
              )}
              {invoice.paid_amount > 0 && (
                <>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Paid</span>
                    <span className="text-green-600">{money(invoice.paid_amount)}</span>
                  </div>
                  <div className="flex justify-between text-sm font-semibold">
                    <span className="text-muted-foreground">Balance Due</span>
                    <span className={invoice.balance > 0 ? 'text-red-600' : 'text-green-600'}>
                      {money(invoice.balance)}
                    </span>
                  </div>
                </>
              )}
              {showBaseEquivalent && (
                <p className="text-right text-xs text-muted-foreground">
                  1 {docCurrency} = {fxRate.toFixed(4)} {baseCurrency}
                  {invoice.exchange_rate ? '' : ''}
                </p>
              )}
            </div>
          </div>

          <Dialog open={resetDialogOpen} onOpenChange={setResetDialogOpen}>
            <DialogContent className="max-w-md">
              <DialogHeader>
                <div className="flex items-start gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-950/60">
                    <Icon icon="solar:undo-left-round-bold-duotone" className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                  </div>
                  <div className="space-y-1">
                    <DialogTitle>Reset this invoice to draft?</DialogTitle>
                    <DialogDescription>
                      Invoice <span className="font-medium text-foreground">{invoice.invoice_number}</span> will
                      return to draft, become editable again, and must be posted once more.
                      The customer will be notified that this invoice has been withdrawn.
                    </DialogDescription>
                  </div>
                </div>
              </DialogHeader>

              <div className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40">
                <div className="font-medium text-amber-900 dark:text-amber-200">
                  What happens when you reset:
                </div>
                <ul className="space-y-1 text-amber-800 dark:text-amber-300">
                  <li className="flex items-start gap-2">
                    <Icon icon="solar:check-circle-bold" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    A reversing journal entry offsets the original general-ledger posting.
                  </li>
                  <li className="flex items-start gap-2">
                    <Icon icon="solar:check-circle-bold" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Stock moved by the linked delivery is restored.
                  </li>
                  <li className="flex items-start gap-2">
                    <Icon icon="solar:close-circle-bold" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    The reset is blocked if the invoice already has payments or posted credit notes.
                  </li>
                </ul>
              </div>

              <DialogFooter>
                <Button variant="outline" onClick={() => setResetDialogOpen(false)}>
                  Cancel
                </Button>
                <Button
                  className="bg-amber-600 hover:bg-amber-700"
                  onClick={() => {
                    setResetDialogOpen(false)
                    resetToDraftMutation.mutate()
                  }}
                  disabled={resetToDraftMutation.isPending}
                >
                  <Icon icon="solar:undo-left-round-bold-duotone" className="mr-2 h-4 w-4" />
                  Reset to draft
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <InvoiceWriteOffDialog
            invoiceId={id as string}
            invoiceNumber={invoice.invoice_number}
            balance={Number(invoice.balance)}
            currencyCode={invoice.currency_code}
            open={writeOffDialogOpen}
            onOpenChange={setWriteOffDialogOpen}
          />

          <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
            <DialogContent className="max-w-md">
              <DialogHeader>
                <div className="flex items-start gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-destructive/10">
                    <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-5 w-5 text-destructive" />
                  </div>
                  <div className="space-y-1">
                    <DialogTitle>Delete this invoice?</DialogTitle>
                    <DialogDescription>
                      Invoice <span className="font-medium text-foreground">{invoice.invoice_number}</span> and
                      all of its line items will be permanently removed. This cannot be undone.
                    </DialogDescription>
                  </div>
                </div>
              </DialogHeader>

              <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">Customer</span>
                  <span className="font-medium">{invoice.customer?.name ?? '—'}</span>
                </div>
                <div className="mt-1 flex items-center justify-between">
                  <span className="text-muted-foreground">Total</span>
                  <span className="font-semibold tabular-nums">
                    {formatCurrency(invoice.total)}
                  </span>
                </div>
              </div>

              <DialogFooter>
                <Button variant="outline" onClick={() => setDeleteDialogOpen(false)}>
                  Keep invoice
                </Button>
                <Button
                  variant="destructive"
                  onClick={() => deleteMutation.mutate()}
                  disabled={deleteMutation.isPending}
                >
                  {deleteMutation.isPending ? (
                    <div className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-current" />
                  ) : (
                    <Icon icon="solar:trash-bin-trash-bold-duotone" className="mr-2 h-4 w-4" />
                  )}
                  Delete invoice
                </Button>
              </DialogFooter>
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
                <div className="space-y-2">
                  <Label htmlFor="invoice-send-email" className="text-xs font-medium text-muted-foreground">
                    Send to email
                  </Label>
                  <Input
                    id="invoice-send-email"
                    type="email"
                    value={sendRecipientEmail}
                    onChange={(e) => setSendRecipientEmail(e.target.value)}
                    placeholder="customer@example.com"
                  />
                  {!invoice.customer?.email && (
                    <p className="text-xs text-amber-600">
                      The customer has no email on file — enter one to send the invoice.
                    </p>
                  )}
                  <label className="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                    <input
                      type="checkbox"
                      checked={saveCustomerEmail}
                      onChange={(e) => setSaveCustomerEmail(e.target.checked)}
                      className="h-3.5 w-3.5 rounded border-gray-300"
                    />
                    Save this email on the customer record
                  </label>
                </div>
                <ul className="text-xs text-muted-foreground space-y-1">
                  <li>• Invoice PDF will be attached to the email</li>
                  <li>• Customer gets a unique signed acceptance link (no login)</li>
                  <li>• Pay-now link + scannable QR included when the invoice is payable</li>
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
                {sendDialogPaymentUrl && (
                  <div className="rounded-md border border-green-200 bg-green-50 p-3 dark:border-green-900 dark:bg-green-950/40">
                    <div className="text-xs font-medium text-green-700 dark:text-green-400">Pay-now link</div>
                    <a
                      href={sendDialogPaymentUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="break-all text-xs text-green-700 underline dark:text-green-300"
                    >
                      {sendDialogPaymentUrl}
                    </a>
                  </div>
                )}
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setSendDialogOpen(false)}>Cancel</Button>
                <Button
                  onClick={() => sendInvoiceMutation.mutate()}
                  disabled={
                    sendInvoiceMutation.isPending ||
                    !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(sendRecipientEmail.trim())
                  }
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

          {/* #488 (PAY-UX2): the customer's unapplied payments, applicable here. */}
          {isInvoicePayable(invoice) && invoice.customer_id && (
            <div className="mb-8">
              <UnappliedCreditsCard
                customerId={Number(invoice.customer_id)}
                invoiceId={invoice.id}
                invoiceBalance={Number(invoice.balance ?? 0)}
                currency={invoice.currency_code}
                hideWhenEmpty
              />
            </div>
          )}

          {/* #495: the customer's credit notes still holding credit, applicable here. */}
          {isInvoicePayable(invoice) && invoice.customer_id && (
            <div className="mb-8">
              <OutstandingCreditNotesCard
                customerId={Number(invoice.customer_id)}
                invoiceId={invoice.id}
                invoiceNumber={invoice.invoice_number}
                invoiceBalance={Number(invoice.balance ?? 0)}
                currency={invoice.currency_code}
                legalEntityId={invoice.legal_entity_id ?? null}
              />
            </div>
          )}

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
                    <p className="text-sm font-medium break-words">{invoice.billing_contact.display_name || '-'}</p>
                  </div>
                )}
                {invoice.shipping_contact && (
                  <div>
                    <span className="text-xs text-muted-foreground">Shipping Contact</span>
                    <p className="text-sm font-medium break-words">{invoice.shipping_contact.display_name || '-'}</p>
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
                      <p className="text-sm whitespace-pre-wrap break-words">{formatAddress(invoice.billing_address_snapshot)}</p>
                    </div>
                  )}
                  {invoice.shipping_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-xs text-muted-foreground mb-1">Delivery Address</p>
                      <p className="text-sm whitespace-pre-wrap break-words">{formatAddress(invoice.shipping_address_snapshot)}</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Notes & Terms */}
          {(invoice.terms || (!isAcmeTenant && visibleNotes)) && (
            <div className="grid grid-cols-2 gap-8">
              {!isAcmeTenant && visibleNotes && (
                <div>
                  <h3 className="text-sm font-semibold text-muted-foreground uppercase mb-2">Notes</h3>
                  <p className="text-sm whitespace-pre-wrap">{visibleNotes}</p>
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

      {/* Evidence — customer uploads (signature, payment proof) the vendor verifies */}
      {invoice.evidence && invoice.evidence.filter((e) => e.file_name || e.signed_at).length > 0 && (
        <Card className="print:hidden">
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Icon icon="solar:folder-check-bold-duotone" className="h-5 w-5 text-primary" />
              Evidence
              {invoice.evidence_confirmed ? (
                <Badge variant="secondary" className="bg-emerald-100 text-emerald-800">
                  Confirmed
                </Badge>
              ) : (
                <Badge variant="secondary" className="bg-amber-100 text-amber-800">
                  Awaiting confirmation
                </Badge>
              )}
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {invoice.evidence
              .filter((e) => e.file_name || e.signed_at)
              .map((e) => (
                <div
                  key={e.id}
                  className="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-muted/30 px-3 py-2"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <Icon
                      icon={
                        e.type === 'payment_proof'
                          ? 'solar:wallet-money-bold-duotone'
                          : 'solar:document-text-bold-duotone'
                      }
                      className="h-5 w-5 shrink-0 text-primary"
                    />
                    <div className="min-w-0">
                      <div className="truncate text-sm font-medium">
                        {e.file_name || (e.type === 'customer_acceptance' ? 'Customer acceptance' : e.type.replace(/_/g, ' '))}
                      </div>
                      <div className="text-xs capitalize text-muted-foreground">
                        {e.type.replace(/_/g, ' ')}
                        {e.signer_name ? ` · ${e.signer_name}` : ''}
                        {e.created_at ? ` · ${formatDate(e.created_at)}` : ''}
                      </div>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    {e.file_name && (
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7 rounded-full px-3 text-xs"
                        onClick={() => viewEvidence(e.id)}
                        disabled={viewingEvidence === e.id}
                      >
                        <Icon icon="solar:eye-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
                        {viewingEvidence === e.id ? 'Opening…' : 'View'}
                      </Button>
                    )}
                    {e.confirmed_at ? (
                      <span className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                        <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
                        Confirmed{e.confirmed_by?.name ? ` by ${e.confirmed_by.name}` : ''}
                      </span>
                    ) : (
                      <Button
                        size="sm"
                        className="h-7 rounded-full bg-emerald-600 px-3 text-xs text-white hover:bg-emerald-700"
                        onClick={() => confirmEvidenceMutation.mutate(e.id)}
                        disabled={confirmEvidenceMutation.isPending}
                      >
                        <Icon icon="solar:check-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
                        Confirm
                      </Button>
                    )}
                  </div>
                </div>
              ))}
          </CardContent>
        </Card>
      )}

      {/* Footer */}
      <div className="text-center text-sm text-muted-foreground print:hidden">
        <p>Thank you for your business!</p>
      </div>
        </TabsContent>

        <TabsContent value="journal" className="mt-6">
          <InvoiceJournalTab invoiceId={id as string} status={invoice.status} />
        </TabsContent>

        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/invoices/${id}/audit`} permission="invoices.view" />
        </TabsContent>

        <TabsContent value="stock-moves" className="mt-6">
          <StockMovesTab endpoint={`/invoices/${id}/stock-moves`} permission="invoices.view" />
        </TabsContent>
      </Tabs>

      {/* Odoo-style inline Chatter — sticky at bottom of viewport, click header to expand */}
      <ChatterDock
        subjectType={String.raw`App\Models\Invoice`}
        subjectId={id as string}
        documentLabel={invoice.invoice_number}
        open={chatterOpen}
        onOpenChange={setChatterOpen}
      />
      <RegisterPaymentDialog
        invoiceId={registerPaymentOpen ? invoice.id : null}
        onOpenChange={setRegisterPaymentOpen}
      />
    </div>
  )
}
