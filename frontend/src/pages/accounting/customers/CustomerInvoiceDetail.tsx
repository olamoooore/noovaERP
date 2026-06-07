import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
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
import { formatDate, resolveImageUrl } from '@/lib/utils';
import api from '@/lib/api';
import InvoiceCreditNotes from '../components/InvoiceCreditNotes';
import CreateCreditNoteDialog from '../components/CreateCreditNoteDialog';

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

export const CustomerInvoiceDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [showCreditNoteDialog, setShowCreditNoteDialog] = useState(false);
  const isQuoteMode = location.pathname.startsWith('/sales/quotes');
  const documentLabel = isQuoteMode ? 'Quotation' : 'Invoice';
  const backPath = isQuoteMode ? '/sales/quotes' : '/accounting/customers/bills';

  // Fetch invoice details
  const { data: invoice, isLoading } = useQuery({
    queryKey: ['customer-invoice', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`);
      return response.data;
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

  const formatCurrency = (amount: number | string) => {
    const numAmount = typeof amount === 'string' ? parseFloat(amount) : amount;
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(numAmount || 0);
  };

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { variant: any; label: string; icon: string }> = {
      draft: { variant: 'secondary', label: 'Draft', icon: 'solar:document-bold-duotone' },
      sent: { variant: 'default', label: 'Sent', icon: 'solar:send-bold-duotone' },
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
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate(backPath)}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">{documentLabel} {invoice.invoice_number || `#${invoice.id}`}</h1>
            <p className="text-muted-foreground mt-1">Customer: {invoice.customer?.name || 'Unknown'}</p>
          </div>
        </div>
        <div className="flex gap-2">
          {invoice.status === 'draft' && (
            <>
              <Button variant="default" onClick={() => postMutation.mutate()}>
                <Icon icon="solar:send-bold-duotone" className="h-4 w-4 mr-2" />
                {isQuoteMode ? 'Convert to Invoice' : 'Post Invoice'}
              </Button>
              <Button variant="destructive" onClick={() => setShowDeleteDialog(true)}>
                <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 mr-2" />
                Delete
              </Button>
            </>
          )}
          <Button variant="outline">
            <Icon icon="solar:printer-bold-duotone" className="h-4 w-4 mr-2" />
            Print
          </Button>
          <Button variant="outline">
            <Icon icon="solar:download-bold-duotone" className="h-4 w-4 mr-2" />
            Export
          </Button>
        </div>
      </div>

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
          {invoice.notes && (
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
          {(invoice.customer_reference || invoice.internal_reference || invoice.attention_to || invoice.contact_email || invoice.contact_phone || invoice.company_registration_number || invoice.tax_registration_number || invoice.billing_address_snapshot || invoice.shipping_address_snapshot) && (
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
                      <p className="text-sm text-muted-foreground mb-2">Billing Address Snapshot</p>
                      <p className="text-sm whitespace-pre-wrap">{formatSnapshotAddress(invoice.billing_address_snapshot)}</p>
                    </div>
                  )}
                  {invoice.shipping_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-sm text-muted-foreground mb-2">Shipping Address Snapshot</p>
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
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product/Service</TableHead>
                  <TableHead>Description</TableHead>
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
                            {line.product?.name && line.description && line.product.name !== line.description && (
                              <p className="text-xs text-muted-foreground truncate">{line.description}</p>
                            )}
                          </div>
                        </div>
                      </TableCell>
                      <TableCell>{line.description || line.product?.name || '-'}</TableCell>
                      <TableCell className="text-right">{line.quantity}</TableCell>
                      <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                      <TableCell className="text-right">{line.tax_rate}%</TableCell>
                      <TableCell className="text-right">{formatCurrency(line.tax_amount)}</TableCell>
                      <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                      No line items
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
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
    </div>
  );
};

export default CustomerInvoiceDetail;
