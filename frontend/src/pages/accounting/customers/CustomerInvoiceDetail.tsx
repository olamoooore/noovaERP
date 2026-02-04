import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
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
import api from '@/lib/api';

export const CustomerInvoiceDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);

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
      toast({ title: 'Invoice deleted successfully' });
      navigate('/accounting/customers/bills');
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to delete invoice',
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
      toast({ title: 'Invoice posted successfully' });
      queryClient.invalidateQueries({ queryKey: ['customer-invoice', id] });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post invoice',
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

  const formatDate = (date: string) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
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
          <p className="text-muted-foreground mt-4">Loading invoice details...</p>
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
              <p className="text-lg font-medium mb-2">Invoice not found</p>
              <p className="text-sm text-muted-foreground mb-4">The invoice you're looking for doesn't exist</p>
              <Button onClick={() => navigate('/accounting/customers/bills')}>
                <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
                Back to Invoices
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
          <Button variant="ghost" size="sm" onClick={() => navigate('/accounting/customers/bills')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Invoice {invoice.invoice_number || `#${invoice.id}`}</h1>
            <p className="text-muted-foreground mt-1">Customer: {invoice.customer?.name || 'Unknown'}</p>
          </div>
        </div>
        <div className="flex gap-2">
          {invoice.status === 'draft' && (
            <>
              <Button variant="default" onClick={() => postMutation.mutate()}>
                <Icon icon="solar:send-bold-duotone" className="h-4 w-4 mr-2" />
                Post Invoice
              </Button>
              <Button variant="outline" onClick={() => navigate(`/accounting/customers/bills/${id}/edit`)}>
                <Icon icon="solar:pen-bold-duotone" className="h-4 w-4 mr-2" />
                Edit
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
            <span>Invoice Information</span>
            {getStatusBadge(invoice.status)}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
              <p className="text-sm text-muted-foreground mb-1">Invoice Number</p>
              <p className="font-medium">{invoice.invoice_number || `#${invoice.id}`}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Customer</p>
              <p className="font-medium">{invoice.customer?.name || 'Unknown'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Invoice Date</p>
              <p className="font-medium">{formatDate(invoice.invoice_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Due Date</p>
              <p className="font-medium">{formatDate(invoice.due_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Subtotal</p>
              <p className="font-medium">{formatCurrency(invoice.subtotal)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Tax Amount</p>
              <p className="font-medium">{formatCurrency(invoice.tax_amount)}</p>
            </div>
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
                      <TableCell className="font-medium">
                        {line.product?.name || '-'}
                      </TableCell>
                      <TableCell>{line.description || '-'}</TableCell>
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
              <Button
                variant="outline"
                size="sm"
                onClick={() => navigate(`/accounting/journal-entries/${invoice.journal_entry_id}`)}
              >
                <Icon icon="solar:eye-bold-duotone" className="h-4 w-4 mr-2" />
                View Entry
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Customer Invoice?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this invoice? This action cannot be undone.
              {invoice.status !== 'draft' && (
                <p className="mt-2 text-red-600 font-medium">
                  This invoice is {invoice.status} and cannot be deleted. Please cancel it instead.
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
