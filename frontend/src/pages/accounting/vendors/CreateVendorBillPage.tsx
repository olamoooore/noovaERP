import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { accountingApi } from '@/lib/api';

interface BillLine {
  account_id: string;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
}

interface VendorRecord {
  id: number;
  name?: string;
  email?: string | null;
  phone?: string | null;
  company?: string | null;
  tax_number?: string | null;
  company_registration_number?: string | null;
  address?: string | null;
  contact_person?: string | null;
  website?: string | null;
  payment_terms?: number | null;
  notes?: string | null;
  is_active?: boolean;
}

interface VendorFormState {
  name: string;
  email: string;
  phone: string;
  company: string;
  tax_number: string;
  company_registration_number: string;
  address: string;
  contact_person: string;
  website: string;
  payment_terms: string;
  notes: string;
  is_active: boolean;
}

interface VendorDocumentSnapshot {
  vendor_reference: string;
  internal_reference: string;
  attention_to: string;
  contact_email: string;
  contact_phone: string;
  vendor_address_snapshot: string;
  remittance_address_snapshot: string;
  company_registration_number: string;
  tax_registration_number: string;
}

const emptyLine = (): BillLine => ({
  account_id: '',
  description: '',
  quantity: 1,
  unit_price: 0,
  tax_rate: 0,
});

const initialVendorForm: VendorFormState = {
  name: '',
  email: '',
  phone: '',
  company: '',
  tax_number: '',
  company_registration_number: '',
  address: '',
  contact_person: '',
  website: '',
  payment_terms: '30',
  notes: '',
  is_active: true,
};

const emptyDocumentSnapshot: VendorDocumentSnapshot = {
  vendor_reference: '',
  internal_reference: '',
  attention_to: '',
  contact_email: '',
  contact_phone: '',
  vendor_address_snapshot: '',
  remittance_address_snapshot: '',
  company_registration_number: '',
  tax_registration_number: '',
};

