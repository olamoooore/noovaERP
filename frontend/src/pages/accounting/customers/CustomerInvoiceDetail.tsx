import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { useToast } from '@/hooks/use-toast';
import { formatDate, resolveImageUrl, formatCurrency } from '@/lib/utils';
import api from '@/lib/api';
import InvoiceCreditNotes from '../components/InvoiceCreditNotes';
import CreateCreditNoteDialog from '../components/CreateCreditNoteDialog';
import { useAuthStore } from '@/store/authStore';
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow';
import { SalesLineColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility';

const formatSnapshotAddress = (snapshot: string | null | undefined): string => {
  if (!snapshot) return '';
  try {
    const parsed = JSON.parse(snapshot);
    const parts = [
      parsed.first_name || parsed.name || '',
      parsed.last_name || '',
      parsed.address || parsed.street || '',
      parsed.street2 || '',
      parsed.city || '',
      parsed.state || '',
      parsed.postal_code || parsed.zip || '',
      parsed.country || '',
    ].filter(Boolean);
    return parts.join(', ');
  } catch {
    return snapshot;
  }
};

const INVOICE_LINE_COLUMNS = [
  'Product/Service', 'SKU', 'Description', 'Model #', 'Product Notes', 'Lot Number',
  'Expiry Date', 'Quantity', 'Unit Price', 'Tax Rate', 'Tax Amount', 'Line Total',
].map((label, index) => ({ label, index: index + 1 }));


export const CustomerInvoiceDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const isAcmeTenant = useAuthStore((state) => state.tenantId) === '00000000-0000-0000-0000-000000000001';
  const role = useAuthStore((state) => state.role);
  // Product Notes starts hidden, matching the previous default.
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(
    () => INVOICE_LINE_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  );
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [showCancelDialog, setShowCancelDialog] = useState(false);
  const [showResetDialog, setShowResetDialog] = useState(false);
  const [showCreditNoteDialog, setShowCreditNoteDialog] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const isQuoteMode = location.pathname.startsWith('/sales/quotes');
  const documentLabel = isQuoteMode ? 'Quotation' : 'Invoice';
  const backPath = isQuoteMode ? '/sales/quotes' : '/accounting/customers/bills';

  const handleDownloadPdf = async () => {
    setIsDownloading(true);
    try {
      const response = await api.get(`/invoices/${id}/pdf`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(response.data);
      const a = document.createElement('a');
      a.href = url;
      a.download = `invoice-${id}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);
    } catch (error) {
      toast({ variant: 'destructive', title: 'Failed to download PDF' });
    } finally {
      setIsDownloading(false);
    }
  };

  // Fetch invoice details
  const { data: invoice, isLoading } = useQuery({
    queryKey: ['customer-invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`);
      return response.data;
    },
    enabled: !!id,
  });

  // Count payments linked to this invoice for the "Payments (N)" smart button.
  const { data: paymentsCount } = useQuery({
    queryKey: ['invoice-payments-count', id],
    queryFn: async () => {
      const response = await api.get(`/payments?invoice_id=${id}&per_page=1`);
      return response.data?.total ?? 0;
    },
    enabled: !!id,
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete(`/invoices/${id}`);
    },
    onSuccess: () => {
      toast({ title: `${documentLabel} deleted successfully` });
      navigate(backPath);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: `Failed to delete ${documentLabel.toLowerCase()}`,
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  // Post invoice mutation
  const postMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/invoices/${id}/send`);
    },
    onSuccess: () => {
      toast({ title: isQuoteMode ? 'Quotation converted to invoice' : 'Invoice posted successfully' });
      queryClient.invalidateQueries({ queryKey: ['customer-invoice', id] });
      if (isQuoteMode) {
        queryClient.invalidateQueries({ queryKey: ['sales-quotes'] });
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: isQuoteMode ? 'Failed to convert quotation' : 'Failed to post invoice',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  // Cancel invoice mutation
  const cancelMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/invoices/${id}/cancel`);
    },
    onSuccess: () => {
      toast({ title: `${documentLabel} cancelled` });
      queryClient.invalidateQueries({ queryKey: ['customer-invoice', id] });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: `Failed to cancel ${documentLabel.toLowerCase()}`,
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  // Reset to draft mutation
  const resetToDraftMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/invoices/${id}/reset-to-draft`);
    },
    onSuccess: () => {
      toast({ title: `${documentLabel} reset to draft` });
      queryClient.invalidateQueries({ queryKey: ['customer-invoice', id] });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: `Failed to reset ${documentLabel.toLowerCase()}`,
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { variant: any; label: string; icon: string }> = {
      draft: { variant: 'secondary', label: 'Draft', icon: 'solar:document-bold-duotone' },
      sent: { variant: 'default', label: 'Sent', icon: 'solar:check-circle-bold' },
      posted: { variant: 'default', label: 'Posted', icon: 'solar:check-circle-bold-duotone' },
      paid: { variant: 'default', label: 'Paid', icon: 'solar:wallet-money-bold-duotone' },
      partial: { variant: 'default', label: 'Partially Paid', icon: 'solar:wallet-bold-duotone' },
      overdue: { variant: 'destructive', label: 'Overdue', icon: 'solar:danger-bold-duotone' },
      cancelled: { variant: 'secondary', label: 'Cancelled', icon: 'solar:close-circle-bold-duotone' },
    };

    const config = statusConfig[status] || { variant: 'secondary', label: status, icon: 'solar:document-bold-duotone' };
    return (
      <Badge variant={config.variant} className="gap-1">
        <Icon icon={config.icon} className="h-3 w-3" />
        {config.label}
      </Badge>
    );
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
          <p className="text-muted-foreground mt-4">Loading {documentLabel.toLowerCase()} details...</p>
        </div>
      </div>
    );
  }

  if (!invoice) {
    return (
      <div className="p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-12">
              <Icon icon="solar:bill-list-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50 text-muted-foreground" />
              <p className="text-lg font-medium mb-2">{documentLabel} not found</p>
              <p className="text-sm text-muted-foreground mb-4">The {documentLabel.toLowerCase()} you're looking for doesn't exist</p>
              <Button onClick={() => navigate(backPath)}>
                <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
                Back to {isQuoteMode ? 'Quotes' : 'Invoices'}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="print:hidden">
        <PageHeader
          title={`${documentLabel} ${invoice.invoice_number || `#${invoice.id}`}`}
          description={
            <>
              Customer:{' '}
              {invoice.customer ? (
                <Link
                  to={`/customers/${invoice.customer.id}`}
                  className="font-medium text-primary underline-offset-2 hover:underline"
                >
                  {invoice.customer.name}
                </Link>
              ) : (
                'Unknown'
              )}
              {invoice.customer?.code && (
                <span className="ml-2 font-mono text-xs text-muted-foreground">{invoice.customer.code}</span>
              )}
            </>
          }
          backTo={backPath}
          actions={<>
            <HeaderActionButton
              variant="outline"
              icon="solar:wallet-money-bold-duotone"
              onClick={() => navigate(`/accounting/customers/payments?invoice_id=${id}`)}
            >
              Payments ({paymentsCount ?? 0})
            </HeaderActionButton>

            {invoice.status === 'draft' && (
              <>
                {!isQuoteMode && (
                  <HeaderActionButton
                    variant="outline"
                    icon="solar:pen-bold-duotone"
                    onClick={() => navigate(`/accounting/customers/bills/${invoice.id}/edit`)}
                  >
                    Edit
                  </HeaderActionButton>
                )}
                <HeaderActionButton
                  icon="solar:check-circle-bold-duotone"
                  loading={postMutation.isPending}
                  onClick={() => postMutation.mutate()}
                >
                  {isQuoteMode ? 'Convert to Invoice' : 'Post Invoice'}
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:trash-bin-trash-bold-duotone"
                  className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                  onClick={() => setShowDeleteDialog(true)}
                >
                  Delete
                </HeaderActionButton>
              </>
            )}

            {['posted', 'sent', 'partial', 'overdue'].includes(invoice.status) && (
              <HeaderActionButton
                variant="outline"
                icon="solar:close-circle-bold-duotone"
                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                loading={cancelMutation.isPending}
                onClick={() => setShowCancelDialog(true)}
                disabled={cancelMutation.isPending}
              >
                Cancel
              </HeaderActionButton>
            )}

            {/* Owner/Admin only — mirrors documents.reset_to_draft. */}
            {(role === 'Owner' || role === 'Admin') &&
              ['posted', 'sent', 'partial', 'overdue', 'cancelled'].includes(invoice.status) && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:undo-left-round-bold-duotone"
                  className="border-amber-300 text-amber-700 hover:bg-amber-50 hover:text-amber-800 dark:border-amber-900 dark:text-amber-400 dark:hover:bg-amber-950/40"
                  loading={resetToDraftMutation.isPending}
                  onClick={() => setShowResetDialog(true)}
                  disabled={resetToDraftMutation.isPending}
                >
                  Reset to Draft
                </HeaderActionButton>
              )}

            <HeaderActionButton
              variant="outline"
              icon="solar:printer-bold-duotone"
              onClick={handleDownloadPdf}
              disabled={isDownloading}
            >
              Print
            </HeaderActionButton>
            <HeaderActionButton
              variant="outline"
              icon="solar:download-minimalistic-bold-duotone"
              onClick={handleDownloadPdf}
              disabled={isDownloading}
            >
              PDF
            </HeaderActionButton>
          </>}
        />
      </div>

      {/* Related document smart buttons */}
      {(invoice.quotation || (invoice.delivery_orders && invoice.delivery_orders.length > 0)) && (
        <div className="flex flex-wrap gap-3 print:hidden">
          {invoice.quotation && (
            <button
              type="button"
              onClick={() => navigate(`/quotations/${invoice.quotation.id}`)}
              className="inline-flex h-8 items-center gap-1.5 rounded-full border border-input bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            >
              <Icon icon="solar:document-text-bold-duotone" className="h-3.5 w-3.5" />
              {invoice.quotation.quotation_number}
              <span className="text-muted-foreground/70">Quotation</span>
            </button>
          )}
          {invoice.delivery_orders?.map((deliveryOrder: any) => (
            <button
              type="button"
              key={deliveryOrder.id}
              onClick={() => navigate(`/delivery-orders/${deliveryOrder.id}`)}
              className="inline-flex h-8 items-center gap-1.5 rounded-full border border-input bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            >
              <Icon icon="solar:box-bold-duotone" className="h-3.5 w-3.5" />
              {deliveryOrder.delivery_order_number}
              <span className="text-muted-foreground/70">Delivery Order</span>
            </button>
          ))}
        </div>
      )}

      {/* Invoice Header */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>{documentLabel} Information</span>
            {getStatusBadge(invoice.status)}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
              <p className="text-sm text-muted-foreground mb-1">{documentLabel} Number</p>
              <p className="font-medium">{invoice.invoice_number || `#${invoice.id}`}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Customer</p>
              <p className="font-medium">{invoice.customer?.name || 'Unknown'}</p>
              {invoice.customer?.code && (
                <p className="text-xs font-mono text-muted-foreground">{invoice.customer.code}</p>
              )}
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">{isQuoteMode ? 'Quote Date' : 'Invoice Date'}</p>
              <p className="font-medium">{formatDate(invoice.invoice_date || invoice.created_at)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">{isQuoteMode ? 'Valid Until' : 'Due Date'}</p>
              <p className="font-medium">{formatDate(invoice.due_date || invoice.invoice_date || invoice.created_at)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Subtotal</p>
              <p className="font-medium">{formatCurrency(invoice.subtotal)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Tax Amount</p>
              <p className="font-medium">{formatCurrency(invoice.tax_amount)}</p>
            </div>
            {(invoice.shipping_cost || invoice.shipping_method) && (
              <div>
                <p className="text-sm text-muted-foreground mb-1">Shipping</p>
                <p className="font-medium">{formatCurrency(invoice.shipping_cost || 0)}</p>
              </div>
            )}
            {invoice.shipping_method && (
              <div>
                <p className="text-sm text-muted-foreground mb-1">Shipping Method</p>
                <p className="font-medium">{invoice.shipping_method}</p>
              </div>
            )}
            <div>
              <p className="text-sm text-muted-foreground mb-1">Total Amount</p>
              <p className="text-xl font-bold text-primary">{formatCurrency(invoice.total)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Balance Due</p>
              <p className="text-xl font-bold text-green-600">{formatCurrency(invoice.balance)}</p>
            </div>
          </div>
          {!isAcmeTenant && invoice.notes && (
            <div className="mt-6 pt-6 border-t">
              <p className="text-sm text-muted-foreground mb-1">Notes</p>
              <p className="text-sm">{invoice.notes}</p>
            </div>
          )}
          {invoice.terms && (
            <div className="mt-4">
              <p className="text-sm text-muted-foreground mb-1">Terms</p>
              <p className="text-sm">{invoice.terms}</p>
            </div>
          )}
          {(invoice.case_number || invoice.date_of_case || invoice.case_location || invoice.patient_name || invoice.cost_center_id || invoice.delivery_date) && (
            <div className="mt-6 pt-6 border-t">
              <p className="text-sm font-medium mb-3">Case Information</p>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {invoice.case_number && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Case Number</p>
                    <p className="font-medium">{invoice.case_number}</p>
                  </div>
                )}
                {invoice.date_of_case && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Date of Case</p>
                    <p className="font-medium">{formatDate(invoice.date_of_case)}</p>
                  </div>
                )}
                {invoice.case_location && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Location</p>
                    <p className="font-medium">{invoice.case_location}</p>
                  </div>
                )}
                {invoice.patient_name && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Patient Name</p>
                    <p className="font-medium">{invoice.patient_name}</p>
                  </div>
                )}
                {invoice.cost_center_id && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Cost Center Id</p>
                    <p className="font-medium">{invoice.cost_center_id}</p>
                  </div>
                )}
                {invoice.delivery_date && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Delivery Date</p>
                    <p className="font-medium">{formatDate(invoice.delivery_date)}</p>
                  </div>
                )}
                {invoice.po_number && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">PO Number</p>
                    <p className="font-medium">{invoice.po_number}</p>
                  </div>
                )}
                {invoice.doctor && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Doctor</p>
                    <p className="font-medium">{invoice.doctor.display_name || invoice.doctor.full_name || '-'}</p>
                  </div>
                )}
                {invoice.requestor && (
                  <div>
                    <p className="text-sm text-muted-foreground mb-1">Requestor</p>
                    <p className="font-medium">{invoice.requestor.display_name || invoice.requestor.full_name || '-'}</p>
                  </div>
                )}
              </div>
            </div>
          )}
          {(invoice.customer_reference || invoice.internal_reference || invoice.attention_to || invoice.contact_email || invoice.contact_phone || invoice.company_registration_number || invoice.tax_registration_number || invoice.billing_contact || invoice.shipping_contact || invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
            <div className="mt-6 pt-6 border-t space-y-6">
              <div>
                <p className="text-sm font-medium mb-3">Commercial Snapshot</p>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                  {invoice.customer_reference && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Customer Reference</p>
                      <p className="font-medium">{invoice.customer_reference}</p>
                    </div>
                  )}
                  {invoice.internal_reference && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Internal Reference</p>
                      <p className="font-medium">{invoice.internal_reference}</p>
                    </div>
                  )}
                  {invoice.attention_to && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Attention To</p>
                      <p className="font-medium">{invoice.attention_to}</p>
                    </div>
                  )}
                  {invoice.contact_email && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Contact Email</p>
                      <p className="font-medium">{invoice.contact_email}</p>
                    </div>
                  )}
                  {invoice.contact_phone && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Contact Phone</p>
                      <p className="font-medium">{invoice.contact_phone}</p>
                    </div>
                  )}
                  {invoice.billing_contact && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Billing Contact</p>
                      <p className="font-medium">{invoice.billing_contact.display_name || '-'}</p>
                    </div>
                  )}
                  {invoice.shipping_contact && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Shipping Contact</p>
                      <p className="font-medium">{invoice.shipping_contact.display_name || '-'}</p>
                    </div>
                  )}
                  {invoice.company_registration_number && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Company Registration</p>
                      <p className="font-medium">{invoice.company_registration_number}</p>
                    </div>
                  )}
                  {invoice.tax_registration_number && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Tax Registration</p>
                      <p className="font-medium">{invoice.tax_registration_number}</p>
                    </div>
                  )}
                </div>
              </div>
              {(invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {invoice.billing_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-sm text-muted-foreground mb-2">Billing Address</p>
                      <p className="text-sm whitespace-pre-wrap">{formatSnapshotAddress(invoice.billing_address_snapshot)}</p>
                    </div>
                  )}
                  {invoice.shipping_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-sm text-muted-foreground mb-2">Delivery Address</p>
                      <p className="text-sm whitespace-pre-wrap">{formatSnapshotAddress(invoice.shipping_address_snapshot)}</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Line Items */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle>Line Items</CardTitle>
          <SalesLineColumnMenu
            columns={INVOICE_LINE_COLUMNS}
            hidden={hiddenLineColumns}
            setHidden={setHiddenLineColumns}
          />
        </CardHeader>
        <CardContent>
          <SalesLineColumnVisibility
            tableClass="customer-invoice-detail-columns"
            columns={INVOICE_LINE_COLUMNS}
            hiddenColumns={hiddenLineColumns}
            onHiddenColumnsChange={setHiddenLineColumns}
            showMenu={false}
          >
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product/Service</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Model #</TableHead>
                  <TableHead>Product Notes</TableHead>
                  <TableHead>Lot Number</TableHead>
                  <TableHead>Expiry Date</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax Rate</TableHead>
                  <TableHead className="text-right">Tax Amount</TableHead>
                  <TableHead className="text-right">Line Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoice.lines && invoice.lines.length > 0 ? (
                  invoice.lines.map((line: any, index: number) => (
                    <TableRow key={index}>
                      {line.line_type === 'note' || line.line_type === 'section' ? <TableCell colSpan={12} className={line.line_type === 'section' ? 'bg-muted/60 font-semibold' : 'bg-muted/30 italic text-muted-foreground'}><div className="flex items-center justify-between gap-4"><span>{line.description}</span>{line.line_type === 'section' && <span className="whitespace-nowrap tabular-nums">{formatCurrency(getDocumentSectionTotal(invoice.lines || [], index, (item: any) => Number(item.line_total || 0)))}</span>}</div></TableCell> : <>
                      <TableCell>
                        <div className="flex items-center gap-3">
                          {line.product?.image ? (
                            <img
                              src={resolveImageUrl(line.product.image)}
                              alt={line.product?.name || line.description || 'Line item'}
                              className="h-12 w-12 rounded object-cover border bg-background"
                            />
                          ) : (
                            <div className="h-12 w-12 rounded border bg-background flex items-center justify-center text-muted-foreground">
                              <Icon icon="solar:box-bold-duotone" className="h-5 w-5" />
                            </div>
                          )}
                          <div className="min-w-0">
                            <p className="font-medium truncate">{line.product?.name || line.description || '-'}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                      <TableCell className="whitespace-pre-line align-top">{line.description || '-'}</TableCell>
                      <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                      <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                      <TableCell className="text-muted-foreground">{line.lot_number || '-'}</TableCell>
                      <TableCell className="text-muted-foreground">{line.expiry_date ? formatDate(line.expiry_date) : '-'}</TableCell>
                      <TableCell className="text-right">{line.quantity}</TableCell>
                      <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                      <TableCell className="text-right">{line.tax_rate}%</TableCell>
                      <TableCell className="text-right">{formatCurrency(line.tax_amount)}</TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                      </>}
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={12} className="text-center text-muted-foreground py-8">
                      No line items
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
          </SalesLineColumnVisibility>
          {invoice.lines && invoice.lines.length > 0 && (
            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(invoice.subtotal)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(invoice.tax_amount)}</span>
                </div>
                {invoice.discount_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">-{formatCurrency(invoice.discount_amount)}</span>
                  </div>
                )}
                <div className="flex justify-between text-lg font-bold pt-2 border-t">
                  <span>Total:</span>
                  <span className="text-primary">{formatCurrency(invoice.total)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Paid:</span>
                  <span className="font-medium text-red-600">{formatCurrency(invoice.paid_amount)}</span>
                </div>
                <div className="flex justify-between text-lg font-bold">
                  <span>Balance Due:</span>
                  <span className="text-green-600">{formatCurrency(invoice.balance)}</span>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Payment History */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Payment History</span>
            {(invoice.status === 'posted' || invoice.status === 'sent') && invoice.balance > 0 && (
              <Button size="sm" onClick={() => navigate(`/accounting/customers/payments?invoice_id=${id}`)}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Record Payment
              </Button>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {invoice.payments && invoice.payments.length > 0 ? (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Payment #</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Method</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoice.payments.map((payment: any) => (
                    <TableRow key={payment.id}>
                      <TableCell className="font-medium">{payment.payment_number || `#${payment.id}`}</TableCell>
                      <TableCell>{formatDate(payment.payment_date)}</TableCell>
                      <TableCell className="capitalize">{payment.payment_method}</TableCell>
                      <TableCell>{payment.reference || '-'}</TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(payment.amount)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              <Icon icon="solar:wallet-money-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
              <p className="text-sm">No payments recorded yet</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Credit Notes */}
      {['posted', 'paid', 'partial'].includes(invoice.status) && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Credit Notes</CardTitle>
            <Button
              size="sm"
              variant="outline"
              onClick={() => setShowCreditNoteDialog(true)}
            >
              <Icon icon="solar:document-add-bold-duotone" className="mr-1.5 h-4 w-4" />
              Create Credit Note
            </Button>
          </CardHeader>
          <CardContent>
            <InvoiceCreditNotes
              invoiceId={invoice.id}
              invoiceTotal={invoice.total}
              currencyCode={invoice.currency_code}
            />
          </CardContent>
        </Card>
      )}

      {/* Journal Entry */}
      {invoice.journal_entry_id && (
        <Card>
          <CardHeader>
            <CardTitle>Journal Entry</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center justify-between p-4 bg-accent/50 rounded-lg">
              <div className="flex items-center gap-3">
                <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-primary" />
                <div>
                  <p className="font-medium">Journal Entry #{invoice.journal_entry_id}</p>
                  <p className="text-sm text-muted-foreground">Posted on {formatDate(invoice.invoice_date)}</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Credit Note Dialog */}
      {showCreditNoteDialog && (
        <CreateCreditNoteDialog
          invoiceId={invoice.id}
          onClose={() => setShowCreditNoteDialog(false)}
          onSuccess={() => {
            setShowCreditNoteDialog(false);
            queryClient.invalidateQueries({ queryKey: ['customer-invoice', id] });
            queryClient.invalidateQueries({ queryKey: ['invoice-credit-notes', invoice.id] });
          }}
        />
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {documentLabel}?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this {documentLabel.toLowerCase()}? This action cannot be undone.
              {invoice.status !== 'draft' && (
                <p className="mt-2 text-red-600 font-medium">
                  This {documentLabel.toLowerCase()} is {invoice.status} and cannot be deleted.
                </p>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteMutation.mutate()}
              disabled={invoice.status !== 'draft' || deleteMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={showCancelDialog} onOpenChange={setShowCancelDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cancel {documentLabel}?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to cancel {invoice.invoice_number}? This will mark it as cancelled.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Go Back</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => cancelMutation.mutate()}
              disabled={cancelMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {cancelMutation.isPending ? 'Cancelling...' : 'Cancel Invoice'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={showResetDialog} onOpenChange={setShowResetDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reset to Draft?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to reset {invoice.invoice_number} to draft? You will be able to edit and re-post it.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Go Back</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => resetToDraftMutation.mutate()}
              disabled={resetToDraftMutation.isPending}
            >
              {resetToDraftMutation.isPending ? 'Resetting...' : 'Reset to Draft'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default CustomerInvoiceDetail;
