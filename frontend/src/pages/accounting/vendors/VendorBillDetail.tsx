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
import api, { accountingApi } from '@/lib/api';

export const VendorBillDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);

  // Fetch bill details
  const { data: bill, isLoading } = useQuery({
    queryKey: ['vendor-bill', id],
    queryFn: async () => {
      const response = await accountingApi.get(`/accounting/vendor-bills/${id}`);
      return response.data.data;
    },
    enabled: !!id,
  });

  // Fetch payments for this bill
  const { data: payments } = useQuery({
    queryKey: ['bill-payments', id],
    queryFn: async () => {
      const response = await accountingApi.get(`/accounting/bill-payments?bill_id=${id}`);
      return response.data.data || [];
    },
    enabled: !!id,
  });

  // Post bill mutation
  const postMutation = useMutation({
    mutationFn: async () => {
      const response = await accountingApi.post(`/accounting/vendor-bills/${id}/post`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-bill', id] });
      queryClient.invalidateQueries({ queryKey: ['vendor-bills'] });
      toast({ title: 'Bill posted successfully' });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post bill',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async () => {
      await accountingApi.delete(`/accounting/vendor-bills/${id}`);
    },
    onSuccess: () => {
      toast({ title: 'Bill deleted successfully' });
      navigate('/accounting/vendors/bills');
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to delete bill',
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
      posted: { variant: 'default', label: 'Posted', icon: 'solar:check-circle-bold-duotone' },
      paid: { variant: 'default', label: 'Paid', icon: 'solar:wallet-money-bold-duotone' },
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
          <p className="text-muted-foreground mt-4">Loading bill details...</p>
        </div>
      </div>
    );
  }

  if (!bill) {
    return (
      <div className="p-6">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center py-12">
              <Icon icon="solar:bill-list-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50 text-muted-foreground" />
              <p className="text-lg font-medium mb-2">Bill not found</p>
              <p className="text-sm text-muted-foreground mb-4">The bill you're looking for doesn't exist</p>
              <Button onClick={() => navigate('/accounting/vendors/bills')}>
                <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
                Back to Bills
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
          <Button variant="ghost" size="sm" onClick={() => navigate('/accounting/vendors/bills')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Bill {bill.bill_number || `#${bill.id}`}</h1>
            <p className="text-muted-foreground mt-1">Vendor: {bill.vendor?.name || 'Unknown'}</p>
          </div>
        </div>
        <div className="flex gap-2">
          {bill.status === 'draft' && (
            <>
              <Button
                onClick={() => postMutation.mutate()}
                disabled={postMutation.isPending}
              >
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                {postMutation.isPending ? 'Posting...' : 'Post Bill'}
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

      {/* Bill Header */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Bill Information</span>
            {getStatusBadge(bill.status)}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
              <p className="text-sm text-muted-foreground mb-1">Bill Number</p>
              <p className="font-medium">{bill.bill_number || `#${bill.id}`}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Vendor</p>
              <p className="font-medium">{bill.vendor?.name || 'Unknown'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Bill Date</p>
              <p className="font-medium">{formatDate(bill.bill_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Due Date</p>
              <p className="font-medium">{formatDate(bill.due_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Subtotal</p>
              <p className="font-medium">{formatCurrency(bill.subtotal)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Tax Amount</p>
              <p className="font-medium">{formatCurrency(bill.tax_amount)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Total Amount</p>
              <p className="text-xl font-bold text-primary">{formatCurrency(bill.total)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground mb-1">Balance Due</p>
              <p className="text-xl font-bold text-red-600">{formatCurrency(bill.balance)}</p>
            </div>
          </div>
          {bill.notes && (
            <div className="mt-6 pt-6 border-t">
              <p className="text-sm text-muted-foreground mb-1">Notes</p>
              <p className="text-sm">{bill.notes}</p>
            </div>
          )}
          {(bill.vendor_reference || bill.internal_reference || bill.attention_to || bill.contact_email || bill.contact_phone || bill.company_registration_number || bill.tax_registration_number || bill.vendor_address_snapshot || bill.remittance_address_snapshot) && (
            <div className="mt-6 pt-6 border-t space-y-6">
              <div>
                <p className="text-sm font-medium mb-3">Commercial Snapshot</p>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                  {bill.vendor_reference && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Vendor Reference</p>
                      <p className="font-medium">{bill.vendor_reference}</p>
                    </div>
                  )}
                  {bill.internal_reference && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Internal Reference</p>
                      <p className="font-medium">{bill.internal_reference}</p>
                    </div>
                  )}
                  {bill.attention_to && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Attention To</p>
                      <p className="font-medium">{bill.attention_to}</p>
                    </div>
                  )}
                  {bill.contact_email && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Contact Email</p>
                      <p className="font-medium">{bill.contact_email}</p>
                    </div>
                  )}
                  {bill.contact_phone && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Contact Phone</p>
                      <p className="font-medium">{bill.contact_phone}</p>
                    </div>
                  )}
                  {bill.company_registration_number && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Company Registration</p>
                      <p className="font-medium">{bill.company_registration_number}</p>
                    </div>
                  )}
                  {bill.tax_registration_number && (
                    <div>
                      <p className="text-sm text-muted-foreground mb-1">Tax Registration</p>
                      <p className="font-medium">{bill.tax_registration_number}</p>
                    </div>
                  )}
                </div>
              </div>
              {(bill.vendor_address_snapshot || bill.remittance_address_snapshot) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {bill.vendor_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-sm text-muted-foreground mb-2">Vendor Address Snapshot</p>
                      <p className="text-sm whitespace-pre-wrap">{bill.vendor_address_snapshot}</p>
                    </div>
                  )}
                  {bill.remittance_address_snapshot && (
                    <div className="rounded-lg border p-4 bg-muted/20">
                      <p className="text-sm text-muted-foreground mb-2">Remittance Address Snapshot</p>
                      <p className="text-sm whitespace-pre-wrap">{bill.remittance_address_snapshot}</p>
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
                  <TableHead>Account</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax Rate</TableHead>
                  <TableHead className="text-right">Tax Amount</TableHead>
                  <TableHead className="text-right">Line Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {bill.lines && bill.lines.length > 0 ? (
                  bill.lines.map((line: any, index: number) => (
                    <TableRow key={index}>
                      <TableCell className="font-medium">
                        {line.account?.code} - {line.account?.name}
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
          {bill.lines && bill.lines.length > 0 && (
            <div className="mt-6 flex justify-end">
              <div className="w-full max-w-sm space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal:</span>
                  <span className="font-medium">{formatCurrency(bill.subtotal)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Tax:</span>
                  <span className="font-medium">{formatCurrency(bill.tax_amount)}</span>
                </div>
                {bill.discount_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount:</span>
                    <span className="font-medium text-green-600">-{formatCurrency(bill.discount_amount)}</span>
                  </div>
                )}
                <div className="flex justify-between text-lg font-bold pt-2 border-t">
                  <span>Total:</span>
                  <span className="text-primary">{formatCurrency(bill.total)}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Paid:</span>
                  <span className="font-medium text-green-600">{formatCurrency(bill.paid_amount)}</span>
                </div>
                <div className="flex justify-between text-lg font-bold">
                  <span>Balance Due:</span>
                  <span className="text-red-600">{formatCurrency(bill.balance)}</span>
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
            {bill.status === 'posted' && bill.balance > 0 && (
              <Button size="sm" onClick={() => navigate(`/accounting/vendors/payments?bill_id=${id}`)}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Record Payment
              </Button>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {payments && payments.length > 0 ? (
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
                  {payments.map((payment: any) => (
                    <TableRow key={payment.id}>
                      <TableCell className="font-medium">{payment.payment_number}</TableCell>
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
      {bill.journal_entry_id && (
        <Card>
          <CardHeader>
            <CardTitle>Journal Entry</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center justify-between p-4 bg-accent/50 rounded-lg">
              <div className="flex items-center gap-3">
                <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-primary" />
                <div>
                  <p className="font-medium">Journal Entry #{bill.journal_entry_id}</p>
                  <p className="text-sm text-muted-foreground">Posted on {formatDate(bill.bill_date)}</p>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Vendor Bill?</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this bill? This action cannot be undone.
              {bill.status === 'posted' && (
                <p className="mt-2 text-red-600 font-medium">
                  This bill is posted and cannot be deleted. Please cancel it instead.
                </p>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteMutation.mutate()}
              disabled={bill.status === 'posted' || deleteMutation.isPending}
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

export default VendorBillDetail;
