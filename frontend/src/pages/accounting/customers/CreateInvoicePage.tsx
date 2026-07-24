import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocation, useNavigate } from 'react-router-dom';
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
import { getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api';

interface InvoiceLine {
  product_id: string;
  description: string;
  quantity: number;
  unit_price: number | '';
  tax_rate: number | '';
  discount_amount: number | '';
}

interface CustomerAddress {
  type?: 'billing' | 'shipping' | 'other';
  street?: string | null;
  street2?: string | null;
  city?: string | null;
  state?: string | null;
  zip?: string | null;
  country?: string | null;
  is_default?: boolean;
}

interface CustomerContact {
  name?: string | null;
  email?: string | null;
  phone?: string | null;
  mobile?: string | null;
  is_primary?: boolean;
}

interface CustomerRecord {
  id: number;
  type?: 'individual' | 'company';
  name?: string;
  company?: string | null;
  code?: string | null;
  email?: string | null;
  phone?: string | null;
  mobile?: string | null;
  tax_number?: string | null;
  company_registration_number?: string | null;
  website?: string | null;
  payment_terms?: number | null;
  notes?: string | null;
  billing_address?: string | null;
  shipping_address?: string | null;
  addresses?: CustomerAddress[];
  customer_contacts?: CustomerContact[];
  customerContacts?: CustomerContact[];
}

interface CustomerFormState {
  type: 'individual' | 'company';
  name: string;
  company: string;
  email: string;
  phone: string;
  mobile: string;
  tax_number: string;
  company_registration_number: string;
  website: string;
  billing_address: string;
  shipping_address: string;
  payment_terms: string;
  notes: string;
}

interface InvoiceDocumentSnapshot {
  customer_reference: string;
  internal_reference: string;
  attention_to: string;
  contact_email: string;
  contact_phone: string;
  billing_address_snapshot: string;
  shipping_address_snapshot: string;
  company_registration_number: string;
  tax_registration_number: string;
}

const emptyLine = (): InvoiceLine => ({
  product_id: '',
  description: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
  discount_amount: '',
});

const initialCustomerForm: CustomerFormState = {
  type: 'company',
  name: '',
  company: '',
  email: '',
  phone: '',
  mobile: '',
  tax_number: '',
  company_registration_number: '',
  website: '',
  billing_address: '',
  shipping_address: '',
  payment_terms: '30',
  notes: '',
};

const emptyDocumentSnapshot: InvoiceDocumentSnapshot = {
  customer_reference: '',
  internal_reference: '',
  attention_to: '',
  contact_email: '',
  contact_phone: '',
  billing_address_snapshot: '',
  shipping_address_snapshot: '',
  company_registration_number: '',
  tax_registration_number: '',
};

const formatAddress = (address?: CustomerAddress | null): string => {
  if (!address) {
    return '';
  }

  return [address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join(', ');
};

const getPrimaryCustomerContact = (customer?: CustomerRecord | null): CustomerContact | undefined => {
  const contacts = customer?.customer_contacts ?? customer?.customerContacts ?? [];
  return contacts.find((contact) => contact.is_primary) ?? contacts[0];
};

const formatLegacyAddress = (address: string | null | undefined): string => {
  if (!address) return '';
  try {
    const parsed = JSON.parse(address);
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
    return address;
  }
};

const getCustomerAddress = (customer?: CustomerRecord | null, type?: 'billing' | 'shipping'): string => {
  if (!customer) {
    return '';
  }

  const typedAddresses = customer.addresses ?? [];
  const matchedAddress = typedAddresses.find((address) => address.type === type && address.is_default)
    ?? typedAddresses.find((address) => address.type === type)
    ?? null;

  if (matchedAddress) {
    return formatAddress(matchedAddress);
  }

  if (type === 'billing') {
    return formatLegacyAddress(customer.billing_address);
  }

  if (type === 'shipping') {
    return formatLegacyAddress(customer.shipping_address);
  }

  return '';
};

export const CreateInvoicePage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const isQuoteMode = location.pathname.startsWith('/sales/quotes');
  const documentLabel = isQuoteMode ? 'Quotation' : 'Invoice';
  const backPath = isQuoteMode ? '/sales/quotes' : '/accounting/customers/bills';

  const [customerId, setCustomerId] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(new Date().toISOString().split('T')[0]);
  const [dueDate, setDueDate] = useState(
    new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
  );
  const [dateOfCase, setDateOfCase] = useState('');
  const [caseLocation, setCaseLocation] = useState('');
  const [patientName, setPatientName] = useState('');
  const [costCenterId, setCostCenterId] = useState('');
  const [deliveryDate, setDeliveryDate] = useState('');
  const [poNumber, setPoNumber] = useState('');
  const [doctorContactId, setDoctorContactId] = useState('');
  const [requestorContactId, setRequestorContactId] = useState('');
  const [billingContactId, setBillingContactId] = useState('');
  const [shippingContactId, setShippingContactId] = useState('');
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [lines, setLines] = useState<InvoiceLine[]>([emptyLine()]);
  const [documentSnapshot, setDocumentSnapshot] = useState<InvoiceDocumentSnapshot>(emptyDocumentSnapshot);
  const [customerDialogOpen, setCustomerDialogOpen] = useState(false);
  const [customerDialogMode, setCustomerDialogMode] = useState<'create' | 'edit'>('create');
  const [customerForm, setCustomerForm] = useState<CustomerFormState>(initialCustomerForm);

  const { data: customers } = useQuery<CustomerRecord[]>({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const response = await api.get('/customers?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: selectedCustomerDetail } = useQuery<CustomerRecord>({
    queryKey: ['customer-document', customerId],
    queryFn: async () => {
      const response = await api.get(`/customers/${customerId}`);
      return response.data;
    },
    enabled: Boolean(customerId),
  });

  const customerContacts = (selectedCustomerDetail?.customer_contacts ?? selectedCustomerDetail?.customerContacts ?? []) as CustomerContact[];

  const { data: systemContacts } = useQuery({
    queryKey: ['system-contacts'],
    queryFn: async () => {
      const response = await api.get('/contacts?per_page=500&is_active=true');
      return response.data?.data?.data ?? response.data?.data ?? [];
    },
  });

  const contactOptions = (systemContacts ?? []).map((c: any) => ({
    id: c.id,
    name: c.display_name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.company_name || 'Unnamed',
    email: c.email,
    phone: c.phone,
  }));

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const selectedCustomer = selectedCustomerDetail
    ?? customers?.find((customer) => String(customer.id) === String(customerId));

  useEffect(() => {
    if (!selectedCustomer) {
      return;
    }

    const primaryContact = getPrimaryCustomerContact(selectedCustomer);
    const billingAddress = getCustomerAddress(selectedCustomer, 'billing');
    const shippingAddress = getCustomerAddress(selectedCustomer, 'shipping');

    setDocumentSnapshot({
      customer_reference: selectedCustomer.code ?? '',
      internal_reference: '',
      attention_to: primaryContact?.name ?? selectedCustomer.name ?? '',
      contact_email: primaryContact?.email ?? selectedCustomer.email ?? '',
      contact_phone: primaryContact?.phone ?? primaryContact?.mobile ?? selectedCustomer.phone ?? selectedCustomer.mobile ?? '',
      billing_address_snapshot: billingAddress,
      shipping_address_snapshot: shippingAddress,
      company_registration_number: selectedCustomer.company_registration_number ?? '',
      tax_registration_number: selectedCustomer.tax_number ?? '',
    });

    const contacts = contactOptions;
    const primaryIdx = contacts.findIndex((c: any) => c.is_primary);
    const defaultIdx = primaryIdx >= 0 ? primaryIdx : 0;
    if (contacts.length > 0) {
      setBillingContactId(String((contacts[defaultIdx] as any)?.id ?? ''));
      setShippingContactId(String((contacts[defaultIdx] as any)?.id ?? ''));
    }
  }, [selectedCustomer]);

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.post('/invoices', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: `${documentLabel} created successfully` });
      const invoiceId = data?.id || data?.data?.id;
      if (invoiceId) {
        navigate(isQuoteMode ? `/sales/quotes/${invoiceId}` : `/accounting/customers/bills/${invoiceId}`);
      } else {
        navigate(backPath);
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: `Failed to create ${documentLabel.toLowerCase()}`,
        description: getApiErrorMessage(error),
      });
    },
  });

  const createCustomerMutation = useMutation({
    mutationFn: async (payload: CustomerFormState) => {
      const response = await api.post('/customers', {
        ...payload,
        payment_terms: payload.payment_terms ? parseInt(payload.payment_terms, 10) : undefined,
      });
      return response.data as CustomerRecord;
    },
    onSuccess: async (customer) => {
      await queryClient.invalidateQueries({ queryKey: ['customers-list'] });
      toast({ title: 'Customer created successfully' });
      setCustomerId(String(customer.id));
      setCustomerDialogOpen(false);
      setCustomerForm(initialCustomerForm);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create customer',
        description: getApiErrorMessage(error),
      });
    },
  });

  const updateCustomerMutation = useMutation({
    mutationFn: async (payload: CustomerFormState) => {
      const response = await api.put(`/customers/${customerId}`, {
        ...payload,
        payment_terms: payload.payment_terms ? parseInt(payload.payment_terms, 10) : undefined,
      });
      return response.data as CustomerRecord;
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['customers-list'] }),
        queryClient.invalidateQueries({ queryKey: ['customer-document', customerId] }),
      ]);
      toast({ title: 'Customer updated successfully' });
      setCustomerDialogOpen(false);
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update customer',
        description: getApiErrorMessage(error),
      });
    },
  });

  const updateLine = (index: number, field: keyof InvoiceLine, value: string | number) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };

    if (field === 'product_id' && value && products) {
      const product = products.find((item: any) => String(item.id) === String(value));
      if (product) {
        // Default the line description to the product's FULL description (not its
        // name). The product name already shows in the Product column; the
        // description is a free-text field the user can customize per document.
        updated[index].description = product.description || product.short_description || '';
        updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
      }
    }

    setLines(updated);
  };

  const updateDocumentSnapshot = (field: keyof InvoiceDocumentSnapshot, value: string) => {
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

  const getLineTotal = (line: InvoiceLine) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = line.quantity * unitPrice;
    const tax = base * (taxRate / 100);
    return base + tax - discount;
  };

  const openCreateCustomerDialog = () => {
    setCustomerDialogMode('create');
    setCustomerForm(initialCustomerForm);
    setCustomerDialogOpen(true);
  };

  const openEditCustomerDialog = () => {
    if (!selectedCustomer) {
      return;
    }

    setCustomerDialogMode('edit');
    setCustomerForm({
      type: selectedCustomer.type ?? 'company',
      name: selectedCustomer.name ?? '',
      company: selectedCustomer.company ?? '',
      email: selectedCustomer.email ?? '',
      phone: selectedCustomer.phone ?? '',
      mobile: selectedCustomer.mobile ?? '',
      tax_number: selectedCustomer.tax_number ?? '',
      company_registration_number: selectedCustomer.company_registration_number ?? '',
      website: selectedCustomer.website ?? '',
      billing_address: getCustomerAddress(selectedCustomer, 'billing'),
      shipping_address: getCustomerAddress(selectedCustomer, 'shipping'),
      payment_terms: String(selectedCustomer.payment_terms ?? 30),
      notes: selectedCustomer.notes ?? '',
    });
    setCustomerDialogOpen(true);
  };

  const subtotal = lines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    return sum + line.quantity * unitPrice;
  }, 0);
  const taxTotal = lines.reduce((sum, line) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    return sum + line.quantity * unitPrice * (taxRate / 100);
  }, 0);
  const lineDiscounts = lines.reduce((sum, line) => sum + (line.discount_amount === '' ? 0 : line.discount_amount), 0);
  const discountAmt = discountAmount === '' ? 0 : discountAmount;
  const documentDiscountAmount = discountMode === 'percentage'
    ? subtotal * (discountAmt / 100)
    : discountAmt;
  const total = subtotal + taxTotal - lineDiscounts - documentDiscountAmount;
  const invoiceNumberPreview = `INV-${new Date(invoiceDate).getFullYear()}-XXXX`;

  const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);

  const handleCustomerFormSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (customerDialogMode === 'edit') {
      updateCustomerMutation.mutate(customerForm);
      return;
    }

    createCustomerMutation.mutate(customerForm);
  };

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    if (lines.length === 0 || lines.every((line) => !line.description && !line.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      invoice_date: invoiceDate,
      due_date: dueDate,
      date_of_case: dateOfCase || undefined,
      case_location: caseLocation || undefined,
      patient_name: patientName || undefined,
      cost_center_id: costCenterId || undefined,
      delivery_date: deliveryDate || undefined,
      po_number: poNumber || undefined,
      doctor_contact_id: doctorContactId || undefined,
      requestor_contact_id: requestorContactId || undefined,
      billing_contact_id: billingContactId || undefined,
      shipping_contact_id: shippingContactId || undefined,
      notes: notes || undefined,
      terms: terms || undefined,
      discount_amount: documentDiscountAmount || 0,
      ...documentSnapshot,
      lines: lines
        .filter((line) => line.description || line.product_id)
        .map((line) => ({
          product_id: line.product_id ? parseInt(line.product_id, 10) : null,
          description: line.description || 'Item',
          quantity: line.quantity,
          unit_price: line.unit_price === '' ? 0 : line.unit_price,
          tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
          discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
        })),
    };

    createMutation.mutate(payload);
  };

  const customerMutationPending = createCustomerMutation.isPending || updateCustomerMutation.isPending;
  const customerActions = [
    {
      key: 'add-customer',
      label: 'Add Customer Inline',
      description: 'Create and select a new customer without leaving this document.',
      icon: 'solar:add-square-bold-duotone',
      onClick: openCreateCustomerDialog,
      disabled: false,
    },
    {
      key: 'edit-customer',
      label: 'Edit Selected Customer',
      description: 'Update the selected customer before issuing this document.',
      icon: 'solar:pen-bold-duotone',
      onClick: openEditCustomerDialog,
      disabled: !selectedCustomer,
    },
    {
      key: 'manage-customers',
      label: 'Manage Customers',
      description: 'Open the full customer workspace in a separate screen.',
      icon: 'solar:users-group-rounded-bold-duotone',
      onClick: () => navigate('/customers'),
      disabled: false,
    },
    {
      key: 'open-profile',
      label: 'Open Customer Profile',
      description: selectedCustomer
        ? 'Jump directly to the selected customer record.'
        : 'Select a customer first to open the profile.',
      icon: 'solar:user-id-bold-duotone',
      onClick: () => {
        if (selectedCustomer) {
          navigate(`/customers/${selectedCustomer.id}`);
        }
      },
      disabled: !selectedCustomer,
    },
  ];

  const productActions = [
    {
      key: 'product-list',
      label: 'Product List',
      description: 'Browse existing products and reuse them in line items.',
      icon: 'solar:box-bold-duotone',
      onClick: () => navigate('/products'),
    },
    {
      key: 'create-product',
      label: 'Create Product',
      description: 'Add a missing product, service, or catalog item.',
      icon: 'solar:add-square-bold-duotone',
      onClick: () => navigate('/products/new'),
    },
  ];

  return (
    <>
      <div className="p-6 space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="sm" onClick={() => navigate(backPath)}>
              <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
              Back
            </Button>
            <div>
              <h1 className="text-3xl font-bold">Create {documentLabel}</h1>
              <p className="text-muted-foreground mt-1">
                Prepare the customer, products, and commercial details for this {documentLabel.toLowerCase()}.
              </p>
            </div>
          </div>
        </div>

        <form onSubmit={handleSubmit}>
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>{documentLabel} Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Customer *</label>
                  <select
                    value={customerId}
                    onChange={(event) => setCustomerId(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    required
                  >
                    <option value="">Select customer...</option>
                    {customers?.map((customer) => (
                      <option key={customer.id} value={customer.id}>
                        {customer.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">{isQuoteMode ? 'Quote Date' : 'Invoice Date'}</label>
                  <input
                    type="date"
                    value={invoiceDate}
                    onChange={(event) => setInvoiceDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">{isQuoteMode ? 'Valid Until' : 'Due Date'}</label>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(event) => setDueDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">{documentLabel} Number</label>
                  <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2">
                    <div className="text-sm font-semibold text-slate-900">{invoiceNumberPreview}</div>
                    <div className="text-xs text-slate-500 mt-1">Final sequence assigned when the {documentLabel.toLowerCase()} is created.</div>
                  </div>
                </div>
              </div>

              <div className="mt-4 grid grid-cols-1 xl:grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div className="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
                  <div>
                    <div className="text-sm font-semibold text-slate-900">Customer Actions</div>
                    <div className="text-xs text-slate-500 mt-1">
                      Keep the selected customer accurate before you save this {documentLabel.toLowerCase()}.
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {customerActions.map((action) => (
                      <Button
                        key={action.key}
                        type="button"
                        variant="outline"
                        onClick={action.onClick}
                        disabled={action.disabled}
                        className="h-auto min-h-[72px] justify-start px-3 py-3 text-left whitespace-normal"
                      >
                        <Icon icon={action.icon} className="h-4 w-4 mr-3 shrink-0" />
                        <span className="min-w-0">
                          <span className="block text-sm font-medium text-slate-900">{action.label}</span>
                          <span className="mt-1 block text-xs text-slate-500">{action.description}</span>
                        </span>
                      </Button>
                    ))}
                  </div>
                </div>

                <div className="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
                  <div>
                    <div className="text-sm font-semibold text-slate-900">Product Actions</div>
                    <div className="text-xs text-slate-500 mt-1">
                      Open the catalog or create a missing item before adding line entries.
                    </div>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {productActions.map((action) => (
                      <Button
                        key={action.key}
                        type="button"
                        variant="outline"
                        onClick={action.onClick}
                        className="h-auto min-h-[72px] justify-start px-3 py-3 text-left whitespace-normal"
                      >
                        <Icon icon={action.icon} className="h-4 w-4 mr-3 shrink-0" />
                        <span className="min-w-0">
                          <span className="block text-sm font-medium text-slate-900">{action.label}</span>
                          <span className="mt-1 block text-xs text-slate-500">{action.description}</span>
                        </span>
                      </Button>
                    ))}
                  </div>
                </div>
              </div>

              {selectedCustomer && (
                <div className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Customer</div>
                    <div className="mt-1 font-medium text-slate-900">{selectedCustomer.name}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Email / Phone</div>
                    <div className="mt-1 text-sm text-slate-700">{selectedCustomer.email || selectedCustomer.phone || selectedCustomer.mobile || 'Not captured'}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wide text-slate-500">Business Reference</div>
                    <div className="mt-1 text-sm text-slate-700">
                      {selectedCustomer.tax_number || selectedCustomer.company_registration_number || selectedCustomer.code || 'No tax or registration reference on file'}
                    </div>
                  </div>
                </div>
              )}

              <div className="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Customer Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.customer_reference}
                    onChange={(event) => updateDocumentSnapshot('customer_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="PO / customer code"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Internal Ref.</label>
                  <input
                    type="text"
                    value={documentSnapshot.internal_reference}
                    onChange={(event) => updateDocumentSnapshot('internal_reference', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Sales order / project"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Attention To</label>
                  <input
                    type="text"
                    value={documentSnapshot.attention_to}
                    onChange={(event) => updateDocumentSnapshot('attention_to', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Primary contact"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Email</label>
                  <input
                    type="email"
                    value={documentSnapshot.contact_email}
                    onChange={(event) => updateDocumentSnapshot('contact_email', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="billing@customer.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Phone</label>
                  <input
                    type="text"
                    value={documentSnapshot.contact_phone}
                    onChange={(event) => updateDocumentSnapshot('contact_phone', event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Phone or mobile"
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
                    placeholder="VAT / GST / TIN"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Billing Address Contact</label>
                  <select
                    value={billingContactId}
                    onChange={(e) => {
                      setBillingContactId(e.target.value);
                      const contact = contactOptions.find((c: any) => String((c as any).id) === e.target.value);
                      if (contact) {
                        updateDocumentSnapshot('attention_to', (contact as any).name ?? '');
                      }
                    }}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  >
                    <option value="">Select contact...</option>
                    {contactOptions.map((c: any, i: number) => (
                      <option key={c.id ?? i} value={c.id ?? ''}>
                        {c.name || `Contact ${i + 1}`}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Delivery Address Contact</label>
                  <select
                    value={shippingContactId}
                    onChange={(e) => setShippingContactId(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  >
                    <option value="">Select contact...</option>
                    {contactOptions.map((c: any, i: number) => (
                      <option key={c.id ?? i} value={c.id ?? ''}>
                        {c.name || `Contact ${i + 1}`}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Billing Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.billing_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('billing_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Invoice billing address"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Address Snapshot</label>
                  <textarea
                    value={documentSnapshot.shipping_address_snapshot}
                    onChange={(event) => updateDocumentSnapshot('shipping_address_snapshot', event.target.value)}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Delivery address"
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
              <CardTitle>Case Information</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Date of Case</label>
                  <input
                    type="date"
                    value={dateOfCase}
                    onChange={(event) => setDateOfCase(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Location</label>
                  <input
                    type="text"
                    value={caseLocation}
                    onChange={(event) => setCaseLocation(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Case / site location"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Patient Name</label>
                  <input
                    type="text"
                    value={patientName}
                    onChange={(event) => setPatientName(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Patient name"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Cost Center Id</label>
                  <input
                    type="text"
                    value={costCenterId}
                    onChange={(event) => setCostCenterId(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Cost center reference"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Delivery Date</label>
                  <input
                    type="date"
                    value={deliveryDate}
                    onChange={(event) => setDeliveryDate(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">PO Number</label>
                  <input
                    type="text"
                    value={poNumber}
                    onChange={(event) => setPoNumber(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Customer PO reference"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Doctor</label>
                  <select
                    value={doctorContactId}
                    onChange={(event) => setDoctorContactId(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  >
                    <option value="">Select doctor...</option>
                    {contactOptions.map((c: any, i: number) => (
                      <option key={c.id ?? i} value={c.id ?? ''}>
                        {c.name || `Contact ${i + 1}`}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Requestor</label>
                  <select
                    value={requestorContactId}
                    onChange={(event) => setRequestorContactId(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  >
                    <option value="">Select requestor...</option>
                    {contactOptions.map((c: any, i: number) => (
                      <option key={c.id ?? i} value={c.id ?? ''}>
                        {c.name || `Contact ${i + 1}`}
                      </option>
                    ))}
                  </select>
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
                      <th className="text-left py-2 px-2">Product</th>
                      <th className="text-left py-2 px-2">Description</th>
                      <th className="text-right py-2 px-2 w-24">Qty</th>
                      <th className="text-right py-2 px-2 w-32">Unit Price</th>
                      <th className="text-right py-2 px-2 w-24">Tax %</th>
                      <th className="text-right py-2 px-2 w-28">Line Discount</th>
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
                            value={line.product_id}
                            onChange={(event) => updateLine(index, 'product_id', event.target.value)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                          >
                            <option value="">-- None --</option>
                            {products?.map((product: any) => (
                              <option key={product.id} value={product.id}>
                                {product.name} {product.sku ? `(${product.sku})` : ''}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="py-2 px-2">
                          <textarea
                            value={line.description}
                            onChange={(event) => updateLine(index, 'description', event.target.value)}
                            rows={2}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm"
                            placeholder="Description (editable per line)"
                          />
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="number"
                            min="1"
                            value={line.quantity}
                            onChange={(event) => updateLine(index, 'quantity', parseInt(event.target.value, 10) || 1)}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          />
                        </td>
                        <td className="py-2 px-2">
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            value={line.unit_price === '' ? '' : line.unit_price}
                            onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                            className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          />
                        </td>
                        <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          max="100"
                          step="0.01"
                          placeholder="0"
                          value={line.tax_rate === '' ? '' : line.tax_rate}
                          onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                        />
                      </td>
                      <td className="py-2 px-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          placeholder="0.00"
                          value={line.discount_amount === '' ? '' : line.discount_amount}
                          onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
                          className="w-full px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                          title="Per-line discount amount (line_discount_amount)"
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
                        placeholder="0"
                        value={discountAmount === '' ? '' : discountAmount}
                        onChange={(event) => setDiscountAmount(event.target.value === '' ? '' : parseFloat(event.target.value))}
                        className="w-28 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-sm text-right"
                      />
                    </div>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Tax:</span>
                    <span className="font-medium">{formatCurrency(taxTotal)}</span>
                  </div>
                  {(documentDiscountAmount > 0 || lineDiscounts > 0) && (
                    <div className="flex justify-between text-sm">
                      <span className="text-muted-foreground">Discount:</span>
                      <span className="font-medium text-green-600">
                        -{formatCurrency(documentDiscountAmount + lineDiscounts)}
                      </span>
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
            <Button type="button" variant="outline" onClick={() => navigate(backPath)}>
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
                  Create {documentLabel}
                </>
              )}
            </Button>
          </div>
        </form>
      </div>

      <Dialog open={customerDialogOpen} onOpenChange={setCustomerDialogOpen}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{customerDialogMode === 'create' ? 'Add Customer' : 'Edit Customer'}</DialogTitle>
          </DialogHeader>

          <form onSubmit={handleCustomerFormSubmit} className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Customer Type</label>
                <select
                  value={customerForm.type}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, type: event.target.value as 'individual' | 'company' }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                >
                  <option value="company">Company</option>
                  <option value="individual">Individual</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Customer Name *</label>
                <input
                  type="text"
                  value={customerForm.name}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, name: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Company</label>
                <input
                  type="text"
                  value={customerForm.company}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, company: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Email</label>
                <input
                  type="email"
                  value={customerForm.email}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, email: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Phone</label>
                <input
                  type="text"
                  value={customerForm.phone}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, phone: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Mobile</label>
                <input
                  type="text"
                  value={customerForm.mobile}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, mobile: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Tax Registration</label>
                <input
                  type="text"
                  value={customerForm.tax_number}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, tax_number: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Company Registration</label>
                <input
                  type="text"
                  value={customerForm.company_registration_number}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, company_registration_number: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Website</label>
                <input
                  type="url"
                  value={customerForm.website}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, website: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Payment Terms (days)</label>
                <input
                  type="number"
                  min="0"
                  value={customerForm.payment_terms}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, payment_terms: event.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium mb-1">Billing Address</label>
                <textarea
                  value={customerForm.billing_address}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, billing_address: event.target.value }))}
                  rows={3}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1">Shipping Address</label>
                <textarea
                  value={customerForm.shipping_address}
                  onChange={(event) => setCustomerForm((current) => ({ ...current, shipping_address: event.target.value }))}
                  rows={3}
                  className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                />
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">Notes</label>
              <textarea
                value={customerForm.notes}
                onChange={(event) => setCustomerForm((current) => ({ ...current, notes: event.target.value }))}
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
              />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setCustomerDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={customerMutationPending}>
                {customerMutationPending ? 'Saving...' : customerDialogMode === 'create' ? 'Create Customer' : 'Save Changes'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default CreateInvoicePage;