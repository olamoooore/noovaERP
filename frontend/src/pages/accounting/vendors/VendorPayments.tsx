import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ImportButton } from '@/components/ImportButton';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';

export const VendorPayments: React.FC = () => {
  const [searchParams] = useSearchParams();
  const billIdParam = searchParams.get('bill_id') || '';
  const [showRecordPayment, setShowRecordPayment] = useState(!!billIdParam);
  const [searchQuery, setSearchQuery] = useState(searchParams.get('search') || '');
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // Fetch vendor payments
  const { data: payments, isLoading } = useQuery({
    queryKey: ['vendor-payments', searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (searchQuery) params.append('search', searchQuery);
      params.append('per_page', '1000'); // Get all payments
      
      const response = await api.get(`/accounting/bill-payments?${params.toString()}`);
      return response.data.data || [];
    },
  });

  // Fetch unpaid vendor bills for payment allocation
  const { data: unpaidBills } = useQuery({
    queryKey: ['unpaid-vendor-bills'],
    queryFn: async () => {
      const response = await api.get('/accounting/vendor-bills?status=posted,partial');
      return response.data.data || [];
    },
    enabled: showRecordPayment,
  });

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
          <h1 className="text-3xl font-bold">Vendor Payments</h1>
          <p className="text-muted-foreground mt-1">Track payments made to vendors/suppliers</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* Search sits with the actions so the page does not spend a whole
              row on one input. */}
          <input
            type="text"
            placeholder="Search by vendor, reference number..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="h-8 w-full rounded-full border bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring sm:w-72"
          />
          <ImportButton 
            importType="payments"
            onImportComplete={() => queryClient.invalidateQueries({ queryKey: ['vendor-payments'] })}
            variant="outline"
          />
          <Dialog open={showRecordPayment} onOpenChange={setShowRecordPayment}>
            <DialogTrigger asChild>
              <HeaderActionButton icon="solar:add-circle-bold-duotone">
                Record Payment
              </HeaderActionButton>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Record Vendor Payment</DialogTitle>
              </DialogHeader>
              <RecordPaymentForm
                unpaidBills={unpaidBills || []}
                initialBillId={billIdParam}
                onSuccess={() => {
                  setShowRecordPayment(false);
                  queryClient.invalidateQueries({ queryKey: ['vendor-payments'] });
                  toast({ title: 'Payment recorded successfully' });
                }}
              />
            </DialogContent>
          </Dialog>
        </div>
      </div>

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
              <p className="text-lg font-medium mb-2">No vendor payments recorded yet</p>
              <p className="text-sm mb-4">Record your first payment to start tracking</p>
              <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => setShowRecordPayment(true)}>
                Record Payment
              </HeaderActionButton>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Payment #</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Vendor</TableHead>
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
                      <TableCell>{payment.vendor?.name || payment.vendor_name || '-'}</TableCell>
                      <TableCell>{getPaymentMethodBadge(payment.payment_method)}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {payment.reference || '-'}
                      </TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(parseFloat(payment.amount) || 0)}
                      </TableCell>
                      <TableCell>
                        {payment.bill ? (
                          <span className="text-sm">
                            {payment.bill.bill_number || payment.bill.uuid?.slice(0, 8)}
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
                    {formatCurrency(payments.reduce((sum: number, p: any) => sum + (parseFloat(p.amount) || 0), 0))}
                  </p>
                </div>
                <Icon icon="solar:dollar-bold-duotone" className="h-8 w-8 text-red-500" />
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
  unpaidBills: any[];
  initialBillId?: string;
  onSuccess: () => void;
}> = ({ unpaidBills, initialBillId, onSuccess }) => {
  const [formData, setFormData] = useState({
    vendor_id: '',
    bill_id: '',
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    payment_method: 'bank',
    reference: '',
    memo: '',
  });

  // Preselect bill from URL param once bills are loaded
  useEffect(() => {
    if (initialBillId && unpaidBills.length > 0 && !formData.bill_id) {
      const bill = unpaidBills.find((b) => b.id === parseInt(initialBillId));
      if (bill) {
        setFormData((prev) => ({
          ...prev,
          bill_id: initialBillId,
          vendor_id: bill.vendor_id || '',
          amount: bill.balance || bill.total || '',
        }));
      }
    }
  }, [initialBillId, unpaidBills]);

  const { toast } = useToast();

  const recordPaymentMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/accounting/bill-payments', data);
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

  const selectedBill = unpaidBills.find((bill) => bill.id === parseInt(formData.bill_id));

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="bill_id">Vendor Bill</Label>
          <Select
            value={formData.bill_id}
            onValueChange={(value) => {
              const bill = unpaidBills.find((b) => b.id === parseInt(value));
              setFormData({
                ...formData,
                bill_id: value,
                vendor_id: bill?.vendor_id || '',
                amount: bill?.balance || bill?.total || '',
              });
            }}
          >
            <SelectTrigger>
              <SelectValue placeholder="Select bill" />
            </SelectTrigger>
            <SelectContent>
              {unpaidBills.map((bill) => (
                <SelectItem key={bill.id} value={bill.id.toString()}>
                  {bill.bill_number} - {bill.vendor?.name} (${bill.balance || bill.total})
                </SelectItem>
              ))}
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
          {selectedBill && (
            <p className="text-xs text-muted-foreground">
              Bill balance: ${selectedBill.balance || selectedBill.total}
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
          <Label htmlFor="payment_method">Payment Method</Label>
          <Select
            value={formData.payment_method}
            onValueChange={(value) => setFormData({ ...formData, payment_method: value })}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="cash">Cash</SelectItem>
              <SelectItem value="bank">Bank Transfer</SelectItem>
              <SelectItem value="card">Credit/Debit Card</SelectItem>
              <SelectItem value="check">Check</SelectItem>
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
        <Label htmlFor="memo">Notes (Optional)</Label>
        <Input
          id="memo"
          value={formData.memo}
          onChange={(e) => setFormData({ ...formData, memo: e.target.value })}
          placeholder="Additional notes..."
        />
      </div>

      <div className="bg-blue-50 dark:bg-blue-950/20 p-4 rounded-lg">
        <div className="flex items-start gap-3">
          <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-blue-600 mt-0.5" />
          <div className="text-sm text-blue-700 dark:text-blue-300">
            <p className="font-medium mb-1">Payment will be recorded</p>
            <p>The bill status will be updated to "Paid" and a journal entry will be created automatically.</p>
          </div>
        </div>
      </div>

      <div className="flex justify-end gap-2 pt-4">
        <Button type="submit" disabled={recordPaymentMutation.isPending}>
          {recordPaymentMutation.isPending ? 'Recording...' : 'Record Payment'}
        </Button>
      </div>
    </form>
  );
};

export default VendorPayments;