export const CreateVendorBillPage: React.FC = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const [vendorId, setVendorId] = useState('');
  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate] = useState(new Date().toISOString().split('T')[0]);
  const [dueDate, setDueDate] = useState(
    new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
  );
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState(0);
  const [lines, setLines] = useState<BillLine[]>([emptyLine()]);
  const [documentSnapshot, setDocumentSnapshot] = useState<VendorDocumentSnapshot>(emptyDocumentSnapshot);
  const [vendorDialogOpen, setVendorDialogOpen] = useState(false);
  const [vendorDialogMode, setVendorDialogMode] = useState<'create' | 'edit'>('create');
  const [vendorForm, setVendorForm] = useState<VendorFormState>(initialVendorForm);

  const { data: vendors } = useQuery<VendorRecord[]>({
    queryKey: ['vendors-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/accounting/vendors?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: selectedVendorDetail } = useQuery<VendorRecord>({
    queryKey: ['vendor-document', vendorId],
    queryFn: async () => {
      const response = await accountingApi.get(`/accounting/vendors/${vendorId}`);
      return response.data.data;
    },
    enabled: Boolean(vendorId),
  });

  const { data: accounts } = useQuery({
    queryKey: ['chart-of-accounts-list'],
    queryFn: async () => {
      const response = await accountingApi.get('/accounting/chart-of-accounts');
      return response.data.data || response.data || [];
    },
  });

  const selectedVendor = selectedVendorDetail
    ?? vendors?.find((vendor) => String(vendor.id) === String(vendorId));

  useEffect(() => {
    if (!selectedVendor) {
      return;
    }

    setDocumentSnapshot({
      vendor_reference: selectedVendor.company ?? selectedVendor.name ?? '',
      internal_reference: '',
      attention_to: selectedVendor.contact_person ?? selectedVendor.name ?? '',
      contact_email: selectedVendor.email ?? '',
      contact_phone: selectedVendor.phone ?? '',
      vendor_address_snapshot: selectedVendor.address ?? '',
      remittance_address_snapshot: selectedVendor.address ?? '',
      company_registration_number: selectedVendor.company_registration_number ?? '',
      tax_registration_number: selectedVendor.tax_number ?? '',
    });
  }, [selectedVendor]);

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await accountingApi.post('/accounting/vendor-bills', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: 'Vendor bill created successfully' });
      const billId = data?.data?.id || data?.id;
      if (billId) {
        navigate(`/accounting/vendors/bills/${billId}`);
      } else {
        navigate('/accounting/vendors/bills');
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create vendor bill',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      });
    },
  });

  const createVendorMutation = useMutation({
    mutationFn: async (payload: VendorFormState) => {
      const response = await accountingApi.post('/accounting/vendors', {
        ...payload,
        payment_terms: payload.payment_terms ? parseInt(payload.payment_terms, 10) : undefined,
      });
      return response.data.data as VendorRecord;
    },
    onSuccess: async (vendor) => {
      await queryClient.invalidateQueries({ queryKey: ['vendors-list'] });
      toast({ title: 'Vendor created successfully' });
      setVendorId(String(vendor.id));
      setVendorDialogOpen(false);
      setVendorForm(initialVendorForm);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create vendor',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const updateVendorMutation = useMutation({
    mutationFn: async (payload: VendorFormState) => {
      const response = await accountingApi.put(`/accounting/vendors/${vendorId}`, {
        ...payload,
        payment_terms: payload.payment_terms ? parseInt(payload.payment_terms, 10) : undefined,
      });
      return response.data.data as VendorRecord;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['vendors-list'] }),
        queryClient.invalidateQueries({ queryKey: ['vendor-document', vendorId] }),
      ]);
      toast({ title: 'Vendor updated successfully' });
      setVendorDialogOpen(false);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update vendor',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const updateLine = (index: number, field: keyof BillLine, value: string | number) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };
    setLines(updated);
  };

  const updateDocumentSnapshot = (field: keyof VendorDocumentSnapshot, value: string) => {
    setDocumentSnapshot((current) => ({
      ...current,
      [field]: value,
    }));
  };

  const addLine = () => setLines([...lines, emptyLine()]);

  const duplicateLine = (index: number) => {
    const targetLine = lines[index];
    if (!targetLine) return;
    const updated = [...lines];
    updated.splice(index + 1, 0, { ...targetLine });
    setLines(updated);
  };

  const moveLine = (index: number, direction: 'up' | 'down') => {
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= lines.length) {
      return;
    }

    const updated = [...lines];
    [updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]];
    setLines(updated);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, currentIndex) => currentIndex !== index));
    }
  };

  const getLineTotal = (line: BillLine) => {
    const base = line.quantity * line.unit_price;
    const tax = base * (line.tax_rate / 100);
    return base + tax;
  };

  const openCreateVendorDialog = () => {
    setVendorDialogMode('create');
    setVendorForm(initialVendorForm);
    setVendorDialogOpen(true);
  };

  const openEditVendorDialog = () => {
    if (!selectedVendor) {
      return;
    }

    setVendorDialogMode('edit');
    setVendorForm({
      name: selectedVendor.name ?? '',
      email: selectedVendor.email ?? '',
      phone: selectedVendor.phone ?? '',
      company: selectedVendor.company ?? '',
      tax_number: selectedVendor.tax_number ?? '',
      company_registration_number: selectedVendor.company_registration_number ?? '',
      address: selectedVendor.address ?? '',
      contact_person: selectedVendor.contact_person ?? '',
      website: selectedVendor.website ?? '',
      payment_terms: String(selectedVendor.payment_terms ?? 30),
      notes: selectedVendor.notes ?? '',
      is_active: selectedVendor.is_active ?? true,
    });
    setVendorDialogOpen(true);
  };

  const subtotal = lines.reduce((sum, line) => sum + line.quantity * line.unit_price, 0);
  const taxTotal = lines.reduce((sum, line) => sum + line.quantity * line.unit_price * (line.tax_rate / 100), 0);
  const documentDiscountAmount = discountMode === 'percentage'
    ? subtotal * (discountAmount / 100)
    : discountAmount;
  const total = subtotal + taxTotal - documentDiscountAmount;
  const billNumberPreview = billNumber || `BILL-${new Date(billDate).getFullYear()}-XXXX`;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleVendorFormSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (vendorDialogMode === 'edit') {
      updateVendorMutation.mutate(vendorForm);
      return;
    }

    createVendorMutation.mutate(vendorForm);
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!vendorId) {
      toast({ variant: 'destructive', title: 'Please select a vendor' });
      return;
    }

    const validLines = lines.filter((line) => line.account_id && line.quantity > 0 && line.unit_price > 0);
    if (validLines.length === 0) {
      toast({ variant: 'destructive', title: 'Please add at least one line item with an account, quantity, and price' });
      return;
    }

    const payload = {
      vendor_id: parseInt(vendorId, 10),
      bill_number: billNumber || undefined,
      bill_date: billDate,
      due_date: dueDate,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: documentDiscountAmount || 0,
      ...documentSnapshot,
      lines: validLines.map((line) => ({
        account_id: parseInt(line.account_id, 10),
        description: line.description || undefined,
        quantity: line.quantity,
        unit_price: line.unit_price,
        tax_rate: line.tax_rate,
      })),
    };

    createMutation.mutate(payload);
  };

  const vendorMutationPending = createVendorMutation.isPending || updateVendorMutation.isPending;

  return (
    <>
      <div className="p-6 space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="sm" onClick={() => navigate('/accounting/vendors/bills')}>
              <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
              Back
            </Button>
            <div>
              <h1 className="text-3xl font-bold">Create Vendor Bill</h1>
              <p className="text-muted-foreground mt-1">Record a new bill from a vendor</p>
            </div>
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>Bill Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor *</label>
                  <select
                    value={vendorId}
                    onChange={(event) => setVendorId(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    required
                  >
                    <option value="">Select vendor...</option>
                    {vendors?.map((vendor) => (
                      <option key={vendor.id} value={vendor.id}>
                        {vendor.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Bill Number</label>
                  <div className="space-y-2">
                    <input
                      type="text"
                      value={billNumber}
                      onChange={(event) => setBillNumber(event.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                      placeholder="Auto-generated if blank"
                    />
                    <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                      Preview: <span className="font-semibold text-slate-900">{billNumberPreview}</span>
                    </div>
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Bill Date *</label>
                  <input
                    type="date"
                    value={billDate}
                    onChange={(event) => setBillDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Due Date</label>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(event) => setDueDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                <Button type="button" variant="outline" size="sm" onClick={openCreateVendorDialog}>
                  <Icon icon="solar:add-square-bold-duotone" className="h-4 w-4 mr-2" />
                  Add Vendor Inline
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={openEditVendorDialog} disabled={!selectedVendor}>
                  <Icon icon="solar:pen-bold-duotone" className="h-4 w-4 mr-2" />
                  Edit Selected Vendor
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={() => navigate('/accounting/core/chart-of-accounts')}>
                  <Icon icon="solar:book-bookmark-bold-duotone" className="h-4 w-4 mr-2" />
                  Chart of Accounts
                </Button>
              </div>

              {selectedVendor && (
                <div className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Vendor</div>
                    <div className="mt-1 font-medium text-slate-900">{selectedVendor.name}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Email / Phone</div>
                    <div className="mt-1 text-sm text-slate-700">{selectedVendor.email || selectedVendor.phone || 'Not captured'}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Business Reference</div>
                    <div className="mt-1 text-sm text-slate-700">
                      {selectedVendor.tax_number || selectedVendor.company_registration_number || selectedVendor.company || 'No tax or registration reference on file'}
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.vendor_reference}
                    onChange={(event) => updateDocumentSnapshot('vendor_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Supplier invoice / account"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Internal Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.internal_reference}
                    onChange={(event) => updateDocumentSnapshot('internal_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="PO / project / request"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Attention To</label>
                  <input
                    type="text"
                    value={documentSnapshot.attention_to}
                    onChange={(event) => updateDocumentSnapshot('attention_to', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor contact"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Email</label>
                  <input
                    type="email"
                    value={documentSnapshot.contact_email}
                    onChange={(event) => updateDocumentSnapshot('contact_email', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="ap@vendor.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Phone</label>
                  <input
                    type="text"
                    value={documentSnapshot.contact_phone}
                    onChange={(event) => updateDocumentSnapshot('contact_phone', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor phone"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Company Reg. No.</label>
                  <input
                    type="text"
                    value={documentSnapshot.company_registration_number}
                    onChange={(event) => updateDocumentSnapshot('company_registration_number', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Business registration number"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Tax Registration</label>
                  <input
                    type="text"
                    value={documentSnapshot.tax_registration_number}
                    onChange={(event) => updateDocumentSnapshot('tax_registration_number', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="GST / VAT / TIN"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Vendor Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.vendor_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('vendor_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Vendor billing address"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Remittance Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.remittance_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('remittance_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Where the vendor expects payment correspondence"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Notes</label>
                  <textarea
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    rows={2}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Internal notes..."
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Terms</label>
                  <textarea
                    value={terms}
                    onChange={(event) => setTerms(event.target.value)}
                    rows={2}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Payment terms..."
                  />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="mb-6">
            <CardHeader>
              <CardTitle className="flex items-center justify-between">
                <span>Line Items</span>
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={addLine}>
                    <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                    Add Line
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={() => duplicateLine(lines.length - 1)} disabled={lines.length === 0}>
                    <Icon icon="solar:copy-bold-duotone" className="h-4 w-4 mr-1" />
                    Duplicate Last
                  </Button>
                </div>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b">
                      <th className="text-left py-2 px-2 w-12">#</th>
                      <th className="text-left py-2 px-2">Account *</th>
                      <th className="text-left py-2 px-2">Description</th>
                      <th className="text-right py-2 px-2 w-24">Qty</th>
                      <th className="text-right py-2 px-2 w-32">Unit Price</th>
                      <th className="text-right py-2 px-2 w-24">Tax %</th>
                      <th className="text-right py-2 px-2 w-32">Line Total</th>
                      <th className="py-2 px-2 w-40">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((line, index) => (
                      <tr key={index} className="border-b">
                        <td className="py-2 px-2 text-sm font-medium text-slate-500">{index + 1}</td>
                        <td className="py-2 px-2">
                          <select
                            value={line.account_id}
                            onChange={(event) => updateLine(index, 'account_id', event.target.value)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                            required
                          >
                            <option value="">Select account...</option>
                            {accounts?.map((account: any) => (
                              <option key={account.id} value={account.id}>
                                {account.code} - {account.name}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="text"
                            value={line.description}
                            onChange={(event) => updateLine(index, 'description', event.target.value)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                            placeholder="Description"
                          />
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={line.quantity}
                            onChange={(event) => updateLine(index, 'quantity', parseFloat(event.target.value) || 0)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          />
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={line.unit_price}
                            onChange={(event) => updateLine(index, 'unit_price', parseFloat(event.target.value) || 0)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          />
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            value={line.tax_rate}
                            onChange={(event) => updateLine(index, 'tax_rate', parseFloat(event.target.value) || 0)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          />
                        </td>
                        <td className="py-2 px-2 text-right font-medium">
                          {formatCurrency(getLineTotal(line))}
                        </td>
                        <td className="py-2 px-2">
                          <div className="flex items-center justify-end gap-1">
                            <Button type="button" variant="ghost" size="sm" onClick={() => moveLine(index, 'up')} disabled={index === 0}>
                              <Icon icon="solar:arrow-up-bold-duotone" className="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => moveLine(index, 'down')} disabled={index === lines.length - 1}>
                              <Icon icon="solar:arrow-down-bold-duotone" className="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" onClick={() => duplicateLine(index)}>
                              <Icon icon="solar:copy-bold-duotone" className="h-4 w-4" />
                            </Button>
                            {lines.length > 1 && (
                              <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(index)}>
                                <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="mt-6 flex justify-end">
                <div className="w-full max-w-sm space-y-2">
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Subtotal:</span>
                    <span className="font-medium">{formatCurrency(subtotal)}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 text-sm">
                    <span className="text-muted-foreground">Document Discount:</span>
                    <div className="flex items-center gap-2">
                      <select
                        value={discountMode}
                        onChange={(event) => setDiscountMode(event.target.value as 'fixed' | 'percentage')}
                        className="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                      >
                        <option value="fixed">Fixed</option>
                        <option value="percentage">%</option>
                      </select>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={discountAmount}
                        onChange={(event) => setDiscountAmount(parseFloat(event.target.value) || 0)}
                        className="w-28 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                      />
                    </div>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Tax:</span>
                    <span className="font-medium">{formatCurrency(taxTotal)}</span>
                  </div>
                  {documentDiscountAmount > 0 && (
                    <div className="flex justify-between text-sm">
                      <span className="text-muted-foreground">Discount:</span>
                      <span className="font-medium text-green-600">-{formatCurrency(documentDiscountAmount)}</span>
                    </div>
                  )}
                  <div className="flex justify-between text-lg font-bold pt-2 border-t">
                    <span>Total:</span>
                    <span className="text-primary">{formatCurrency(total)}</span>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => navigate('/accounting/vendors/bills')}>
              Cancel
            </Button>
            <Button type="submit" disabled={createMutation.isPending}>
              {createMutation.isPending ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                  Creating...
                </>
              ) : (
                <>
                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                  Create Bill
                </>
              )}
            </Button>
          </div>
        </form>
      </div>

      <Dialog open={vendorDialogOpen} onOpenChange={setVendorDialogOpen}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{vendorDialogMode === 'create' ? 'Add Vendor' : 'Edit Vendor'}</DialogTitle>
          </DialogHeader>

          <form onSubmit={handleVendorFormSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Vendor Name *</label>
                <input
                  type="text"
                  value={vendorForm.name}
                  onChange={(event) => setVendorForm((current) => ({ ...current, name: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Company</label>
                <input
                  type="text"
                  value={vendorForm.company}
                  onChange={(event) => setVendorForm((current) => ({ ...current, company: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Email</label>
                <input
                  type="email"
                  value={vendorForm.email}
                  onChange={(event) => setVendorForm((current) => ({ ...current, email: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Phone</label>
                <input
                  type="text"
                  value={vendorForm.phone}
                  onChange={(event) => setVendorForm((current) => ({ ...current, phone: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Contact Person</label>
                <input
                  type="text"
                  value={vendorForm.contact_person}
                  onChange={(event) => setVendorForm((current) => ({ ...current, contact_person: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Website</label>
                <input
                  type="url"
                  value={vendorForm.website}
                  onChange={(event) => setVendorForm((current) => ({ ...current, website: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Tax Registration</label>
                <input
                  type="text"
                  value={vendorForm.tax_number}
                  onChange={(event) => setVendorForm((current) => ({ ...current, tax_number: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Company Registration</label>
                <input
                  type="text"
                  value={vendorForm.company_registration_number}
                  onChange={(event) => setVendorForm((current) => ({ ...current, company_registration_number: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Payment Terms (days)</label>
                <input
                  type="number"
                  min="0"
                  value={vendorForm.payment_terms}
                  onChange={(event) => setVendorForm((current) => ({ ...current, payment_terms: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Status</label>
                <select
                  value={vendorForm.is_active ? 'active' : 'inactive'}
                  onChange={(event) => setVendorForm((current) => ({ ...current, is_active: event.target.value === 'active' }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">Address</label>
              <textarea
                value={vendorForm.address}
                onChange={(event) => setVendorForm((current) => ({ ...current, address: event.target.value }))}
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
              />
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">Notes</label>
              <textarea
                value={vendorForm.notes}
                onChange={(event) => setVendorForm((current) => ({ ...current, notes: event.target.value }))}
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setVendorDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={vendorMutationPending}>
                {vendorMutationPending ? 'Saving...' : vendorDialogMode === 'create' ? 'Create Vendor' : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default CreateVendorBillPage;