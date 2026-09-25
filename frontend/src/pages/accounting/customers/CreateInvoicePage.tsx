import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { todayDateOnly, addCalendarDays, diffCalendarDays, isValidProductQuantity } from '@/lib/dateOnly';
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
import { cn, formatCurrency, getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api';
import { ContactSearchCombobox } from '@/components/ui/ContactSearchCombobox';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { ProductTrackingSelector } from '@/components/inventory/ProductTrackingSelector';
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow';
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import { TagEditor } from '@/components/tags/TagEditor'
import { TagPicker } from '@/components/tags/TagPicker'
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  productLineColumns,
  ProductLineActions,
} from '@/components/sales/ProductLineTable'

interface InvoiceLine {
  line_type?: 'product' | 'note' | 'section';
  product_id: string;
  sku: string;
  model_number: string;
  description: string;
  product_notes: string;
  lot_number: string;
  serial_number: string;
  product_batch_id: number | null;
  product_serial_id: number | null;
  tracking_method: string;
  expiry_date: string;
  quantity: number | '';
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
  label?: string | null;
  is_default?: boolean;
}

interface CustomerContact {
  id?: number | string;
  name?: string | null;
  email?: string | null;
  phone?: string | null;
  mobile?: string | null;
  is_primary?: boolean;
  street?: string | null;
  street2?: string | null;
  city?: string | null;
  state?: string | null;
  zip?: string | null;
  country?: string | null;
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

// Date-only + quantity-domain helpers are imported at the top of this file
// from '@/lib/dateOnly' so they are unit-tested rather than duplicated inline
// (issue #71). See the import block below.

const emptyLine = (): InvoiceLine => ({
  line_type: 'product',
  product_id: '',
  sku: '',
  model_number: '',
  description: '',
  product_notes: '',
  lot_number: '',
  serial_number: '',
  product_batch_id: null,
  product_serial_id: null,
  tracking_method: 'none',
  expiry_date: '',
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

type AddressFormState = Record<'label' | 'street' | 'street2' | 'city' | 'state' | 'zip' | 'country', string>;

const emptyAddressForm: AddressFormState = {
  label: '', street: '', street2: '', city: '', state: '', zip: '', country: '',
};

const toAddressForm = (address?: CustomerAddress | null): AddressFormState => ({
  label: address?.label ?? '',
  street: address?.street ?? '',
  street2: address?.street2 ?? '',
  city: address?.city ?? '',
  state: address?.state ?? '',
  zip: address?.zip ?? '',
  country: address?.country ?? '',
});

const addressFormToSnapshot = (address: AddressFormState): string =>
  [address.label, address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join('\n');

const formatAddress = (address?: CustomerAddress | null): string => {
  if (!address) {
    return '';
  }

  return [address.label, address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join('\n');
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
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const isQuoteMode = location.pathname.startsWith('/sales/quotes');
  const isEditMode = Boolean(id);
  const documentLabel = isQuoteMode ? 'Quotation' : 'Invoice';
  const isLegacyInvoiceRoute = location.pathname.startsWith('/invoices/');
  const backPath = isQuoteMode
    ? '/sales/quotes'
    : isLegacyInvoiceRoute
      ? `/invoices/${id ?? ''}`
      : isEditMode
        ? `/accounting/customers/bills/${id}`
        : '/accounting/customers/bills';

  const [customerId, setCustomerId] = useState('');
  const [invoiceDate, setInvoiceDate] = useState(() => todayDateOnly());
  const [paymentTerms, setPaymentTerms] = useState(30);
  // HR71-04: explicit override intent. Once the user manually edits terms or
  // due date, a later customer change must not silently overwrite it.
  const [termsOverridden, setTermsOverridden] = useState(false);
  const [requiresApproval, setRequiresApproval] = useState(false);
  const [customInvoiceNumber, setCustomInvoiceNumber] = useState('');
  const calcDueDate = (invDate: string, termsDays: number) => addCalendarDays(invDate, termsDays);
  const [dueDate, setDueDate] = useState(() => calcDueDate(todayDateOnly(), 30));
  const [caseNumber, setCaseNumber] = useState('');
  const [prNo, setPrNo] = useState('');
  const [contractNo, setContractNo] = useState('');
  const [buyerContactId, setBuyerContactId] = useState('');
  const [plantCode, setPlantCode] = useState('');
  const [companyCode, setCompanyCode] = useState('');
  const [dateOfCase, setDateOfCase] = useState('');
  const [caseLocation, setCaseLocation] = useState('');
  const [patientName, setPatientName] = useState('');
  const [costCenterId, setCostCenterId] = useState('');
  const [deliveryDate, setDeliveryDate] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [poNumber, setPoNumber] = useState('');
  const [doctorContactId, setDoctorContactId] = useState('');
  const [requestorContactId, setRequestorContactId] = useState('');
  const [billingContactId, setBillingContactId] = useState('');
  const [shippingContactId, setShippingContactId] = useState('');
  const [billingAddress, setBillingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [shippingAddress, setShippingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [lines, setLines] = useState<InvoiceLine[]>([emptyLine()]);
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null);
  const [documentSnapshot, setDocumentSnapshot] = useState<InvoiceDocumentSnapshot>(emptyDocumentSnapshot);
  const [customerDialogOpen, setCustomerDialogOpen] = useState(false);
  const [customerDialogMode, setCustomerDialogMode] = useState<'create' | 'edit'>('create');
  const [customerForm, setCustomerForm] = useState<CustomerFormState>(initialCustomerForm);
  const [tags, setTags] = useState<string[]>([]);
  const [originalCustomerId, setOriginalCustomerId] = useState('');
  const [visibleLineColumns, setVisibleLineColumns] = useState<Record<string, boolean>>(() => {
    const defaults = { sku: true, description: true, model_number: true, product_notes: false, lot_number: true, expiry_date: true, tax_rate: true };
    try {
      return { ...defaults, ...JSON.parse(localStorage.getItem('invoice-line-columns-v4') || '{}') };
    } catch {
      return defaults;
    }
  });

  const toggleLineColumn = (column: string) => {
    setVisibleLineColumns((current) => {
      const next = { ...current, [column]: !current[column] };
      localStorage.setItem('invoice-line-columns-v4', JSON.stringify(next));
      return next;
    });
  };

  const { data: existingInvoice, isLoading: isInvoiceLoading } = useQuery({
    queryKey: ['invoice-edit', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`);
      return response.data;
    },
    enabled: isEditMode,
  });

  // Tenant-level sales settings (only consulted when not editing)
  const { data: salesSettings } = useQuery({
    queryKey: ['sales-settings'],
    queryFn: async () => {
      const res = await api.get('/settings/sales');
      return res.data?.data ?? null;
    },
    enabled: !isEditMode,
    staleTime: 60_000,
  });

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

  const selectedCustomer = selectedCustomerDetail
    ?? customers?.find((customer) => String(customer.id) === String(customerId));

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  const { data: warehouses = [] } = useQuery({
    queryKey: ['warehouses-list'],
    queryFn: async () => {
      const response = await api.get('/warehouses', { params: { per_page: 100 } });
      return response.data?.data || response.data || [];
    },
  });

  useEffect(() => {
    if (!warehouseId && warehouses.length) {
      const preferred = warehouses.find((warehouse: any) => warehouse.is_default) || warehouses[0];
      setWarehouseId(String(preferred.id));
    }
  }, [warehouseId, warehouses]);

  useEffect(() => {
    if (!selectedCustomer) {
      return;
    }

    const addresses = selectedCustomer.addresses ?? [];
    const billingAddressRecord = addresses.find((address) => address.type === 'billing' && address.is_default)
      ?? addresses.find((address) => address.type === 'billing');
    const shippingAddressRecord = addresses.find((address) => address.type === 'shipping' && address.is_default)
      ?? addresses.find((address) => address.type === 'shipping');

    setBillingAddress(toAddressForm(billingAddressRecord));
    setShippingAddress(toAddressForm(shippingAddressRecord));

    // Preserve the invoice's saved snapshots while initially loading edit mode.
    if (isEditMode && String(selectedCustomer.id) === originalCustomerId) {
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

    setBillingContactId('');
    setShippingContactId('');
    setDoctorContactId('');
    setRequestorContactId('');

    // WRK-A / HR71-04: Use the customer's authoritative payment terms to
    // populate a DEFAULT only when the user has NOT intentionally overridden
    // terms/due date, and never re-derives a saved invoice's historical due
    // date while editing (originalCustomerId guard above returns early).
    const customerTerms = Number(selectedCustomer.payment_terms);
    if (!termsOverridden && Number.isFinite(customerTerms) && customerTerms >= 0) {
      setPaymentTerms(customerTerms);
      setDueDate(calcDueDate(invoiceDate, customerTerms));
    }
  }, [isEditMode, originalCustomerId, selectedCustomer]);

  useEffect(() => {
    if (!existingInvoice) return;

    const savedCustomerId = existingInvoice.customer_id ? String(existingInvoice.customer_id) : '';
    setOriginalCustomerId(savedCustomerId);
    setCustomerId(savedCustomerId);
    setInvoiceDate(existingInvoice.invoice_date ? String(existingInvoice.invoice_date).split('T')[0] : '');
    setDueDate(existingInvoice.due_date ? String(existingInvoice.due_date).split('T')[0] : '');
    // HR71-03: restore the invoice's saved payment-term basis so the visible
    // term state is coherent with the saved historical Due Date. Prefer a
    // saved payment_terms field; otherwise derive the day-count from the
    // saved dates. Mark as established (overridden) so a later customer
    // default does not silently rewrite historical invoice intent.
    const savedInvoiceDate = existingInvoice.invoice_date ? String(existingInvoice.invoice_date).split('T')[0] : '';
    const savedDueDate = existingInvoice.due_date ? String(existingInvoice.due_date).split('T')[0] : '';
    const restoredTerms = Number.isFinite(Number(existingInvoice.payment_terms))
      ? Number(existingInvoice.payment_terms)
      : (savedInvoiceDate && savedDueDate ? diffCalendarDays(savedInvoiceDate, savedDueDate) : null);
    if (restoredTerms !== null) {
      setPaymentTerms(restoredTerms);
      setTermsOverridden(true);
    }
    setCaseNumber(existingInvoice.case_number || '');
    setCustomInvoiceNumber(existingInvoice.invoice_number || '');
    setPrNo(existingInvoice.pr_no || '');
    setContractNo(existingInvoice.contract_no || '');
    setPlantCode(existingInvoice.plant_code || '');
    setCompanyCode(existingInvoice.company_code || '');
    setBuyerContactId(existingInvoice.buyer_contact_id ? String(existingInvoice.buyer_contact_id) : '');
    setDateOfCase(existingInvoice.date_of_case ? String(existingInvoice.date_of_case).split('T')[0] : '');
    setCaseLocation(existingInvoice.case_location || '');
    setPatientName(existingInvoice.patient_name || '');
    setCostCenterId(existingInvoice.cost_center_id || '');
    setDeliveryDate(existingInvoice.delivery_date ? String(existingInvoice.delivery_date).split('T')[0] : '');
    setPoNumber(existingInvoice.po_number || '');
    setDoctorContactId(existingInvoice.doctor_contact_id ? String(existingInvoice.doctor_contact_id) : '');
    setRequestorContactId(existingInvoice.requestor_contact_id ? String(existingInvoice.requestor_contact_id) : '');
    setBillingContactId(existingInvoice.billing_contact_id ? String(existingInvoice.billing_contact_id) : '');
    setShippingContactId(existingInvoice.shipping_contact_id ? String(existingInvoice.shipping_contact_id) : '');
    setNotes(existingInvoice.notes || '');
    setTerms(existingInvoice.terms || '');
    setDiscountMode('fixed');
    setDiscountAmount(existingInvoice.discount_amount ? Number(existingInvoice.discount_amount) : '');
    setRequiresApproval(!!existingInvoice.requires_approval);
    setDocumentSnapshot({
      customer_reference: existingInvoice.customer_reference || '',
      internal_reference: existingInvoice.internal_reference || '',
      attention_to: existingInvoice.attention_to || '',
      contact_email: existingInvoice.contact_email || '',
      contact_phone: existingInvoice.contact_phone || '',
      billing_address_snapshot: existingInvoice.billing_address_snapshot || '',
      shipping_address_snapshot: existingInvoice.shipping_address_snapshot || '',
      company_registration_number: existingInvoice.company_registration_number || '',
      tax_registration_number: existingInvoice.tax_registration_number || '',
    });

    const mappedLines = (existingInvoice.lines || []).map((line: any): InvoiceLine => ({
      product_id: line.product_id ? String(line.product_id) : '',
      line_type: line.line_type || 'product',
      sku: line.product?.sku || '',
      model_number: line.model_number || line.product?.manufacturer_part_number || '',
      description: line.description || '',
      product_notes: line.product_notes || '',
      lot_number: line.lot_number || '',
      serial_number: line.serial_number || '',
      product_batch_id: line.product_batch_id || null,
      product_serial_id: line.product_serial_id || null,
      tracking_method: line.product?.tracking_method || 'none',
      expiry_date: line.expiry_date ? String(line.expiry_date).split('T')[0] : '',
      quantity: Number(line.quantity ?? 1),
      unit_price: line.unit_price != null ? Number(line.unit_price) : '',
      tax_rate: line.tax_rate != null ? Number(line.tax_rate) : '',
      discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
    }));
    setLines(mappedLines.length ? mappedLines : [emptyLine()]);
  }, [existingInvoice]);

  useEffect(() => {
    if (!isEditMode || !existingInvoice || !selectedCustomerDetail) return;

    const contacts = selectedCustomerDetail.customer_contacts ?? selectedCustomerDetail.customerContacts ?? [];
    const billingName = existingInvoice.billing_contact?.display_name?.trim().toLowerCase();
    const shippingName = existingInvoice.shipping_contact?.display_name?.trim().toLowerCase();
    const doctorName = (existingInvoice.doctor?.display_name || existingInvoice.doctor?.full_name || existingInvoice.doctor?.name)?.trim().toLowerCase();
    const requestorName = (existingInvoice.requestor?.display_name || existingInvoice.requestor?.full_name || existingInvoice.requestor?.name)?.trim().toLowerCase();
    const billingMatch = billingName
      ? contacts.find((contact) => contact.name?.trim().toLowerCase() === billingName)
      : undefined;
    const shippingMatch = shippingName
      ? contacts.find((contact) => contact.name?.trim().toLowerCase() === shippingName)
      : undefined;
    const doctorMatch = doctorName
      ? contacts.find((contact) => contact.name?.trim().toLowerCase() === doctorName)
      : undefined;
    const requestorMatch = requestorName
      ? contacts.find((contact) => contact.name?.trim().toLowerCase() === requestorName)
      : undefined;

    setBillingContactId(billingMatch?.id != null ? String(billingMatch.id) : '');
    setShippingContactId(shippingMatch?.id != null ? String(shippingMatch.id) : '');
    setDoctorContactId(doctorMatch?.id != null ? String(doctorMatch.id) : '');
    setRequestorContactId(requestorMatch?.id != null ? String(requestorMatch.id) : '');
  }, [existingInvoice, isEditMode, selectedCustomerDetail]);

  // Apply tenant-level sales defaults when creating a NEW invoice (skip for edit)
  const [defaultsApplied, setDefaultsApplied] = useState(false);
  useEffect(() => {
    if (isEditMode || defaultsApplied || !salesSettings) return;
    const inv = salesSettings.invoice;
    if (inv?.default_requires_approval) {
      setRequiresApproval(true);
    }
    if (inv?.default_terms && !terms) {
      setTerms(inv.default_terms);
    }
    if (inv?.default_notes && !notes) {
      setNotes(inv.default_notes);
    }
    setDefaultsApplied(true);
  }, [salesSettings, isEditMode, defaultsApplied]);
  const saveMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = isEditMode
        ? await api.put(`/invoices/${id}`, data)
        : await api.post('/invoices', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: `${documentLabel} ${isEditMode ? 'updated' : 'created'} successfully` });
      const invoiceId = id || data?.id || data?.data?.id;
      if (invoiceId) {
        navigate(isQuoteMode
          ? `/sales/quotes/${invoiceId}`
          : isLegacyInvoiceRoute
            ? `/invoices/${invoiceId}`
            : `/accounting/customers/bills/${invoiceId}`);
      } else {
        navigate(backPath);
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: `Failed to ${isEditMode ? 'update' : 'create'} ${documentLabel.toLowerCase()}`,
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
        updated[index].description = product.description || product.short_description || '';
        updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
        updated[index].model_number = product.manufacturer_part_number || '';
        updated[index].sku = product.sku || '';
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

  const updateAddress = (type: 'billing' | 'shipping', field: keyof AddressFormState, value: string) => {
    const setter = type === 'billing' ? setBillingAddress : setShippingAddress;
    setter((current) => {
      const next = { ...current, [field]: value };
      updateDocumentSnapshot(
        type === 'billing' ? 'billing_address_snapshot' : 'shipping_address_snapshot',
        addressFormToSnapshot(next),
      );
      return next;
    });
  };

  const addLine = () => setLines([...lines, emptyLine()]);
  const addTextLine = (line_type: 'note' | 'section') => setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => {
    const next = [...lines];
    selected.forEach(({ product, quantity }) => {
      const existing = next.find((line) => line.line_type === 'product' && line.product_id === String(product.id));
      if (existing) {
        const cur = existing.quantity === '' ? 0 : existing.quantity;
        existing.quantity = cur + quantity;
      } else {
        next.push({ ...emptyLine(), product_id: String(product.id), sku: product.sku || '', description: product.name || '', unit_price: Number(product.price || 0), quantity });
      }
    });
    setLines(next);
  };

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

  const resetLine = (index: number) => {
    setLines((current) => current.map((line, i) => (i === index ? emptyLine() : line)));
  };

  const getLineTotal = (line: InvoiceLine) => {
    const qty = line.quantity === '' ? 0 : line.quantity;
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = qty * unitPrice;
    const discounted = base - discount;
    const tax = discounted * (taxRate / 100);
    return discounted + tax;
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
    const qty = line.quantity === '' ? 0 : line.quantity;
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    return sum + qty * unitPrice;
  }, 0);
  const taxTotal = lines.reduce((sum, line) => {
    const qty = line.quantity === '' ? 0 : line.quantity;
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = qty * unitPrice;
    return sum + (base - discount) * (taxRate / 100);
  }, 0);
  const lineDiscounts = lines.reduce((sum, line) => sum + (line.discount_amount === '' ? 0 : line.discount_amount), 0);
  const discountAmt = discountAmount === '' ? 0 : discountAmount;
  const documentDiscountAmount = discountMode === 'percentage'
    ? subtotal * (discountAmt / 100)
    : discountAmt;
  const total = subtotal + taxTotal - lineDiscounts - documentDiscountAmount;

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

    const missingProductIndex = lines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id
    );
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      });
      return;
    }

    if (discountMode === 'percentage' && (discountAmount === '' ? 0 : discountAmount) > 100) {
      toast({ variant: 'destructive', title: 'Percentage discount cannot exceed 100%' });
      return;
    }

    // HR71-08 — explicit quantity domain rule (approved): a product line's
    // quantity must be a finite number strictly greater than 0 with at most
    // 4 decimals. Blank, zero, negative, NaN, or over-precision are REJECTED
    // with a clear message — never silently coerced to 1. Note/section lines
    // carry quantity 0 and are exempt. isValidProductQuantity is unit-tested.
    const badQty = lines.find((line) => {
      const type = line.line_type ?? 'product';
      if (type !== 'product') return false;
      if (!line.description && !line.product_id) return false; // empty row skipped
      return !isValidProductQuantity(line.quantity);
    });
    if (badQty) {
      const idx = lines.indexOf(badQty) + 1;
      toast({
        variant: 'destructive',
        title: `Invalid quantity on line ${idx}`,
        description: 'Quantity must be a positive number (up to 4 decimal places).',
      });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      invoice_date: invoiceDate,
      due_date: dueDate,
      case_number: caseNumber || undefined,
      pr_no: prNo || undefined, contract_no: contractNo || undefined,
      buyer_contact_id: buyerContactId.includes('-') ? buyerContactId : null,
      plant_code: plantCode || undefined, company_code: companyCode || undefined,
      date_of_case: dateOfCase || undefined,
      case_location: caseLocation || undefined,
      patient_name: patientName || undefined,
      cost_center_id: costCenterId || undefined,
      delivery_date: deliveryDate || undefined,
      warehouse_id: warehouseId ? Number(warehouseId) : undefined,
      po_number: poNumber || undefined,
      doctor_contact_id: doctorContactId.includes('-') ? doctorContactId : null,
      requestor_contact_id: requestorContactId.includes('-') ? requestorContactId : null,
      // Customer-contact selections populate the document snapshot. The legacy
      // UUID contact links are retained only when loading an existing invoice.
      billing_contact_id: billingContactId.includes('-') ? billingContactId : null,
      shipping_contact_id: shippingContactId.includes('-') ? shippingContactId : null,
      billing_contact_name: (selectedCustomerDetail?.customer_contacts ?? selectedCustomerDetail?.customerContacts ?? [])
        .find((contact) => String(contact.id) === billingContactId)?.name || null,
      shipping_contact_name: (selectedCustomerDetail?.customer_contacts ?? selectedCustomerDetail?.customerContacts ?? [])
        .find((contact) => String(contact.id) === shippingContactId)?.name || null,
      doctor_contact_name: (selectedCustomerDetail?.customer_contacts ?? selectedCustomerDetail?.customerContacts ?? [])
        .find((contact) => String(contact.id) === doctorContactId)?.name || null,
      requestor_contact_name: (selectedCustomerDetail?.customer_contacts ?? selectedCustomerDetail?.customerContacts ?? [])
        .find((contact) => String(contact.id) === requestorContactId)?.name || null,
      notes: notes || undefined,
      terms: terms || undefined,
      payment_terms: paymentTerms,
      discount_amount: documentDiscountAmount || 0,
      requires_approval: requiresApproval,
      ...(!isEditMode && tags.length ? { tags } : {}),
      ...(customInvoiceNumber ? { invoice_number: customInvoiceNumber } : {}),
      ...documentSnapshot,
      lines: lines
        .filter((line) => line.description || line.product_id)
        .map((line) => ({
          line_type: line.line_type || 'product',
          sequence: lines.indexOf(line),
          product_id: line.product_id ? parseInt(line.product_id, 10) : null,
          model_number: line.model_number || null,
          description: line.description || 'Item',
          product_notes: line.product_notes || null,
          lot_number: line.lot_number || null,
          serial_number: line.serial_number || null,
          product_batch_id: line.product_batch_id || null,
          product_serial_id: line.product_serial_id || null,
          expiry_date: line.expiry_date || null,
          quantity: line.quantity === '' ? 0 : Number(line.quantity),
          unit_price: line.unit_price === '' ? 0 : line.unit_price,
          tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
          discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
        })),
    };

    saveMutation.mutate(payload);
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
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate(backPath)}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">{isEditMode ? 'Edit' : 'Create'} {documentLabel}</h1>
          <p className="text-muted-foreground mt-1">
            Prepare the customer, products, and commercial details for this {documentLabel.toLowerCase()}.
          </p>
          {isEditMode && id ? (
            <div className="mt-2">
              <TagEditor subjectType={isQuoteMode ? 'quotation' : 'invoice'} subjectId={id} />
            </div>
          ) : (
            <div className="mt-2">
              <TagPicker value={tags} onChange={setTags} />
            </div>
          )}
        </div>

        <form onSubmit={handleSubmit}>
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>{documentLabel} Details</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-12 lg:items-end">
                <div className="lg:col-span-3">
                  <label className="block text-sm font-medium mb-1">Customer *</label>
                  <CustomerSearchCombobox
                    value={customerId}
                    onValueChange={(id, customer) => {
                      setCustomerId(id)
                      if (customer) {
                        // Auto-populate customer reference if available
                        if ((customer as any).code) {
                          setDocumentSnapshot(prev => ({ ...prev, customer_reference: (customer as any).code }))
                        }
                      }
                    }}
                  />
                </div>
                {!isQuoteMode && (
                  <div className="lg:col-span-2">
                    <label className="block text-sm font-medium mb-1">Warehouse *</label>
                    <select value={warehouseId} onChange={(event) => { setWarehouseId(event.target.value); setLines((current) => current.map((line) => ({ ...line, product_batch_id: null, product_serial_id: null, lot_number: '', serial_number: '', expiry_date: '' }))); }} className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm">
                      <option value="">Select warehouse</option>
                      {warehouses.map((warehouse: any) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}{warehouse.is_default ? ' (Default)' : ''}</option>)}
                    </select>
                  </div>
                )}
                <div className={isQuoteMode ? 'lg:col-span-2' : 'lg:col-span-2'}>
                  <label className="block text-sm font-medium mb-1">{isQuoteMode ? 'Quote Date' : 'Invoice Date'}</label>
                  <input
                    type="date"
                    value={invoiceDate}
                    onChange={(event) => {
                      const newDate = event.target.value;
                      setInvoiceDate(newDate);
                      setDueDate(calcDueDate(newDate, paymentTerms));
                    }}
                    className="w-full min-w-0 px-2 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                {!isQuoteMode && (
                  <div className="lg:col-span-1">
                    <label className="block text-sm font-medium mb-1">Terms (days)</label>
                    <input
                      type="number"
                      min="0"
                      value={paymentTerms}
                      onChange={(event) => {
                        const days = Math.max(0, parseInt(event.target.value, 10) || 0);
                        setPaymentTerms(days);
                        setDueDate(calcDueDate(invoiceDate, days));
                        // HR71-04: an intentional term edit is an override.
                        setTermsOverridden(true);
                      }}
                      className="w-full min-w-0 px-2 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                )}
                <div className={isQuoteMode ? 'lg:col-span-2' : 'lg:col-span-2'}>
                  <label className="block text-sm font-medium mb-1">{isQuoteMode ? 'Valid Until' : 'Due Date'}</label>
                  <input
                    type="date"
                    value={dueDate}
                    onChange={(event) => {
                      setDueDate(event.target.value);
                      // HR71-04: an intentional due-date edit is an override.
                      setTermsOverridden(true);
                    }}
                    className="w-full min-w-0 px-2 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="lg:col-span-2">
                  <label className="block text-sm font-medium mb-1">{documentLabel} Number</label>
                  <input
                    type="text"
                    value={customInvoiceNumber}
                    onChange={(e) => setCustomInvoiceNumber(e.target.value)}
                    placeholder={`INV-${new Date().getFullYear()}-XXXX`}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm font-mono"
                  />
                </div>
              </div>

              {!isQuoteMode && requiresApproval && (
                <div className="mt-4 flex items-start gap-2 rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-xs text-orange-700 dark:border-orange-900 dark:bg-orange-950/40 dark:text-orange-300">
                  <Icon icon="solar:checklist-bold-duotone" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                  <span>
                    This {documentLabel.toLowerCase()} will require approval before posting
                    {salesSettings?.invoice?.default_requires_approval && !isEditMode && (
                      <span className="text-orange-600/70"> · set in Sales Settings</span>
                    )}
                  </span>
                </div>
              )}

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
                  <label className="block text-sm font-medium mb-1">Billing Contact</label>
                  <ContactSearchCombobox
                    value={billingContactId}
                    onValueChange={(val, contact) => {
                      setBillingContactId(val);
                      if (contact) {
                        setDocumentSnapshot((current) => ({
                          ...current,
                          attention_to: (contact as any).name || contact.display_name || current.attention_to,
                          contact_email: contact.email || current.contact_email,
                          contact_phone: contact.phone || (contact as any).mobile || current.contact_phone,
                        }));
                      }
                    }}
                    placeholder="Select customer contact..."
                    customerId={customerId}
                    source="customer_contacts"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Contact</label>
                  <ContactSearchCombobox
                    value={shippingContactId}
                    onValueChange={(val) => setShippingContactId(val)}
                    placeholder="Select customer contact..."
                    customerId={customerId}
                    source="customer_contacts"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                {([
                  ['billing', 'Billing Address', billingAddress],
                  ['shipping', 'Shipping Address', shippingAddress],
                ] as const).map(([type, title, address]) => (
                  <div key={type} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div className="mb-3 text-sm font-semibold text-slate-900">{title}</div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                      {([
                        ['label', 'Label'], ['street', 'Street'], ['street2', 'Street 2'],
                        ['city', 'City'], ['state', 'State'], ['zip', 'ZIP'], ['country', 'Country'],
                      ] as const).map(([field, label]) => (
                        <div key={field} className={field === 'street' || field === 'street2' ? 'md:col-span-2' : ''}>
                          <label className="block text-xs font-medium text-slate-600 mb-1">{label}</label>
                          <input
                            type="text"
                            value={address[field]}
                            onChange={(event) => updateAddress(type, field, event.target.value)}
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                            placeholder={label}
                          />
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
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
                  <label className="block text-sm font-medium mb-1">Case Number</label>
                  <input
                    type="text"
                    value={caseNumber}
                    onChange={(event) => setCaseNumber(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Case reference number"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Date of Case</label>
                  <input
                    type="date"
                    value={dateOfCase}
                    onChange={(event) => setDateOfCase(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                  />
                </div>
                {([['PR No', prNo, setPrNo], ['Contract No', contractNo, setContractNo], ['Plant Code', plantCode, setPlantCode], ['Company Code', companyCode, setCompanyCode]] as const).map(([label, value, setter]) => <div key={label}><label className="block text-sm font-medium mb-1">{label}</label><input type="text" value={value} onChange={(event) => setter(event.target.value)} className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm" /></div>)}
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
                  <ContactSearchCombobox
                    value={doctorContactId}
                    onValueChange={(val) => setDoctorContactId(val)}
                    placeholder="Select doctor..."
                    customerId={customerId}
                    source="customer_contacts"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Requestor</label>
                  <ContactSearchCombobox
                    value={requestorContactId}
                    onValueChange={(val) => setRequestorContactId(val)}
                    placeholder="Select requestor..."
                    customerId={customerId}
                    source="customer_contacts"
                  />
                </div>
                <div><label className="block text-sm font-medium mb-1">Buyer</label><ContactSearchCombobox value={buyerContactId} onValueChange={setBuyerContactId} placeholder="Select buyer..." customerId={customerId} source="customer_contacts" /></div>
              </div>
            </CardContent>
          </Card>

          <Card className="mb-6">
            <CardHeader>
              <CardTitle className="flex items-center justify-between">
                <span>Line Items</span>
                <div className="flex flex-wrap gap-2">
                  <details className="relative">
                    <summary className="list-none cursor-pointer rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-accent">
                      <Icon icon="solar:settings-bold-duotone" className="inline h-4 w-4 mr-1" />
                      Show/Hide Fields
                    </summary>
                    <div className="absolute right-0 z-20 mt-2 w-52 rounded-md border bg-background p-3 shadow-lg">
                      {[
                        ['sku', 'SKU'], ['description', 'Description'], ['model_number', 'Model #'],
                        ['product_notes', 'Product Notes'], ['lot_number', 'Lot Number'],
                        ['expiry_date', 'Expiry Date'], ['tax_rate', 'Tax %'],
                      ].map(([key, label]) => (
                        <label key={key} className="flex cursor-pointer items-center gap-2 py-1 text-sm">
                          <input type="checkbox" checked={visibleLineColumns[key]} onChange={() => toggleLineColumn(key)} />
                          {label}
                        </label>
                      ))}
                    </div>
                  </details>
                </div>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="overflow-x-auto">
                <LineItemsTable
                  variant="raw"
                  tableClassName="w-full min-w-[1700px] text-sm"
                  headRowClassName="border-b bg-muted/30"
                  columns={productLineColumns({
                    sku: visibleLineColumns.sku,
                    description: visibleLineColumns.description,
                    modelNumber: visibleLineColumns.model_number,
                    productNotes: visibleLineColumns.product_notes,
                    lotNumber: visibleLineColumns.lot_number,
                    expiryDate: visibleLineColumns.expiry_date,
                    taxRate: visibleLineColumns.tax_rate,
                  })}
                >
                    {lines.map((line, index) => (
                      <tr key={index} className="border-b transition-colors hover:bg-muted/20" draggable={(line.line_type ?? 'product') === 'product'} onDragStart={() => setDraggedLineIndex(index)} onDragOver={(event) => event.preventDefault()} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== index) setLines(reorderDocumentLines(lines, draggedLineIndex, index)); setDraggedLineIndex(null); }}>
                        {line.line_type === 'note' || line.line_type === 'section' ? <DocumentTextLineRow type={line.line_type} text={line.description} colSpan={13} total={line.line_type === 'section' ? formatCurrency(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined} onChange={(text) => updateLine(index, 'description', text)} onDelete={() => removeLine(index)} onDragStart={() => setDraggedLineIndex(index)} onDrop={() => { if (draggedLineIndex !== null) setLines(reorderDocumentLines(lines, draggedLineIndex, index)); setDraggedLineIndex(null); }} /> : <>
                        <td className={cn(LINE_CELL, 'w-10')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                            {lines.slice(0, index + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                          </div>
                        </td>
                        <td className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                          <ProductSearchCombobox
                            value={line.product_id}
                            onValueChange={(productId, product) => {
                              const updated = [...lines];
                              updated[index] = { ...updated[index], product_id: productId };
                              updated[index].lot_number = '';
                              updated[index].serial_number = '';
                              updated[index].product_batch_id = null;
                              updated[index].product_serial_id = null;
                              updated[index].expiry_date = '';
                              if (product) {
                                updated[index].description = getProductLineDescription(product);
                                updated[index].unit_price = parseFloat((product as any).price || (product as any).selling_price || '0');
                                updated[index].model_number = (product as any).manufacturer_part_number || '';
                                updated[index].sku = (product as any).sku || '';
                                updated[index].tracking_method = (product as any).tracking_method || 'none';
                              }
                              setLines(updated);
                            }}
                            wrapText
                          />
                        </td>
                        {visibleLineColumns.sku && <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                        </td>}
                        {visibleLineColumns.description && <td className={cn(LINE_CELL, 'min-w-[16rem]')}>
                          <textarea
                            value={line.description}
                            onChange={(event) => updateLine(index, 'description', event.target.value)}
                            rows={2}
                            className={LINE_TEXTAREA}
                            placeholder="Description"
                          />
                        </td>}
                        {visibleLineColumns.model_number && <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                        </td>}
                        {visibleLineColumns.product_notes && <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                          <textarea
                            value={line.product_notes}
                            onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                            rows={2}
                            className={LINE_TEXTAREA}
                            placeholder="Product notes"
                          />
                        </td>}
                        {visibleLineColumns.lot_number && <td className={cn(LINE_CELL, 'min-w-[7.5rem]')}>
                          <ProductTrackingSelector
                            productId={line.product_id}
                            warehouseId={warehouseId}
                            trackingMethod={line.tracking_method}
                            batchId={line.product_batch_id}
                            serialId={line.product_serial_id}
                            onChange={(selection) => setLines((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...selection } : item))}
                          />
                          {line.tracking_method === 'none' || line.tracking_method === 'quantity' ? <span className="text-xs text-muted-foreground">Not tracked</span> : null}
                        </td>}
                        {visibleLineColumns.expiry_date && <td className={cn(LINE_CELL, 'min-w-[9.5rem]')}>
                          <div className={LINE_VALUE}>{line.expiry_date || <span className="opacity-60">Expiry</span>}</div>
                        </td>}
                        <td className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              step="0.0001"
                              inputMode="decimal"
                              value={line.quantity}
                              onChange={(event) => {
                                const raw = event.target.value;
                                if (raw === '') { updateLine(index, 'quantity', ''); return; }
                                const parsed = Number(raw);
                                if (!Number.isNaN(parsed)) updateLine(index, 'quantity', parsed);
                              }}
                              className={LINE_INPUT_NUMERIC}
                            />
                          </div>
                        </td>
                        <td className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              step="0.01"
                              placeholder="0.00"
                              value={line.unit_price === '' ? '' : line.unit_price}
                              onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                              className={LINE_INPUT_NUMERIC}
                            />
                          </div>
                        </td>
                        {visibleLineColumns.tax_rate && <td className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              max="100"
                              step="0.01"
                              placeholder="0"
                              value={line.tax_rate === '' ? '' : line.tax_rate}
                              onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
                              className={LINE_INPUT_NUMERIC}
                            />
                          </div>
                        </td>}
                        <td className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                            {formatCurrency(getLineTotal(line))}
                          </div>
                        </td>
                        <td className={cn(LINE_CELL, 'w-[10.5rem]')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                            <ProductLineActions
                              onMoveUp={() => moveLine(index, 'up')}
                              onMoveDown={() => moveLine(index, 'down')}
                              canMoveUp={index !== 0}
                              canMoveDown={index !== lines.length - 1}
                              onDuplicate={() => duplicateLine(index)}
                              onReset={() => resetLine(index)}
                              onDelete={() => removeLine(index)}
                            />
                          </div>
                        </td>
                        </>}
                      </tr>
                    ))}
                </LineItemsTable>
              </div>

              <div className="mt-3 flex flex-wrap gap-2">
                <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
                <Button type="button" variant="outline" size="sm" onClick={addLine}>
                  <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-1" />
                  Add Line
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={() => addTextLine('section')}>Add Section</Button>
                <Button type="button" variant="outline" size="sm" onClick={() => addTextLine('note')}>Add Note</Button>
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
            <Button type="submit" disabled={saveMutation.isPending || isInvoiceLoading}>
              {saveMutation.isPending ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                  {isEditMode ? 'Saving...' : 'Creating...'}
                </>
              ) : (
                <>
                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                  {isEditMode ? 'Save Changes' : `Create ${documentLabel}`}
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
