import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

export const CustomerPayments: React.FC = () => {
  const [searchParams] = useSearchParams();
  const invoiceIdParam = searchParams.get('invoice_id') || '';
  const [showRecordPayment, setShowRecordPayment] = useState(!!invoiceIdParam);
  const [searchQuery, setSearchQuery] = useState(searchParams.get('search') || '');
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // Fetch payments
  const { data: payments, isLoading } = useQuery({
    queryKey: ['customer-payments', searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (searchQuery) params.append('search', searchQuery);
      
      const response = await api.get(`/payments?${params.toString()}`);
      return response.data.data || [];
    },
  });

  // Fetch unpaid invoices for payment allocation.
  // Eligible statuses: 'posted' (released to GL, not yet paid), 'sent', 'overdue', 'partial'.
  // 'draft' invoices are excluded — they have not been released to the GL.
  const { data: unpaidInvoices } = useQuery({
    queryKey: ['unpaid-invoices'],
    queryFn: async () => {
      const response = await api.get('/invoices?status=posted,sent,overdue,partial');
      return response.data.data || [];
    },
    enabled: showRecordPayment,
  });

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount || 0);
  };

  const formatDate = (date: string) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  const getPaymentMethodBadge = (method: string) => {
    const icons: Record<string, string> = {
      cash: 'solar:wallet-bold-duotone',
      bank: 'solar:card-bold-duotone',
      card: 'solar:card-2-bold-duotone',
      check: 'solar:document-bold-duotone',
    };

    return (
      <Badge variant="outline" className="gap-1">
        <Icon icon={icons[method] || 'solar:wallet-bold-duotone'} className="h-3 w-3" />
        {method.charAt(0).toUpperCase() + method.slice(1)}
      </Badge>
    );
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customer Payments</h1>
          <p className="text-muted-foreground mt-1">Track payments received from customers</p>
        </div>
        <Dialog open={showRecordPayment} onOpenChange={setShowRecordPayment}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
              Record Payment
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>Record Customer Payment</DialogTitle>
            </DialogHeader>
            <RecordPaymentForm
              unpaidInvoices={unpaidInvoices || []}
              initialInvoiceId={invoiceIdParam}
              onSuccess={() => {
                setShowRecordPayment(false);
                queryClient.invalidateQueries({ queryKey: ['customer-payments'] });
                toast({ title: 'Payment recorded successfully' });
              }}
            />
          </DialogContent>
        </Dialog>
      </div>

      {/* Search */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <input
              type="text"
              placeholder="Search by customer, reference number..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </CardContent>
      </Card>

      {/* Payments List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Payment History</span>
            {payments && payments.length > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {payments.length} payment(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading payments...</p>
            </div>
          ) : !payments || payments.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:wallet-money-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No customer payments recorded yet</p>
              <p className="text-sm mb-4">Record your first payment to start tracking</p>
              <Button onClick={() => setShowRecordPayment(true)}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Record Payment
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Payment #</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Method</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Allocated To</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {payments.map((payment: any) => (
                    <TableRow key={payment.id} className="hover:bg-accent/50">
                      <TableCell className="font-medium">
                        {payment.payment_number || `PAY-${payment.id}`}
                      </TableCell>
                      <TableCell>{formatDate(payment.payment_date)}</TableCell>
                      <TableCell>{payment.customer?.name || payment.customer_name || '-'}</TableCell>
                      <TableCell>{getPaymentMethodBadge(payment.method)}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {payment.reference || '-'}
                      </TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(payment.amount)}
                      </TableCell>
                      <TableCell>
                        {payment.invoice ? (
                          <span className="text-sm">
                            {payment.invoice.invoice_number}
                          </span>
                        ) : (
                          <span className="text-sm text-muted-foreground">Unallocated</span>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Quick Stats */}
      {payments && payments.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Payments</p>
                  <p className="text-2xl font-bold">{payments.length}</p>
                </div>
                <Icon icon="solar:wallet-money-bold-duotone" className="h-8 w-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Amount</p>
                  <p className="text-2xl font-bold">
                    {formatCurrency(payments.reduce((sum: number, p: any) => sum + (p.amount || 0), 0))}
                  </p>
                </div>
                <Icon icon="solar:dollar-bold-duotone" className="h-8 w-8 text-green-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">This Month</p>
                  <p className="text-2xl font-bold">
                    {payments.filter((p: any) => {
                      const paymentDate = new Date(p.payment_date);
                      const now = new Date();
                      return paymentDate.getMonth() === now.getMonth() && 
                             paymentDate.getFullYear() === now.getFullYear();
                    }).length}
                  </p>
                </div>
                <Icon icon="solar:calendar-bold-duotone" className="h-8 w-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

// Record Payment Form Component
const RecordPaymentForm: React.FC<{
  unpaidInvoices: any[];
  initialInvoiceId?: string;
  onSuccess: () => void;
}> = ({ unpaidInvoices, initialInvoiceId, onSuccess }) => {
  const [formData, setFormData] = useState({
    customer_id: '',
    invoice_id: '',
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    method: 'bank_transfer',
    reference: '',
    notes: '',
  });

  // Preselect invoice from URL param once invoices are loaded
  useEffect(() => {
    if (initialInvoiceId && unpaidInvoices.length > 0 && !formData.invoice_id) {
      const invoice = unpaidInvoices.find((inv) => inv.id === parseInt(initialInvoiceId));
      if (invoice) {
        setFormData((prev) => ({
          ...prev,
          invoice_id: initialInvoiceId,
          customer_id: invoice.customer_id || '',
          amount: invoice.balance || invoice.total || '',
        }));
      }
    }
  }, [initialInvoiceId, unpaidInvoices]);

  const { toast } = useToast();

  const recordPaymentMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/payments', data);
      return response.data;
    },
    onSuccess: () => {
      onSuccess();
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to record payment',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    recordPaymentMutation.mutate(formData);
  };

  const selectedInvoice = unpaidInvoices.find((inv) => inv.id === parseInt(formData.invoice_id));

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="invoice_id">Invoice</Label>
          <Select
            value={formData.invoice_id}
            onValueChange={(value) => {
              const invoice = unpaidInvoices.find((inv) => inv.id === parseInt(value));
              setFormData({
                ...formData,
                invoice_id: value,
                customer_id: invoice?.customer_id || '',
                amount: invoice?.balance || invoice?.total || '',
              });
            }}
          >
            <SelectTrigger>
              <SelectValue placeholder="Select invoice" />
            </SelectTrigger>
            <SelectContent>
              {unpaidInvoices.length === 0 ? (
                <div className="px-3 py-4 text-sm text-muted-foreground text-center">
                  No eligible invoices found. Only posted, unpaid invoices can be paid.
                </div>
              ) : (
                unpaidInvoices.map((invoice) => (
                  <SelectItem key={invoice.id} value={invoice.id.toString()}>
                    {invoice.invoice_number} - {invoice.customer?.name} (${invoice.balance || invoice.total})
                  </SelectItem>
                ))
              )}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="amount">Amount</Label>
          <Input
            id="amount"
            type="number"
            step="0.01"
            value={formData.amount}
            onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
            required
          />
          {selectedInvoice && (
            <p className="text-xs text-muted-foreground">
              Invoice balance: ${selectedInvoice.balance || selectedInvoice.total}
            </p>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="payment_date">Payment Date</Label>
          <Input
            id="payment_date"
            type="date"
            value={formData.payment_date}
            onChange={(e) => setFormData({ ...formData, payment_date: e.target.value })}
            required
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="method">Payment Method</Label>
          <Select
            value={formData.method}
            onValueChange={(value) => setFormData({ ...formData, method: value })}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="cash">Cash</SelectItem>
              <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
              <SelectItem value="card">Credit/Debit Card</SelectItem>
              <SelectItem value="check">Check</SelectItem>
              <SelectItem value="other">Other</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-2">
        <Label htmlFor="reference">Reference Number</Label>
        <Input
          id="reference"
          value={formData.reference}
          onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
          placeholder="Transaction ID, check number, etc."
        />
      </div>

      <div className="space-y-2">
        <Label htmlFor="notes">Notes (Optional)</Label>
        <Input
          id="notes"
          value={formData.notes}
          onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
          placeholder="Additional notes..."
        />
      </div>

      <div className="flex justify-end gap-2 pt-4">
        <Button type="submit" disabled={recordPaymentMutation.isPending}>
          {recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment'}
        </Button>
      </div>
    </form>
  );
};

export default CustomerPayments;
