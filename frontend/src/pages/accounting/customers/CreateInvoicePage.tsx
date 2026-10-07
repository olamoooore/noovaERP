import React, { useEffect, useState } from 'react';
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { todayDateOnly, addCalendarDays, diffCalendarDays, isValidProductQuantity } from '@/lib/dateOnly';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import { cn, formatCurrency, formatDate, getApiErrorMessage, normalizeQuantity } from '@/lib/utils';
import api from '@/lib/api'
import { buildContactAddress, getCustomerContactForRole, getPrimaryCustomerContact } from '@/lib/customerDocumentDefaults';
import type { ContactMasterAddress } from '@/lib/customerDocumentDefaults';
import { resolveLineTaxRate } from '@/lib/lineTax';
import { computeDocumentTotals } from '@/lib/documentTotals';
import { useSalesDocumentPricing } from '@/hooks/useSalesDocumentPricing';
import {
  duplicateSalesDocumentLine,
  getSalesDocumentLineTotal,
  isBlankSalesDocumentLine,
  moveSalesDocumentLine,
  removeSalesDocumentLine,
  resetSalesDocumentLine,
  type SalesDocumentLine,
} from '@/lib/salesDocumentLines';
import pricingApi from '@/lib/pricingApi'
import { useAuthStore } from '@/store/authStore';
import { ContactSearchCombobox } from '@/components/ui/ContactSearchCombobox';
import { invoiceContactPayload } from './invoiceContactPayload';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { CustomerForm } from '@/components/customers/CustomerForm';
import {
  customerFormToCreatePayload,
  customerFormToUpdatePayload,
  customerToFormValues,
  emptyCustomerFormValues,
  type CustomerFormValues,
} from '@/lib/customerPayload';
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { CaseSearchCombobox, type MedicalCaseOption } from '@/components/sales/CaseSearchCombobox';
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard';
import { CustomerContextStrip } from '@/components/sales/CustomerContextStrip';
import { creditWarning } from '@/lib/creditCheck';
import { duplicateWarning, likelyDuplicateFromError, type DuplicateCheckResult } from '@/lib/duplicateCheck';
import { DuplicateInvoiceDialog } from '@/components/sales/DuplicateInvoiceDialog';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { ProductTrackingSelector } from '@/components/inventory/ProductTrackingSelector';
import { RichTextEditor } from '@/components/ui/RichTextEditor';
import { htmlToPlainText, plainToRichHtml } from '@/lib/richText';
import { missingTrackingLineIndex } from '@/lib/lotTracking';
import { StockAvailabilityChip } from '@/components/sales/StockAvailabilityChip';
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow';
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import { TagEditor } from '@/components/tags/TagEditor'
import { Tooltip, TooltipContent, TooltipTrigger, TooltipProvider } from '@/components/ui/tooltip'
import { HeaderChip, StaticChip } from '@/components/ui/HeaderChip'
import { EditingBanner } from '@/components/sales/EditingBanner'
import { SaveConflictDialog } from '@/components/sales/SaveConflictDialog'
import { extractSaveConflict, type SaveConflict } from '@/lib/recordConflict'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useDensity } from '@/hooks/useDensity'
import { TagPicker } from '@/components/tags/TagPicker'
import { useDocumentDraftGuard } from '@/hooks/useDocumentDraftGuard'
import { useFieldErrors } from '@/hooks/useFieldErrors'
import { FieldErrorsProvider, FieldSlot } from '@/components/ui/field-errors'
import { lineFieldKey, payloadRowIndexes } from '@/lib/serverValidation'
import { DocumentDraftBanner, DocumentLeaveDialog } from '@/components/sales/DocumentDraftControls'
import { openAppPathInNewTab } from '@/lib/openInNewTab'
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
  // #1004: sanitized rich-text copy of the description (font family/size, colour, bold/italic/underline, alignment, lists).
  description_html?: string | null;
  uom_id?: number | string | null;
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
  // MRD pricelist-multicurrency FR-6.2/FR-6.3: pricing intent travels with
  // the line — 'auto' lets the server resolve (and re-resolve) from the
  // price list; 'manual' marks a hand-typed value (floor-guarded).
  price_mode?: 'auto' | 'manual' | 'carried';
  pricing_source?: string | null;
  override_reason?: string | null;
  // FR-3.8: public/reference price for the strikethrough display.
  list_price?: number | null;
  // D5 (invoice review): full engine trace for the hover explanation —
  // list/rule, tier compute info and the FX rate actually used.
  pricing_trace?: {
    source?: string | null;
    pricelist_code?: string | null;
    compute_type?: string | null;
    compute_value?: number | null;
    base?: string | null;
    rate_used?: number | null;
    rate_date?: string | null;
  } | null;
  pricing_currency?: string | null;
  pricing_base_currency?: string | null;
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
  contact_category?: string | null;
  contact_type?: string | null;
  is_primary?: boolean;
  core_contact_uuid?: string | null;
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

type CustomerFormState = CustomerFormValues;

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
  description_html: '',
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

// A fully-empty, untouched product row. These placeholder rows must be ignored
// consistently by every validation + the payload filter (issue #290) — they
// used to block saving after catalogue adds while the starter row lingered.
// Issue #314: the rule lives in the shared sales-document line module.
const isBlankPlaceholderLine = (line: InvoiceLine): boolean =>
  isBlankSalesDocumentLine(line as SalesDocumentLine);

const initialCustomerForm: CustomerFormState = emptyCustomerFormValues({ type: 'company' });

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

// The Bill-To contact rule (billing → primary → first) is shared with the
// quotation form — see '@/lib/customerDocumentDefaults' (issue #320).

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

const PRESET_OVERRIDE_REASONS = [
  'Tender / contract price',
  'Promotional campaign',
  'Management approval',
  'Customer goodwill',
  'Stock clearance',
]

export interface InvoiceCaseRow {
  case_number: string;
  date_of_case: string;
  case_location: string;
  patient_name: string;
  cost_center_id: string;
  delivery_date: string;
  pr_no: string;
  contract_no: string;
  plant_code: string;
  company_code: string;
  po_number: string;
  doctor_contact_id: string;
  requestor_contact_id: string;
  buyer_contact_id: string;
  medical_case_id?: number | null;
}

const emptyInvoiceCase = (): InvoiceCaseRow => ({
  case_number: '', date_of_case: '', case_location: '', patient_name: '', cost_center_id: '',
  delivery_date: '', pr_no: '', contract_no: '', plant_code: '', company_code: '', po_number: '',
  doctor_contact_id: '', requestor_contact_id: '', buyer_contact_id: '', medical_case_id: null,
});

export const CreateInvoicePage: React.FC = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  // The edit form hydrates its state from the server exactly once per invoice.
  // Background refetches (window focus, invalidation) must not overwrite
  // in-progress edits — losing the selected lot this way was a real report.
  const hydratedInvoiceIdRef = useRef<string | null>(null);
  // Marked true by any interaction inside the line-items grid so a later
  // background refetch can never overwrite in-progress edits (e.g. the
  // selected lot). Reset when a different invoice is hydrated.
  const linesTouchedRef = useRef(false);
  const { toast } = useToast()
  const canViewPriceLists = useAuthStore((state) => state.hasPermission('pricelists.view'));
  const isQuoteMode = location.pathname.startsWith('/sales/quotes');
  // Issue #906: stale-save conflict state (dialog) + the payload to resend.
  const [conflict, setConflict] = useState<SaveConflict | null>(null);
  const lastSavePayloadRef = useRef<Record<string, any> | null>(null);
  // #318: inline field errors; server line indexes map back through the grid
  // rows that were actually sent.
  const fieldErrors = useFieldErrors();
  const payloadRowsRef = useRef<number[]>([]);
  const currentUserId = useAuthStore((state) => state.user?.id);
  const canOverwriteConflict = useAuthStore((state) =>
    state.hasPermission(isQuoteMode ? 'quotations.write' : 'invoices.write'));
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
  const [sendAfterSave, setSendAfterSave] = useState(false);
  // D1: why this customer matched the search (contact/tag/address) — kept
  // visible after the pick instead of being discarded with the dropdown.
  const [customerMatch, setCustomerMatch] = useState<{
    contacts: string[];
    tags: string[];
    addresses: string[];
  } | null>(null);
  // D6/D9: the customer's most recent invoice — powers "Repeat last invoice"
  // and the prefill of remembered choices (warehouse, list, notes, contacts).
  const [lastInvoice, setLastInvoice] = useState<{
    id: number;
    number: string;
    warehouseId?: string;
    priceListId?: string;
    notes?: string;
    terms: number | null;
    lines: any[];
    contacts: { billing?: string; shipping?: string; doctor?: string; requestor?: string; buyer?: string };
  } | null>(null);
  const [reuseNotice, setReuseNotice] = useState<string | null>(null);
  const [pasteOpen, setPasteOpen] = useState(false);
  const [pasteText, setPasteText] = useState('');
  const [pasteBusy, setPasteBusy] = useState(false);
  const [repeatConfirmOpen, setRepeatConfirmOpen] = useState(false);
  // #298 (F6): pending warehouse change + the line indexes whose lot/serial
  // pick does NOT exist in the new warehouse (only those are cleared).
  const [warehouseClearConfirm, setWarehouseClearConfirm] = useState<{
    nextId: string;
    clearIndexes: number[];
  } | null>(null);
  // 8.3: one drawer for secondary details + per-user density.
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [density, setDensity] = useDensity();
  // D10: keyboard command bar (Ctrl/⌘+K) for power users.
  const [commandOpen, setCommandOpen] = useState(false);
  const [commandText, setCommandText] = useState('');
  const [commandBusy, setCommandBusy] = useState(false);
  const [commandResults, setCommandResults] = useState<Array<{ id: number; name: string; sku?: string | null }>>([]);
  const warehouseTouchedRef = useRef(false);
  const appliedLastInvoiceRef = useRef<number | null>(null);
  // #317: customer id of a restored local draft — its header values are the
  // user's own, so the customer-default effects must not overwrite them.
  const restoredCustomerRef = useRef<string | null>(null);
  // Guards the async Contact-Master address lookups per section: a stale
  // response must not overwrite the address of a contact picked later.
  const contactAddressRequestRef = useRef<{ billing: number; shipping: number }>({ billing: 0, shipping: 0 });
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

  // Additional cases on the same invoice (the primary case above keeps the
  // legacy single-case fields + templates working).
  const [extraCases, setExtraCases] = useState<InvoiceCaseRow[]>([]);
  // Registry link for the primary case (auto-linked on edit when the stored
  // case number matches a case in the registry).
  const [primaryMedicalCaseId, setPrimaryMedicalCaseId] = useState<number | null>(null);
  const [billingContactId, setBillingContactId] = useState('');
  const [shippingContactId, setShippingContactId] = useState('');
  const [billingAddress, setBillingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [shippingAddress, setShippingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [notes, setNotes] = useState('');
  const [terms, setTerms] = useState('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [lines, setLines] = useState<InvoiceLine[]>([emptyLine()]);
  // MRD pricelist-multicurrency FR-5.1/FR-6.1: document currency + resolved rate
  // snapshot + visible resolution errors. Issue #314: the race-safe resolver and
  // state live in the shared hook so invoice/quotation cannot drift again.
  const {
    currencyCode,
    setCurrencyCode,
    priceListId,
    setPriceListId,
    pricingMeta,
    pricingError,
    resolvePricingForLines: resolvePricingForLinesShared,
  } = useSalesDocumentPricing<InvoiceLine>({
    customerId,
    documentDate: invoiceDate,
    setLines,
    // D5: keep the full engine trace so the price chip can explain how the
    // number was reached (list/rule, tier, FX rate).
    applyQuote: (_line, quote) => ({
      pricing_source: quote.pricing_trace?.pricelist_code || quote.source || null,
      list_price: Number(quote.list_price ?? 0) || null,
      pricing_trace: quote.pricing_trace ?? null,
      pricing_currency: quote.currency ?? null,
      pricing_base_currency: quote.base_currency ?? null,
    }),
  });
  const [overrideDialogOpen, setOverrideDialogOpen] = useState(false);
  // Issue #327: a likely duplicate the server refused until confirmed.
  const [likelyDuplicate, setLikelyDuplicate] = useState<DuplicateCheckResult | null>(null);
  const [overrideReason, setOverrideReason] = useState('');
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null);
  const [documentSnapshot, setDocumentSnapshot] = useState<InvoiceDocumentSnapshot>(emptyDocumentSnapshot);
  // Billing and shipping details are stacked rows with independent toggles:
  // collapsed on load, clicking one shows only that one (report 2026-10-01).
  const [billingOpen, setBillingOpen] = useState(false);
  const [shippingOpen, setShippingOpen] = useState(false);
  const [customerDialogOpen, setCustomerDialogOpen] = useState(false);
  const [customerDialogMode, setCustomerDialogMode] = useState<'create' | 'edit'>('create');
  const [customerForm, setCustomerForm] = useState<CustomerFormState>(initialCustomerForm);
  const [tags, setTags] = useState<string[]>([]);
  const [originalCustomerId, setOriginalCustomerId] = useState('');
  const [visibleLineColumns, setVisibleLineColumns] = useState<Record<string, boolean>>(() => {
    const defaults = { sku: true, description: true, model_number: true, product_notes: false, lot_number: true, expiry_date: true, tax_rate: true, stock: true };
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

  const { data: existingInvoice, isLoading: isInvoiceLoading, isSuccess: invoiceLoaded, refetch: refetchInvoice } = useQuery({
    queryKey: ['invoice-edit', id],
    queryFn: async () => {
      const response = await api.get(`/invoices/${id}`);
      return response.data;
    },
    enabled: isEditMode,
  });

  // Tenant-level sales document defaults.
  // Issue #540: read from the defaults endpoint every invoice creator can
  // reach — GET /settings/sales is OWNER/ADMIN-only, so other roles silently
  // lost default notes, terms and the approval policy.
  // Issue #316: also fetched in edit mode, because the industry field profile
  // gates the Cases card there too. The create-only defaults below keep
  // ignoring it when editing (`salesSettings` is null in edit mode).
  const { data: salesDefaults } = useQuery({
    queryKey: ['sales-settings', 'defaults'],
    queryFn: async () => {
      const res = await api.get('/settings/sales/defaults');
      return res.data?.data ?? null;
    },
    staleTime: 60_000,
  });
  const salesSettings = isEditMode ? null : salesDefaults;

  const { data: selectedCustomerDetail } = useQuery<CustomerRecord>({
    queryKey: ['customer-document', customerId],
    queryFn: async () => {
      const response = await api.get(`/customers/${customerId}`);
      return response.data;
    },
    enabled: Boolean(customerId),
  });

  // #301: the customer picker searches the server; the selected customer comes
  // from its own detail fetch, so no 1,000-row customer preload is needed.
  const selectedCustomer = selectedCustomerDetail;

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
    if (restoredCustomerRef.current !== null) {
      if (String(selectedCustomer.id) === restoredCustomerRef.current) return;
      restoredCustomerRef.current = null;
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
    const shippingContact = getCustomerContactForRole(selectedCustomer, 'shipping');
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

    setBillingContactId(primaryContact?.id != null ? String(primaryContact.id) : '');
    setShippingContactId(shippingContact?.id != null ? String(shippingContact.id) : '');
    setDoctorContactId('');
    setRequestorContactId('');
    // Invalidate any in-flight contact-address lookups from the previous pick.
    contactAddressRequestRef.current.billing += 1;
    contactAddressRequestRef.current.shipping += 1;

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

  // D6/D9: fetch the customer's latest invoice once per pick on NEW documents
  // (two cheap calls) — feeds "Repeat last invoice" and remembered choices.
  useEffect(() => {
    if (!customerId || isEditMode) {
      setLastInvoice(null);
      appliedLastInvoiceRef.current = null;
      return;
    }

    let cancelled = false;

    (async () => {
      try {
        const listRes = await api.get('/invoices', { params: { customer_id: customerId, per_page: 1 } });
        const row = (listRes.data?.data ?? [])[0];
        if (!row || cancelled) return;

        const detailRes = await api.get(`/invoices/${row.id}`);
        const full = detailRes.data?.data ?? detailRes.data;
        if (!full || cancelled) return;

        const trace = (full.lines ?? []).find((line: any) => line.pricing_trace?.pricelist_id)?.pricing_trace;

        setLastInvoice({
          id: Number(full.id),
          number: full.invoice_number ?? `#${full.id}`,
          warehouseId: full.sales_order?.warehouse_id ? String(full.sales_order.warehouse_id) : undefined,
          priceListId: trace?.pricelist_id ? String(trace.pricelist_id) : undefined,
          notes: full.notes || undefined,
          terms: full.payment_terms_snapshot != null ? Number(full.payment_terms_snapshot) : null,
          lines: full.lines ?? [],
          contacts: {
            billing: full.billing_contact_id ? String(full.billing_contact_id) : undefined,
            shipping: full.shipping_contact_id ? String(full.shipping_contact_id) : undefined,
            doctor: full.doctor_contact_id ? String(full.doctor_contact_id) : undefined,
            requestor: full.requestor_contact_id ? String(full.requestor_contact_id) : undefined,
            buyer: full.buyer_contact_id ? String(full.buyer_contact_id) : undefined,
          },
        });
      } catch {
        if (!cancelled) setLastInvoice(null);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [customerId, isEditMode]);

  // D9: apply the remembered choices once per fetched invoice, never
  // overriding anything the user already touched on this document.
  useEffect(() => {
    if (!lastInvoice || isEditMode || !selectedCustomerDetail) return;
    if (appliedLastInvoiceRef.current === lastInvoice.id) return;
    appliedLastInvoiceRef.current = lastInvoice.id;
    if (restoredCustomerRef.current !== null && restoredCustomerRef.current === customerId) return;

    const reused: string[] = [];

    if (lastInvoice.warehouseId && !warehouseTouchedRef.current && lastInvoice.warehouseId !== warehouseId) {
      setWarehouseId(lastInvoice.warehouseId);
      reused.push('warehouse');
    }
    if (lastInvoice.priceListId && !priceListId) {
      setPriceListId(lastInvoice.priceListId);
      reused.push('price list');
      void resolvePricingForLines(customerId, lines, undefined, lastInvoice.priceListId);
    }
    if (lastInvoice.notes && !notes) {
      setNotes(lastInvoice.notes);
      reused.push('notes');
    }
    if (!termsOverridden && lastInvoice.terms != null && Number.isFinite(lastInvoice.terms)) {
      setPaymentTerms(lastInvoice.terms);
      setDueDate(calcDueDate(invoiceDate, lastInvoice.terms));
      reused.push('terms');
    }

    // Contacts are reused only while they still belong to this customer.
    const contacts: any[] = (selectedCustomerDetail as any)?.customer_contacts
      ?? (selectedCustomerDetail as any)?.customerContacts
      ?? [];
    const belongs = (id?: string) => (id && contacts.some((contact) => String(contact.id) === id) ? id : undefined);
    const billing = belongs(lastInvoice.contacts.billing);
    const shipping = belongs(lastInvoice.contacts.shipping);
    const doctor = belongs(lastInvoice.contacts.doctor);
    const requestor = belongs(lastInvoice.contacts.requestor);
    const buyer = belongs(lastInvoice.contacts.buyer);
    if (billing && !billingContactId) {
      setBillingContactId(billing);
      reused.push('billing contact');
    }
    if (shipping && !shippingContactId) {
      setShippingContactId(shipping);
      reused.push('shipping contact');
    }
    if (doctor && !doctorContactId) {
      setDoctorContactId(doctor);
      reused.push('doctor');
    }
    if (requestor && !requestorContactId) setRequestorContactId(requestor);
    if (buyer && !buyerContactId) setBuyerContactId(buyer);

    if (reused.length) {
      setReuseNotice(`Reused from ${lastInvoice.number}: ${reused.join(', ')}.`);
    }
    // Runs once per fetched invoice (appliedLastInvoiceRef); the referenced
    // state is deliberately read as "has the user touched it yet" and must
    // not re-trigger the effect.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastInvoice, isEditMode, selectedCustomerDetail]);

  useEffect(() => {
    if (!existingInvoice || !invoiceLoaded) return;
    const hydrateKey = String(existingInvoice.id);
    if (hydratedInvoiceIdRef.current !== hydrateKey) {
      hydratedInvoiceIdRef.current = hydrateKey;
      linesTouchedRef.current = false;
    }
    if (linesTouchedRef.current) return;

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
    setCustomInvoiceNumber(existingInvoice.invoice_number || '');
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
      description_html: line.description_html || '',
      product_notes: line.product_notes || '',
      lot_number: line.lot_number || '',
      serial_number: line.serial_number || '',
      product_batch_id: line.product_batch_id || null,
      product_serial_id: line.product_serial_id || null,
      tracking_method: line.product?.tracking_method || 'none',
      // #1004: UOM lines keep 4-decimal quantities (spinner step); each lines step by 1.
      uom_id: line.uom_id ?? null,
      expiry_date: line.expiry_date ? String(line.expiry_date).split('T')[0] : '',
      quantity: (normalizeQuantity(line.quantity ?? 1) || 1) as number,
      unit_price: line.unit_price != null ? Number(line.unit_price) : '',
      tax_rate: line.tax_rate != null ? Number(line.tax_rate) : '',
      discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
    }));
    setLines(mappedLines.length ? mappedLines : [emptyLine()]);
  }, [existingInvoice, invoiceLoaded]);

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

    // Invoices raised before contact defaults were stored show no contact;
    // render the customer's default billing/shipping contact instead of blank,
    // without touching the invoice's saved snapshots or addresses.
    const billingDefault = getCustomerContactForRole(selectedCustomerDetail, 'billing');
    const shippingDefault = getCustomerContactForRole(selectedCustomerDetail, 'shipping');

    setBillingContactId(
      billingMatch?.id != null
        ? String(billingMatch.id)
        : (billingDefault?.id != null ? String(billingDefault.id) : ''),
    );
    setShippingContactId(
      shippingMatch?.id != null
        ? String(shippingMatch.id)
        : (shippingDefault?.id != null ? String(shippingDefault.id) : ''),
    );
    setDoctorContactId(doctorMatch?.id != null ? String(doctorMatch.id) : '');
    setRequestorContactId(requestorMatch?.id != null ? String(requestorMatch.id) : '');
  }, [existingInvoice, isEditMode, selectedCustomerDetail]);

  // Apply tenant-level sales defaults when creating a NEW invoice (skip for edit)
  const [defaultsApplied, setDefaultsApplied] = useState(false);
  const defaultTaxRate = Number((salesSettings as any)?.tax?.default_tax_rate ?? 0);
  // D4: the tenant's industry field profile decides which extra header
  // fields render. 'medical' preserves the pre-setting behaviour.
  const fieldProfile = ((salesDefaults as any)?.invoice?.field_profile ?? 'medical') as
    'general' | 'medical' | 'trading' | 'services';
  // #315: the tenant setting gates the hand-typed invoice number. Until the
  // settings resolve (or if that request fails) the historical behaviour
  // (allowed) is kept — the server remains the enforcement point either way.
  const allowCustomNumber = (salesSettings as any)?.invoice?.allow_custom_number !== false;
  useEffect(() => {
    if (isEditMode || defaultsApplied || !salesSettings) return;
    const inv = salesSettings.invoice;
    if (inv?.default_requires_approval) {
      setRequiresApproval(true);
    }
    // Roadmap Phase 2 / #315 precedence: customer-specific terms beat the
    // tenant default, which beats the 30-day fallback. If the chosen customer
    // already carries authoritative terms (possibly applied before this
    // settings response arrived), the setting must not clobber them.
    const customerTerms = Number(selectedCustomer?.payment_terms);
    const customerHasTerms = selectedCustomer != null
      && Number.isFinite(customerTerms)
      && customerTerms >= 0;
    const defaultTermsDays = Number((inv as any)?.default_payment_terms_days);
    if (!termsOverridden && !customerHasTerms && Number.isFinite(defaultTermsDays) && defaultTermsDays >= 0) {
      setPaymentTerms(defaultTermsDays);
      setDueDate(calcDueDate(invoiceDate, defaultTermsDays));
    }
    if (inv?.default_terms && !terms) {
      setTerms(inv.default_terms);
    }
    if (inv?.default_notes && !notes) {
      setNotes(inv.default_notes);
    }
    if (defaultTaxRate > 0) {
      // Untouched lines ('' tax) inherit the tenant's configured default tax.
      setLines((current) =>
        current.map((line) => (line.tax_rate === '' ? { ...line, tax_rate: defaultTaxRate } : line)),
      );
    }
    setDefaultsApplied(true);
  }, [salesSettings, isEditMode, defaultsApplied, defaultTaxRate, selectedCustomer]);

  // Customer changes re-resolve the default document currency and prices
  // (FR-2.3/FR-5.2). Existing hand-typed prices are kept (FR-6.3).
  useEffect(() => {
    if (!customerId || isEditMode) return;
    void resolvePricingForLines(customerId, lines);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId]);
  // #317: unsaved-changes guard + local (this device only) draft autosave.
  // A new document keeps a draft; an existing one is guarded only (its
  // hydration is spread over several effects, so it is not re-applied).
  const draftGuard = useDocumentDraftGuard({
    kind: isQuoteMode ? 'invoice-quote' : 'invoice',
    documentId: isEditMode ? id : null,
    version: isEditMode ? existingInvoice?.lock_version ?? null : null,
    ready: !isEditMode || invoiceLoaded,
    persist: !isEditMode,
    snapshot: {
      customerId, invoiceDate, paymentTerms, termsOverridden, requiresApproval, customInvoiceNumber, dueDate,
      caseNumber, prNo, contractNo, buyerContactId, plantCode, companyCode, dateOfCase, caseLocation,
      patientName, costCenterId, deliveryDate, warehouseId, poNumber, doctorContactId, requestorContactId,
      extraCases, primaryMedicalCaseId, billingContactId, shippingContactId, billingAddress, shippingAddress,
      notes, terms, discountMode, discountAmount, lines, documentSnapshot, tags, currencyCode, priceListId,
    },
    apply: (draft) => {
      restoredCustomerRef.current = draft.customerId || null;
      warehouseTouchedRef.current = true;
      setCustomerId(draft.customerId);
      setInvoiceDate(draft.invoiceDate);
      setPaymentTerms(draft.paymentTerms);
      setTermsOverridden(draft.termsOverridden);
      setRequiresApproval(draft.requiresApproval);
      setCustomInvoiceNumber(draft.customInvoiceNumber);
      setDueDate(draft.dueDate);
      setCaseNumber(draft.caseNumber);
      setPrNo(draft.prNo);
      setContractNo(draft.contractNo);
      setBuyerContactId(draft.buyerContactId);
      setPlantCode(draft.plantCode);
      setCompanyCode(draft.companyCode);
      setDateOfCase(draft.dateOfCase);
      setCaseLocation(draft.caseLocation);
      setPatientName(draft.patientName);
      setCostCenterId(draft.costCenterId);
      setDeliveryDate(draft.deliveryDate);
      setWarehouseId(draft.warehouseId);
      setPoNumber(draft.poNumber);
      setDoctorContactId(draft.doctorContactId);
      setRequestorContactId(draft.requestorContactId);
      setExtraCases(draft.extraCases);
      setPrimaryMedicalCaseId(draft.primaryMedicalCaseId);
      setBillingContactId(draft.billingContactId);
      setShippingContactId(draft.shippingContactId);
      setBillingAddress(draft.billingAddress);
      setShippingAddress(draft.shippingAddress);
      setNotes(draft.notes);
      setTerms(draft.terms);
      setDiscountMode(draft.discountMode);
      setDiscountAmount(draft.discountAmount);
      setLines(draft.lines);
      setDocumentSnapshot(draft.documentSnapshot);
      setTags(draft.tags);
      setCurrencyCode(draft.currencyCode);
      setPriceListId(draft.priceListId);
    },
  });

  const saveMutation = useMutation({
    mutationFn: async (data: Record<string, any>) => {
      // Issue #906: keep the failed payload so "Overwrite" can resend it
      // against the conflict's current version.
      lastSavePayloadRef.current = data;
      const response = isEditMode
        ? await api.put(`/invoices/${id}`, data)
        : await api.post('/invoices', data);
      return response.data;
    },
    onSuccess: async (data) => {
      setConflict(null);
      draftGuard.clearDraft();
      // Issue #326: a save under the `warn` credit policy carries the verdict.
      // Issue #327: a create that resembles another invoice warns too.
      const warnings = [creditWarning(data), duplicateWarning(data)].filter(Boolean).join(' ');
      toast({ title: `${documentLabel} ${isEditMode ? 'updated' : 'created'} successfully`, description: warnings || undefined });
      const invoiceId = id || data?.id || data?.data?.id;
      const savedStatus = data?.status ?? data?.data?.status;

      // D8: Save & Send — save, post when still a draft, then send ONE email
      // with the PDF, acceptance link, pay-now link and QR.
      if (sendAfterSave && invoiceId && !isQuoteMode) {
        setSendAfterSave(false);
        try {
          if (savedStatus === 'draft') {
            await api.post(`/invoices/${invoiceId}/post`);
          }
          await api.post(`/invoices/${invoiceId}/send-bundle`, {});
          toast({
            title: 'Invoice sent to customer',
            description: 'PDF, acceptance link and pay-now QR emailed in one message.',
          });
        } catch (error: any) {
          toast({
            variant: 'destructive',
            title: 'Saved, but sending failed',
            description: getApiErrorMessage(error) || 'Open the invoice and use Send to retry.',
          });
        }
      }

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
    onError: (error: any, variables: unknown) => {
      const message = getApiErrorMessage(error);
      // Issue #327: likely duplicate — ask before creating it anyway.
      const duplicate = likelyDuplicateFromError(error);
      if (duplicate) {
        setLikelyDuplicate(duplicate);
        return;
      }
      // FR-6.3: a below-floor manual override needs an audited reason — ask
      // for it once, then retry with the reason attached to manual lines.
      if (typeof message === 'string' && message.toLowerCase().includes('override_reason')) {
        setOverrideReason('');
        setOverrideDialogOpen(true);
        return;
      }
      // Issue #906: a stale 409 opens the compare-and-choose dialog instead
      // of the generic toast (edit mode only — creates cannot conflict).
      const saveConflict = isEditMode
        ? extractSaveConflict(error, (variables ?? {}) as Record<string, any>)
        : null;
      if (saveConflict) {
        setConflict(saveConflict);
        return;
      }
      const inline = fieldErrors.showServer(error, payloadRowsRef.current);
      toast({
        variant: 'destructive',
        title: inline ? 'Please fix the highlighted fields' : `Failed to ${isEditMode ? 'update' : 'create'} ${documentLabel.toLowerCase()}`,
        description: message,
      });
    },
  });

  const { data: sellableCurrencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  });

  const { data: activePriceLists } = useQuery({
    queryKey: ['price-lists', 'active'],
    queryFn: () => pricingApi.listPriceLists({ active_only: true, per_page: 200 }),
    enabled: canViewPriceLists,
  });
  const priceListRows: any[] = (activePriceLists as any)?.data ?? activePriceLists ?? [];

  const applyOverrideReasonAndRetry = () => {
    const reason = overrideReason.trim();
    if (!reason) {
      toast({ variant: 'destructive', title: 'A reason is required for below-floor prices' });
      return;
    }
    const nextLines = lines.map((line) =>
      line.price_mode === 'manual' ? { ...line, override_reason: reason } : line,
    );
    setLines(nextLines);
    setOverrideDialogOpen(false);
    // Pass the reasoned lines explicitly — state updates are async.
    handleSubmit({ preventDefault: () => {} } as React.FormEvent, nextLines);
  };

  const createCustomerMutation = useMutation({
    mutationFn: async (payload: CustomerFormState) => {
      const response = await api.post('/customers', customerFormToCreatePayload(payload));
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
      const response = await api.put(`/customers/${customerId}`, customerFormToUpdatePayload(payload));
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

  /**
   * MRD pricelist-multicurrency FR-4.6/FR-6.2: ask the shared engine resolver
   * for the applicable price-list prices + the document's default currency.
   * The server re-resolves authoritatively on save (price_mode='auto'); this
   * keeps the on-screen value truthful before saving. Issue #314: the race
   * guard (#296) and error surfacing live once in the shared pricing hook.
   */
  const resolvePricingForLines = (
    customerIdArg: string,
    nextLines: InvoiceLine[],
    currencyOverride?: string,
    listOverride?: string,
    // #299 (F7): price against a date that has not landed in state yet — the
    // date input's onChange runs before React re-renders with the new value.
    dateOverride?: string,
  ) => resolvePricingForLinesShared(nextLines, {
    customerIdOverride: customerIdArg,
    currencyOverride,
    listOverride,
    dateOverride,
  });

  const updateLine = (index: number, field: keyof InvoiceLine, value: string | number) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };

    // A hand-typed price is an override (FR-6.2 manual chip / FR-6.3 floor).
    if (field === 'unit_price') {
      updated[index].price_mode = 'manual';
    }

    // Qty changes re-evaluate auto lines against quantity tiers (FR-6.2).
    setLines(updated);
    if (field === 'quantity' && updated[index].price_mode === 'auto') {
      void resolvePricingForLines(customerId, updated);
    }
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

  // Selecting a related contact person renders their default address for that
  // section: the Contact Master default billing/shipping address when the
  // contact is linked, else the customer-contact record's inline address.
  // Falls back silently to the customer's default when the contact has none.
  const applyContactAddress = async (role: 'billing' | 'shipping', contact: CustomerContact) => {
    const requestId = ++contactAddressRequestRef.current[role];
    let masterAddresses: ContactMasterAddress[] | null = null;

    if (contact.core_contact_uuid) {
      try {
        const res = await api.get(`/contacts/${contact.core_contact_uuid}/addresses`);
        masterAddresses = res.data?.data ?? res.data ?? [];
      } catch {
        // Keep the inline contact address as the fallback.
      }
    }

    const nextAddress = buildContactAddress(contact, masterAddresses, role);
    if (!nextAddress || requestId !== contactAddressRequestRef.current[role]) {
      return;
    }

    if (role === 'billing') {
      setBillingAddress(nextAddress);
    } else {
      setShippingAddress(nextAddress);
    }
    updateDocumentSnapshot(
      role === 'billing' ? 'billing_address_snapshot' : 'shipping_address_snapshot',
      addressFormToSnapshot(nextAddress),
    );
  };

  const addLine = () => setLines([...lines, { ...emptyLine(), tax_rate: defaultTaxRate || '' }]);
  const addTextLine = (line_type: 'note' | 'section') => setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => {
    // F4/#290: when the grid is just the lone untouched starter row, REPLACE it
    // instead of appending — otherwise the empty row lingers and blocks saving.
    const next: InvoiceLine[] = lines.length === 1 && isBlankPlaceholderLine(lines[0])
      ? []
      : [...lines];
    selected.forEach(({ product, quantity }) => {
      const existingIndex = next.findIndex(
        (line) => line.line_type === 'product' && line.product_id === String(product.id),
      );
      if (existingIndex >= 0) {
        // #288(d): build a new object — never mutate React state in place.
        const cur = next[existingIndex].quantity === '' ? 0 : Number(next[existingIndex].quantity);
        next[existingIndex] = { ...next[existingIndex], quantity: cur + quantity };
      } else {
        next.push({
          ...emptyLine(),
          product_id: String(product.id),
          sku: product.sku || '',
          // #288(c): use the shared description helper, not the bare name.
          description: getProductLineDescription(product),
          unit_price: Number(product.price || 0),
          quantity,
          // #288(a): same pricing path as a row pick — mark 'auto' and resolve.
          price_mode: 'auto',
          // F2/#288(b): catalogue additions must follow the same tax resolution
          // as a line-row pick (product tax → linked tax → tenant default) —
          // they were silently saved as 0% tax.
          tax_rate: resolveLineTaxRate(product, defaultTaxRate),
          // Issue #689: the lot/serial picker and its required check key off this.
          tracking_method: product.tracking_method || 'none',
        });
      }
    });
    setLines(next);
    // Catalog additions must price through the engine too (list currency +
    // FX), not just echo the catalogue number.
    void resolvePricingForLines(customerId, next);
  };

  // #298 (F6): a warehouse switch must never silently drop lot/serial picks.
  // Selections that also exist in the new warehouse are kept; only the rest
  // are listed in a confirmation dialog, and Cancel keeps the old warehouse.
  const handleWarehouseChange = async (nextWarehouseId: string) => {
    warehouseTouchedRef.current = true;
    if (!nextWarehouseId || nextWarehouseId === warehouseId) {
      setWarehouseId(nextWarehouseId);
      return;
    }

    const selected = lines
      .map((line, index) => ({ line, index }))
      .filter(({ line }) => line.product_batch_id != null || line.product_serial_id != null);
    if (selected.length === 0) {
      setWarehouseId(nextWarehouseId);
      return;
    }

    const clearIndexes: number[] = [];
    await Promise.all(
      selected.map(async ({ line, index }) => {
        if (!line.product_id) return; // free-text lot without ids: never destroyed
        try {
          const res = await api.get(`/products/${line.product_id}/tracking-availability`, {
            params: { warehouse_id: nextWarehouseId },
          });
          const data = res.data?.data ?? res.data ?? {};
          const batchKept =
            line.product_batch_id == null ||
            (data.batches ?? []).some((batch: any) => Number(batch.id) === Number(line.product_batch_id));
          const serialKept =
            line.product_serial_id == null ||
            (data.serials ?? []).some((serial: any) => Number(serial.id) === Number(line.product_serial_id));
          if (!batchKept || !serialKept) clearIndexes.push(index);
        } catch {
          // Availability unknown -> keep the selection (never drop data
          // because a check failed).
        }
      }),
    );

    if (clearIndexes.length === 0) {
      setWarehouseId(nextWarehouseId);
      return;
    }
    setWarehouseClearConfirm({ nextId: nextWarehouseId, clearIndexes });
  };

  const applyWarehouseChange = () => {
    if (!warehouseClearConfirm) return;
    const { nextId, clearIndexes } = warehouseClearConfirm;
    const toClear = new Set(clearIndexes);
    setWarehouseId(nextId);
    setLines((current) =>
      current.map((line, index) =>
        toClear.has(index)
          ? { ...line, product_batch_id: null, product_serial_id: null, lot_number: '', serial_number: '', expiry_date: '' }
          : line,
      ),
    );
    setWarehouseClearConfirm(null);
  };

  // D6(a): load the previous invoice's lines. Prices are carried verbatim, so
  // a repeat keeps the agreed numbers until someone edits them.
  const performRepeatLastInvoice = () => {
    if (!lastInvoice?.lines?.length) return;
    const mapped: InvoiceLine[] = lastInvoice.lines
      .filter((line: any) => (line.line_type ?? 'product') === 'product' && (line.product_id || line.description))
      .map((line: any) => ({
        ...emptyLine(),
        product_id: line.product_id ? String(line.product_id) : '',
        sku: line.product?.sku ?? '',
        description: line.description ?? '',
        description_html: line.description_html || '',
        quantity: line.quantity != null ? (normalizeQuantity(line.quantity) || 1) as number : 1,
        unit_price: line.unit_price != null ? Number(line.unit_price) : '',
        tax_rate: line.tax_rate != null ? Number(line.tax_rate) : (defaultTaxRate || ''),
        discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
        price_mode: 'carried',
        list_price: line.pricing_trace?.list_price ?? null,
      }));
    setLines(mapped);
    setRepeatConfirmOpen(false);
    toast({ title: `Loaded ${mapped.length} line${mapped.length === 1 ? '' : 's'} from ${lastInvoice.number}` });
  };

  const repeatLastInvoice = () => {
    if (!lastInvoice?.lines?.length) {
      toast({ variant: 'destructive', title: 'No previous invoice for this customer' });
      return;
    }
    const hasContent = lines.some((line) => line.product_id || line.description.trim());
    if (hasContent) {
      setRepeatConfirmOpen(true);
      return;
    }
    performRepeatLastInvoice();
  };

  // D6(c): paste rows copied from Excel — "SKU ⇥ Qty [⇥ Price]" per line.
  const parsePastedRows = (text: string) =>
    text
      .split(/\r?\n/)
      .map((row) => row.split('\t'))
      .filter((cols) => cols.some((col) => col.trim() !== ''))
      .map((cols) => {
        const qty = Number((cols[1] ?? '').trim());
        const price = Number((cols[2] ?? '').trim());
        return {
          code: (cols[0] ?? '').trim(),
          quantity: Number.isFinite(qty) && qty > 0 ? qty : 1,
          price: Number.isFinite(price) && price > 0 ? price : null,
        };
      })
      .filter((row) => row.code !== '');

  const importPastedRows = async (text: string) => {
    const rows = parsePastedRows(text);
    if (!rows.length) {
      toast({ variant: 'destructive', title: 'Nothing to paste', description: 'Expected rows like SKU ⇥ Qty.' });
      return;
    }
    setPasteBusy(true);
    try {
      const additions: InvoiceLine[] = [];
      for (const row of rows) {
        let product: any = null;
        try {
          const res = await api.get('/products', { params: { search: row.code, per_page: 10 } });
          const results: any[] = res.data?.data ?? [];
          const needle = row.code.toLowerCase();
          product = results.find((candidate) =>
            [candidate.sku, candidate.barcode].filter(Boolean).some((code: string) => String(code).toLowerCase() === needle))
            ?? results.find((candidate) => String(candidate.name ?? '').toLowerCase() === needle)
            ?? null;
        } catch {
          product = null;
        }

        if (!product) {
          additions.push({ ...emptyLine(), description: row.code, quantity: row.quantity, unit_price: row.price ?? '', tax_rate: defaultTaxRate || '' });
          continue;
        }
        additions.push({
          ...emptyLine(),
          product_id: String(product.id),
          sku: product.sku || '',
          description: product.name || '',
          quantity: row.quantity,
          unit_price: row.price ?? '',
          price_mode: 'auto',
          tax_rate: resolveLineTaxRate(product, defaultTaxRate),
        });
      }
      const next = [...lines, ...additions];
      setLines(next);
      setPasteOpen(false);
      setPasteText('');
      void resolvePricingForLines(customerId, next);
      toast({ title: `Added ${additions.length} row${additions.length === 1 ? '' : 's'}` });
    } finally {
      setPasteBusy(false);
    }
  };

  const handleGridPaste = (event: React.ClipboardEvent) => {
    const text = event.clipboardData?.getData('text/plain') ?? '';
    if (!text.includes('\t')) return;
    event.preventDefault();
    void importPastedRows(text);
  };

  // D6(b): one click adds a frequently bought product (priced by the engine).
  const addFrequentProduct = (product: { id: number; name: string; sku?: string | null }) => {
    const next = [...lines];
    const existing = next.find((line) => line.line_type === 'product' && line.product_id === String(product.id));
    if (existing) {
      const current = existing.quantity === '' ? 0 : Number(existing.quantity);
      existing.quantity = current + 1;
    } else {
      next.push({
        ...emptyLine(),
        product_id: String(product.id),
        sku: product.sku || '',
        description: product.name || '',
        price_mode: 'auto',
        tax_rate: resolveLineTaxRate(product, defaultTaxRate),
      });
    }
    setLines(next);
    void resolvePricingForLines(customerId, next);
  };

  // D6(e): Enter on the last line appends the next empty row.
  const appendLineIfLast = (index: number) => {
    if (index === lines.length - 1) {
      setLines((current) => [...current, { ...emptyLine(), tax_rate: defaultTaxRate || '' }]);
    }
  };

  // D10: command bar. Ctrl/⌘+K opens it; commands mirror the on-screen
  // actions so power users never need the mouse.
  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setCommandOpen((open) => !open);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  const addProductToLines = (product: any, quantity: number, price: number | null) => {
    const next = [...lines];
    const existing = next.find((line) => line.line_type === 'product' && line.product_id === String(product.id));
    if (existing && price == null) {
      const current = existing.quantity === '' ? 0 : Number(existing.quantity);
      existing.quantity = current + quantity;
    } else {
      next.push({
        ...emptyLine(),
        product_id: String(product.id),
        sku: product.sku || '',
        description: product.name || '',
        quantity,
        unit_price: price ?? '',
        price_mode: price != null ? 'manual' : 'auto',
        tax_rate: resolveLineTaxRate(product, defaultTaxRate),
      });
    }
    setLines(next);
    void resolvePricingForLines(customerId, next);
  };

  const executeCommand = async (raw: string) => {
    const input = raw.trim();
    if (!input) return;
    const lower = input.toLowerCase();

    const closeBar = () => {
      setCommandOpen(false);
      setCommandText('');
      setCommandResults([]);
    };

    const termsMatch = lower.match(/^terms\s+(\d{1,3})$/);
    if (termsMatch) {
      const days = Number(termsMatch[1]);
      setPaymentTerms(days);
      setDueDate(calcDueDate(invoiceDate, days));
      setTermsOverridden(true);
      toast({ title: `Terms set to Net ${days}` });
      closeBar();
      return;
    }

    const discountMatch = lower.match(/^discount\s+(\d+(?:\.\d+)?)(%?)$/);
    if (discountMatch) {
      const value = Number(discountMatch[1]);
      if (discountMatch[2] === '%') {
        setDiscountMode('percentage');
        setDiscountAmount(value);
      } else {
        setDiscountMode('fixed');
        setDiscountAmount(value);
      }
      toast({ title: `Discount set to ${discountMatch[1]}${discountMatch[2] || ' (fixed amount)'}` });
      closeBar();
      return;
    }

    const currencyMatch = input.match(/^currency\s+([A-Za-z]{3})$/);
    if (currencyMatch) {
      const code = currencyMatch[1].toUpperCase();
      setCurrencyCode(code);
      void resolvePricingForLines(customerId, lines, code);
      toast({ title: `Currency set to ${code}` });
      closeBar();
      return;
    }

    if (lower.startsWith('note ')) {
      setNotes(input.slice(5).trim());
      toast({ title: 'Notes updated' });
      closeBar();
      return;
    }

    const addMatch = input.match(/^add\s+(?:(\d+(?:\.\d+)?)\s+)?(.+?)(?:\s*@\s*(\d+(?:\.\d+)?))?$/i);
    if (addMatch) {
      const quantity = addMatch[1] ? Number(addMatch[1]) : 1;
      const query = addMatch[2].trim();
      const price = addMatch[3] ? Number(addMatch[3]) : null;

      setCommandBusy(true);
      try {
        const res = await api.get('/products', { params: { search: query, per_page: 8 } });
        const results: any[] = res.data?.data ?? [];
        if (!results.length) {
          toast({ variant: 'destructive', title: `No product matches “${query}”` });
          return;
        }
        const needle = query.toLowerCase();
        const exact = results.find((candidate) =>
          [candidate.sku, candidate.barcode].filter(Boolean).some((code: string) => String(code).toLowerCase() === needle));
        const chosen = exact ?? results[0];
        addProductToLines(chosen, quantity, price);
        toast({ title: `${quantity} × ${chosen.name} added${exact ? '' : ' (closest match — check it)'}` });
        closeBar();
      } finally {
        setCommandBusy(false);
      }
      return;
    }

    // Anything else is a product search — pick from the results.
    setCommandBusy(true);
    try {
      const res = await api.get('/products', { params: { search: input, per_page: 8 } });
      setCommandResults(res.data?.data ?? []);
    } finally {
      setCommandBusy(false);
    }
  };

  const { data: frequentProducts = [] } = useQuery<Array<{ id: number; name: string; sku?: string | null; total_quantity?: number; times?: number }>>({
    queryKey: ['customer-frequent-products', customerId],
    queryFn: async () => {
      const res = await api.get(`/customers/${customerId}/frequent-products`);
      return res.data?.data ?? [];
    },
    enabled: !!customerId,
  });

  const duplicateLine = (index: number) => {
    fieldErrors.clearLines();
    setLines((current) => duplicateSalesDocumentLine(current, index));
  };

  const moveLine = (index: number, direction: 'up' | 'down') => {
    fieldErrors.clearLines();
    setLines((current) => moveSalesDocumentLine(current, index, direction));
  };

  const removeLine = (index: number) => {
    fieldErrors.clearLines();
    setLines((current) => removeSalesDocumentLine(current, index));
  };

  const resetLine = (index: number) => {
    fieldErrors.clearLines();
    setLines((current) => resetSalesDocumentLine(current, index, emptyLine()));
  };

  const getLineTotal = (line: InvoiceLine) => getSalesDocumentLineTotal(line as SalesDocumentLine);

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
    setCustomerForm(customerToFormValues(selectedCustomer));
    setCustomerDialogOpen(true);
  };

  // Mirrors Invoice::calculateTotals() via the shared helper so the on-screen
  // figure always matches what the server persists (issue #289): the document
  // discount is applied BEFORE tax and the line tax is pro-rated by
  // (subtotal − discount) / subtotal, rounded to 2dp.
  const { subtotal, lineDiscounts, documentDiscountAmount, taxTotal, total } =
    computeDocumentTotals(lines, { mode: discountMode, amount: discountAmount });

  const handleCustomerFormSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (customerDialogMode === 'edit') {
      updateCustomerMutation.mutate(customerForm);
      return;
    }

    createCustomerMutation.mutate(customerForm);
  };

    const applyMedicalCaseToPrimary = (medicalCase: MedicalCaseOption): InvoiceCaseRow => ({
    case_number: medicalCase.case_no || '',
    date_of_case: medicalCase.case_date ? String(medicalCase.case_date).slice(0, 10) : '',
    case_location: medicalCase.location || '',
    patient_name: medicalCase.patient_name || medicalCase.patient_reference || '',
    cost_center_id: '',
    delivery_date: '',
    pr_no: '',
    contract_no: '',
    plant_code: '',
    company_code: '',
    po_number: '',
    doctor_contact_id: medicalCase.doctor_contact_id ? String(medicalCase.doctor_contact_id) : '',
    requestor_contact_id: medicalCase.requestor_contact_id ? String(medicalCase.requestor_contact_id) : '',
    buyer_contact_id: medicalCase.buyer_contact_id ? String(medicalCase.buyer_contact_id) : '',
    medical_case_id: medicalCase.id,
  });

  const primaryCaseRow = (): InvoiceCaseRow => ({
    medical_case_id: primaryMedicalCaseId,
    case_number: caseNumber, date_of_case: dateOfCase, case_location: caseLocation,
    patient_name: patientName, cost_center_id: costCenterId, delivery_date: deliveryDate,
    pr_no: prNo, contract_no: contractNo, plant_code: plantCode, company_code: companyCode,
    po_number: poNumber, doctor_contact_id: doctorContactId,
    requestor_contact_id: requestorContactId, buyer_contact_id: buyerContactId,
  });

  const toCasePayload = (row: InvoiceCaseRow) => ({
    medical_case_id: row.medical_case_id ?? null,
    case_number: row.case_number || null,
    date_of_case: row.date_of_case || null,
    case_location: row.case_location || null,
    patient_name: row.patient_name || null,
    cost_center_id: row.cost_center_id || null,
    delivery_date: row.delivery_date || null,
    pr_no: row.pr_no || null,
    contract_no: row.contract_no || null,
    plant_code: row.plant_code || null,
    company_code: row.company_code || null,
    po_number: row.po_number || null,
    doctor_contact_id: row.doctor_contact_id && row.doctor_contact_id.includes('-') ? row.doctor_contact_id : null,
    requestor_contact_id: row.requestor_contact_id && row.requestor_contact_id.includes('-') ? row.requestor_contact_id : null,
    buyer_contact_id: row.buyer_contact_id && row.buyer_contact_id.includes('-') ? row.buyer_contact_id : null,
  });

  const buildCasesPayload = () =>
    [toCasePayload(primaryCaseRow()), ...extraCases.map(toCasePayload)].filter((row) =>
      Object.entries(row).some(([key, value]) => key !== 'medical_case_id' && value !== null && value !== ''),
    );


  // The Case Information block hydrates independently of the line-items
  // dirty-gate above: opening an existing invoice must always show its
  // stored case details, the registry link and any extra cases.
  const caseHydratedInvoiceRef = useRef<string | null>(null);
  useEffect(() => {
    if (!existingInvoice || !invoiceLoaded) return;
    const key = String(existingInvoice.id);
    if (caseHydratedInvoiceRef.current === key) return;
    caseHydratedInvoiceRef.current = key;

    setCaseNumber(existingInvoice.case_number || '');
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

    const storedCases = Array.isArray(existingInvoice.case_information) ? existingInvoice.case_information : [];
    setPrimaryMedicalCaseId(storedCases[0]?.medical_case_id ? Number(storedCases[0].medical_case_id) : null);
    setExtraCases(
      storedCases.slice(1).map((row: any) => ({
        ...emptyInvoiceCase(),
        ...row,
        date_of_case: row.date_of_case ? String(row.date_of_case).slice(0, 10) : '',
        delivery_date: row.delivery_date ? String(row.delivery_date).slice(0, 10) : '',
        doctor_contact_id: row.doctor_contact_id ? String(row.doctor_contact_id) : '',
        requestor_contact_id: row.requestor_contact_id ? String(row.requestor_contact_id) : '',
        buyer_contact_id: row.buyer_contact_id ? String(row.buyer_contact_id) : '',
      })),
         );
  }, [existingInvoice, invoiceLoaded]);

  // Existing invoices often carry only a free-text case number. When it
  // matches a case in the registry (same customer), link it automatically.
  useEffect(() => {
    if (!isEditMode || !caseNumber || !customerId || primaryMedicalCaseId) return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await api.get('/medical-cases', { params: { search: caseNumber, customer_id: customerId, per_page: '10' } });
        const rows = (res.data?.data ?? []) as Array<{ id: number; case_no?: string | null }>;
        const match = rows.find((row) => (row.case_no || '').toLowerCase() === caseNumber.trim().toLowerCase());
        if (!cancelled && match) setPrimaryMedicalCaseId(match.id);
      } catch {
        // Registry lookup is best-effort; the free-text number stays valid.
      }
    })();
    return () => { cancelled = true; };
  }, [isEditMode, caseNumber, customerId, primaryMedicalCaseId]);

  const handleSubmit = (event: React.FormEvent, linesOverride?: InvoiceLine[]) => {
    event.preventDefault();
    // F4/#290: untouched placeholder rows are ignored everywhere — they used to
    // block saving ("Product required on line 1") after catalogue adds while
    // an empty starting row remained.
    const sourceLines = linesOverride ?? lines;
    const submissionLines = sourceLines.filter((line) => !isBlankPlaceholderLine(line));
    // #318: grid row of each submission line, for inline line errors.
    const submissionRows = payloadRowIndexes(sourceLines, (line) => !isBlankPlaceholderLine(line));
    fieldErrors.clearAll();

    if (!customerId) {
      fieldErrors.show({ customer_id: 'Please select a customer' });
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    if (submissionLines.length === 0 || submissionLines.every((line) => !line.description && !line.product_id)) {
      fieldErrors.show({ lines: 'Add at least one line item' });
      toast({ variant: 'destructive', title: 'Please add at least one line item' });
      return;
    }

    const missingProductIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id
    );
    if (missingProductIndex >= 0) {
      fieldErrors.show({ [lineFieldKey(submissionRows[missingProductIndex], 'product_id')]: 'Select a product before saving.' });
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      });
      return;
    }

    // Issue #689: a batch/serial-tracked product line cannot be saved without
    // the lot or serial it issues (quotations reserve nothing, so exempt).
    const missingTrackingIndex = isQuoteMode ? -1 : missingTrackingLineIndex(submissionLines);
    if (missingTrackingIndex >= 0) {
      fieldErrors.show({ [lineFieldKey(submissionRows[missingTrackingIndex], 'lot_number')]: 'Select the lot or serial number this line issues.' });
      toast({
        variant: 'destructive',
        title: `Lot / serial required on line ${missingTrackingIndex + 1}`,
        description: visibleLineColumns.lot_number
          ? 'Select the lot or serial number this line issues.'
          : 'Show the Lot Number column and select the lot or serial number this line issues.',
      });
      return;
    }

    if (discountMode === 'percentage' && (discountAmount === '' ? 0 : discountAmount) > 100) {
      fieldErrors.show({ discount_amount: 'Percentage discount cannot exceed 100%' });
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
      fieldErrors.show({ [lineFieldKey(idx - 1, 'quantity')]: 'Quantity must be a positive number (up to 4 decimal places).' });
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
      cases: buildCasesPayload(),
      pr_no: prNo || undefined, contract_no: contractNo || undefined,
      ...invoiceContactPayload('buyer', buyerContactId),
      plant_code: plantCode || undefined, company_code: companyCode || undefined,
      date_of_case: dateOfCase || undefined,
      case_location: caseLocation || undefined,
      patient_name: patientName || undefined,
      cost_center_id: costCenterId || undefined,
      delivery_date: deliveryDate || undefined,
      warehouse_id: warehouseId ? Number(warehouseId) : undefined,
      po_number: poNumber || undefined,
      // #300: send the picked contact's stable id (customer-contact id or
      // existing core contact UUID) — never its name, which the server would
      // resolve by name and could merge or duplicate contacts.
      ...invoiceContactPayload('doctor', doctorContactId),
      ...invoiceContactPayload('requestor', requestorContactId),
      ...invoiceContactPayload('billing', billingContactId),
      ...invoiceContactPayload('shipping', shippingContactId),
      notes: notes || undefined,
      terms: terms || undefined,
      payment_terms: paymentTerms,
      discount_amount: documentDiscountAmount || 0,
      // FR-5.1/FR-5.2: explicit document currency; empty = resolve default.
      ...(currencyCode ? { currency_code: currencyCode } : {}),
      // FR-2.3 step 1: explicit list selection wins over applicability.
      ...(priceListId ? { price_list_id: Number(priceListId) } : {}),
      // Issue #540: requires_approval is never sent — the server applies the
      // tenant approval policy on create and keeps it on edit.
      ...(!isEditMode && tags.length ? { tags } : {}),
      ...(customInvoiceNumber && (isQuoteMode || allowCustomNumber) ? { invoice_number: customInvoiceNumber } : {}),
      // Issue #896/#906: echo the loaded version so a concurrent editor's
      // save is detected instead of silently overwritten.
      ...(isEditMode && existingInvoice?.lock_version != null
        ? { version: existingInvoice.lock_version }
        : {}),
      ...documentSnapshot,
      lines: submissionLines
        .filter((line) => line.description || line.product_id)
        .map((line) => ({
          line_type: line.line_type || 'product',
          sequence: submissionLines.indexOf(line),
          product_id: line.product_id ? parseInt(line.product_id, 10) : null,
          model_number: line.model_number || null,
          description: line.description || 'Item',
          description_html: line.description_html || null,
          product_notes: line.product_notes || null,
          lot_number: line.lot_number || null,
          serial_number: line.serial_number || null,
          product_batch_id: line.product_batch_id || null,
          product_serial_id: line.product_serial_id || null,
          expiry_date: line.expiry_date || null,
          quantity: line.quantity === '' ? 0 : (Number(normalizeQuantity(line.quantity)) || 0),
          unit_price: line.unit_price === '' ? 0 : line.unit_price,
          price_mode: line.price_mode ?? undefined,
          override_reason: line.override_reason ?? undefined,
          tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
          discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
        })),
    };

    payloadRowsRef.current = payloadRowIndexes(
      sourceLines,
      (line) => !isBlankPlaceholderLine(line) && Boolean(line.description || line.product_id),
    );
    saveMutation.mutate(payload);
  };

  // Issue #906: discard local edits, refetch and re-hydrate the server copy.
  // The invoice page hydrates once per id (and never after line interactions),
  // so resetting those guards is what lets "Reload theirs" repaint the form.
  const handleReloadTheirs = () => {
    setConflict(null);
    hydratedInvoiceIdRef.current = null;
    linesTouchedRef.current = false;
    void refetchInvoice();
  };

  // Issue #906: resend my payload against the version the conflict reported.
  const handleOverwrite = () => {
    if (!conflict || !lastSavePayloadRef.current) return;
    saveMutation.mutate({ ...lastSavePayloadRef.current, version: conflict.currentVersion });
  };

  // Every document-currency figure renders in the SELECTED currency
  // (previously the default tenant symbol leaked through, e.g. S$ line items
  // under an EUR header).
  const money = (value: number) => formatCurrency(value, currencyCode || undefined);
  // The pricing feedback strip only renders when there is something to say —
  // otherwise it left a stray empty bordered row under the header.
  const hasPricingFeedback = Boolean(
    (currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode)
      || pricingMeta?.price_list_name
      || pricingError,
  );

  const customerMutationPending = createCustomerMutation.isPending || updateCustomerMutation.isPending;
  const customerActions = [
    {
      key: 'add-customer',
      label: 'Add Customer',
      description: 'Create and select a new customer without leaving this document.',
      icon: 'solar:add-square-bold-duotone',
      onClick: openCreateCustomerDialog,
      disabled: false,
    },
    {
      key: 'edit-customer',
      label: 'Edit Customer',
      description: 'Update the selected customer before issuing this document.',
      icon: 'solar:pen-bold-duotone',
      onClick: openEditCustomerDialog,
      disabled: !selectedCustomer,
    },
    {
      key: 'manage-customers',
      label: 'All Customers',
      description: 'Open the full customer workspace in a separate screen.',
      icon: 'solar:users-group-rounded-bold-duotone',
      onClick: () => openAppPathInNewTab('/customers'),
      disabled: false,
    },
    {
      key: 'open-profile',
      label: 'Profile',
      description: selectedCustomer
        ? 'Jump directly to the selected customer record.'
        : 'Select a customer first to open the profile.',
      icon: 'solar:user-id-bold-duotone',
      onClick: () => {
        if (selectedCustomer) {
          openAppPathInNewTab(`/customers/${selectedCustomer.id}`);
        }
      },
      disabled: !selectedCustomer,
    },
  ];

  const productActions: Array<{ key: string; label: string; description: string; icon: string; onClick: () => void; disabled?: boolean }> = [
    {
      key: 'product-list',
      label: 'Product List',
      description: 'Browse existing products and reuse them in line items.',
      icon: 'solar:box-bold-duotone',
      onClick: () => openAppPathInNewTab('/products'),
    },
    {
      key: 'create-product',
      label: 'New Product',
      description: 'Add a missing product, service, or catalog item.',
      icon: 'solar:add-square-bold-duotone',
      onClick: () => openAppPathInNewTab('/products/new'),
    },
  ];

  // One compact row: customer + product shortcuts share the same destination
  // (the document being edited), so they render as a single Quick Actions strip.
  const quickActions = [...customerActions, ...productActions];

  const contactNameById = (id: string) => {
    const contacts: any[] = (selectedCustomerDetail as any)?.customer_contacts
      ?? (selectedCustomerDetail as any)?.customerContacts
      ?? [];
    const match = contacts.find((contact) => String(contact.id) === id);
    return match?.name || match?.display_name || '';
  };

  const addressSummary = (address: AddressFormState) =>
    [address.street, address.city, address.state].filter(Boolean).join(', ');

  const caseCount =
    (caseNumber || patientName || dateOfCase || caseLocation || primaryMedicalCaseId ? 1 : 0)
    + extraCases.length;
  // #316: the Cases card follows the field profile, but an invoice that
  // already carries case data keeps showing it so the data stays readable.
  const showCases = fieldProfile === 'medical' || caseCount > 0;

  const detailsSummary = [
    documentSnapshot.customer_reference ? `PO ${documentSnapshot.customer_reference}` : null,
    contactNameById(billingContactId) || documentSnapshot.attention_to || null,
    showCases && caseCount > 0 ? `cases ${caseCount}` : null,
    notes ? 'notes' : null,
  ]
    .filter(Boolean)
    .join(' · ');

  // D5 (invoice review): plain-language explanation of how a line price was
  // arrived at — price list/rule, tier compute, FX rate/date, manual reason.
  const priceExplanation = (line: InvoiceLine): string[] => {
    const notes: string[] = [];
    const trace = line.pricing_trace ?? null;

    if (line.price_mode === 'manual') {
      notes.push('Manual price — typed directly on this line.');
      if (line.override_reason) notes.push(`Reason: ${line.override_reason}`);
      if (line.list_price != null) notes.push(`List price: ${money(Number(line.list_price))}`);
      return notes;
    }

    if (trace?.pricelist_code) notes.push(`Price list: ${trace.pricelist_code}`);
    else if (line.pricing_source) notes.push(`Source: ${String(line.pricing_source).replace(/_/g, ' ')}`);

    if (trace?.compute_type && trace.compute_type !== 'none') {
      const basis = trace.base ? String(trace.base).replace(/_/g, ' ') : 'list price';
      const amount = trace.compute_value != null
        ? `${trace.compute_value}${trace.compute_type === 'percentage' ? '%' : ''} `
        : '';
      notes.push(`Rule: ${amount}${String(trace.compute_type).replace(/_/g, ' ')} on ${basis}`);
    }

    if (
      trace?.rate_used != null
      && line.pricing_currency
      && line.pricing_base_currency
      && line.pricing_currency !== line.pricing_base_currency
    ) {
      const rateDate = trace.rate_date ? ` on ${String(trace.rate_date).slice(0, 10)}` : '';
      notes.push(`FX: 1 ${line.pricing_currency} = ${trace.rate_used} ${line.pricing_base_currency}${rateDate}`);
    }

    if (line.list_price != null && line.unit_price !== '' && Number(line.unit_price) < Number(line.list_price)) {
      notes.push(`List price: ${money(Number(line.list_price))}`);
    }

    return notes.length ? notes : ['Price resolved by the pricing engine.'];
  };

  return (
    <TooltipProvider delayDuration={150}>
      <div className="p-6 space-y-6" data-density={density} {...draftGuard.trackProps}>
        <div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => draftGuard.guard(() => navigate(backPath))}
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

        <EditingBanner
          activeEditor={isEditMode ? existingInvoice?.active_lock : null}
          currentUserId={currentUserId}
        />

        <DocumentDraftBanner guard={draftGuard} label={documentLabel.toLowerCase()} />

        {/* #318: validation is reported inline on the field (scroll + focus);
            native browser bubbles would block submit before it can run. */}
        <FieldErrorsProvider value={fieldErrors}>
        <form noValidate onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
          <Card className="mb-6">
            <CardHeader>
              <CardTitle>{documentLabel} Details</CardTitle>
            </CardHeader>
            <CardContent>
              {/* Compact single-strip header (MRD §11.2): rounded pill fields
                  that stay on one row on wide screens, with inline pricing
                  feedback (rate snapshot, applied list, resolution errors). */}
              {/* 12-column document header grid: related fields align on one
                  row (customer/warehouse/dates/currency) and the commercial
                  row (price list + number) gets real width. */}
              <div className="grid grid-cols-2 items-end gap-3 md:grid-cols-4 xl:grid-cols-12">
                <div className="col-span-2 md:col-span-2 xl:col-span-6">
                  <label className="mb-1 block text-xs font-medium">Customer *</label>
                  <FieldSlot name="customer_id">
                    <CustomerSearchCombobox
                      compact
                      value={customerId}
                      onValueChange={(id, customer) => {
                        fieldErrors.clear('customer_id')
                        setCustomerId(id)
                        // D1: keep the match explanation so the operator can see
                        // the pick came from a contact/tag/address search.
                        setCustomerMatch(customer ? {
                          contacts: (customer as any).matched_contacts ?? [],
                          tags: (customer as any).matched_tags ?? [],
                          addresses: (customer as any).matched_addresses ?? [],
                        } : null)
                        if (customer) {
                          // Auto-populate customer reference if available
                          if ((customer as any).code) {
                            setDocumentSnapshot(prev => ({ ...prev, customer_reference: (customer as any).code }))
                          }
                        }
                      }}
                    />
                  </FieldSlot>
                  {customerMatch && (customerMatch.contacts.length > 0 || customerMatch.tags.length > 0 || customerMatch.addresses.length > 0) && (
                    <p className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-[11px] leading-snug text-muted-foreground">
                      {customerMatch.contacts.length > 0 && (
                        <span>Matched contact: <span className="text-primary">{customerMatch.contacts.join(', ')}</span></span>
                      )}
                      {customerMatch.tags.length > 0 && (
                        <span>Tag: <span className="text-amber-600">{customerMatch.tags.join(', ')}</span></span>
                      )}
                      {customerMatch.addresses.length > 0 && (
                        <span className="truncate">Address: {customerMatch.addresses.join(' · ')}</span>
                      )}
                    </p>
                  )}
                </div>
                <div className="col-span-1 md:col-span-1 xl:col-span-3">
                  <label className="mb-1 block text-xs font-medium">{isQuoteMode ? 'Quote Date' : 'Invoice Date'}</label>
                  <input
                    type="date"
                    value={invoiceDate}
                    onChange={(event) => {
                      const newDate = event.target.value;
                      setInvoiceDate(newDate);
                      setDueDate(calcDueDate(newDate, paymentTerms));
                      // #299 (F7): price lists are date-effective — a new
                      // invoice date must re-resolve auto/carried lines for
                      // that date (manual lines are kept by the apply loop).
                      if (customerId) {
                        void resolvePricingForLines(customerId, lines, undefined, undefined, newDate);
                      }
                    }}
                    className="h-9 w-full min-w-0 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>

                {/* Terms / Valid until live on the first row, next to the dates. */}
                {!isQuoteMode ? (
                  <div className="col-span-1 md:col-span-1 xl:col-span-3">
                    <label className="mb-1 block text-xs font-medium">Terms</label>
                    <HeaderChip
                      icon="solar:calendar-bold-duotone"
                      value={`${paymentTerms > 0 ? `Net ${paymentTerms}` : 'Due on receipt'} · ${dueDate ? formatDate(dueDate) : 'No due date'}`}
                      title="Payment terms and due date"
                      className="w-full justify-between"
                    >
                      <div className="space-y-2">
                        <div>
                          <label className="mb-1 block text-xs font-medium">Payment terms (days)</label>
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
                            className="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                          />
                        </div>
                        <div>
                          <label className="mb-1 block text-xs font-medium">Due date</label>
                          <input
                            type="date"
                            value={dueDate}
                            onChange={(event) => {
                              setDueDate(event.target.value);
                              // HR71-04: an intentional due-date edit is an override.
                              setTermsOverridden(true);
                            }}
                            className="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                          />
                        </div>
                      </div>
                    </HeaderChip>
                  </div>
                ) : (
                  <div className="col-span-1 md:col-span-1 xl:col-span-3">
                    <label className="mb-1 block text-xs font-medium">Valid until</label>
                    <HeaderChip
                      icon="solar:calendar-bold-duotone"
                      value={dueDate ? formatDate(dueDate) : 'Not set'}
                      title="Quote validity date"
                      className="w-full justify-between"
                    >
                      <input
                        type="date"
                        value={dueDate}
                        onChange={(event) => setDueDate(event.target.value)}
                        className="h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                      />
                    </HeaderChip>
                  </div>
                )}

              </div>

              {/* D3: chip header — every secondary header value is a compact
                  chip; clicking one edits it in place. */}
              <div className="mt-3 flex flex-wrap items-center gap-2">
                {!isQuoteMode && (
                  <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Stock warehouse — changing it asks before clearing unavailable batch/serial selections">
                    <span className="text-muted-foreground">Warehouse</span>
                    <select
                      value={warehouseId}
                      onChange={(event) => { void handleWarehouseChange(event.target.value); }}
                      className="max-w-[14rem] bg-transparent text-xs font-medium focus:outline-none"
                    >
                      <option value="">Select</option>
                      {warehouses.map((warehouse: any) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}{warehouse.is_default ? ' (Default)' : ''}</option>)}
                    </select>
                  </label>
                )}
                <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Document currency — auto follows the customer's price list">
                  <span className="text-muted-foreground">Currency</span>
                  <select
                    value={currencyCode}
                    onChange={(event) => {
                      const next = event.target.value;
                      setCurrencyCode(next);
                      void resolvePricingForLines(customerId, lines, next || undefined);
                    }}
                    className="bg-transparent text-xs font-medium focus:outline-none"
                  >
                    <option value="">Auto</option>
                    {currencyCode && !(sellableCurrencies as any[]).some((c: any) => c.code === currencyCode) && (
                      <option value={currencyCode}>{currencyCode}</option>
                    )}
                    {(sellableCurrencies as any[]).map((currency: any) => (
                      <option key={currency.code} value={currency.code}>
                        {currency.code}{currency.symbol ? ` ${currency.symbol}` : ''}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Price list — Auto applies the customer's list">
                  <span className="text-muted-foreground">List</span>
                  <select
                    value={priceListId}
                    onChange={(event) => {
                      const next = event.target.value;
                      setPriceListId(next);
                      void resolvePricingForLines(customerId, lines, undefined, next);
                    }}
                    className="max-w-[18rem] bg-transparent text-xs font-medium focus:outline-none"
                  >
                    <option value="">Auto</option>
                    {priceListRows.map((list: any) => (
                      <option key={list.id} value={String(list.id)}>
                        {list.name} · {list.currency_code}{list.code ? ` (${list.code})` : ''}
                      </option>
                    ))}
                  </select>
                </label>
                {(isQuoteMode || allowCustomNumber) && (
                  <HeaderChip
                    icon="solar:document-text-bold-duotone"
                    label="Number"
                    value={customInvoiceNumber || 'Auto'}
                    title={`${documentLabel} number`}
                  >
                    <input
                      type="text"
                      value={customInvoiceNumber}
                      onChange={(e) => setCustomInvoiceNumber(e.target.value)}
                      placeholder={`INV-${new Date().getFullYear()}-XXXX`}
                      className="h-8 w-full rounded-md border border-input bg-background px-2.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                    />
                  </HeaderChip>
                )}
                {!isQuoteMode && requiresApproval && (
                  <StaticChip
                    icon="solar:checklist-bold-duotone"
                    value="Approval req."
                    title="This invoice will require approval before posting"
                  />
                )}
                {/* 8.3: all secondary details live in one drawer, with a live summary. */}
                <button
                  type="button"
                  onClick={() => setDetailsOpen(true)}
                  title="References, contacts, addresses, notes and profile fields"
                  className="inline-flex h-8 max-w-[22rem] items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/60"
                >
                  <Icon icon="solar:document-add-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                  <span className="truncate">
                    Details{detailsSummary ? ` · ${detailsSummary}` : ''}
                  </span>
                </button>
                {/* 8.3: comfort/compact density, remembered per user. */}
                <button
                  type="button"
                  onClick={() => setDensity(density === 'compact' ? 'comfortable' : 'compact')}
                  title="Switch between comfortable and compact row density"
                  className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/60"
                >
                  <Icon icon="solar:slider-vertical-bold" className="h-3.5 w-3.5" />
                  {density === 'compact' ? 'Compact' : 'Comfortable'}
                </button>
                {/* D10: keyboard command bar. */}
                <button
                  type="button"
                  onClick={() => setCommandOpen(true)}
                  title="Command bar (Ctrl/⌘+K)"
                  className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/60"
                >
                  <Icon icon="solar:keyboard-bold" className="h-3.5 w-3.5" />
                  Commands · ⌘K
                </button>
              </div>

              {/* D9: what was reused from the customer's last invoice. */}
              {reuseNotice && (
                <div className="mt-2 flex items-center gap-2 text-[11px] text-muted-foreground">
                  <Icon icon="solar:history-bold" className="h-3.5 w-3.5" />
                  {reuseNotice}
                  <button
                    type="button"
                    className="underline hover:text-foreground"
                    onClick={() => setReuseNotice(null)}
                  >
                    dismiss
                  </button>
                </div>
              )}

              {/* D2: compact AR context for the selected customer. */}
              <CustomerContextStrip customerId={customerId || null} className="mt-3" />

              {/* Pricing feedback: rate snapshot, applied list, resolution
                  errors. Full width so long list names are never squeezed. */}
              {hasPricingFeedback && (
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs dark:border-slate-800 dark:bg-slate-900/40">
                  {currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode && (
                    <span className={pricingMeta.rate_stale ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}>
                      1 {currencyCode} = {Number(pricingMeta.exchange_rate || 0).toFixed(4)} {pricingMeta.base_currency_code}
                      {pricingMeta.rate_date ? ` · ${pricingMeta.rate_date}` : ''}
                      {pricingMeta.rate_source ? ` · ${pricingMeta.rate_source}` : ''}
                      {pricingMeta.rate_stale ? ' · stale' : ''}
                    </span>
                  )}
                  {pricingMeta?.price_list_name && (
                    <span className="text-muted-foreground">
                      List: {pricingMeta.price_list_name} ({pricingMeta.price_list_currency ?? currencyCode})
                    </span>
                  )}
                  {pricingError && (
                    <span className="flex items-center gap-1 font-medium text-destructive">
                      <Icon icon="solar:danger-triangle-bold" className="h-3.5 w-3.5" />
                      {pricingError}
                    </span>
                  )}
                </div>
              )}

              {/* One compact Quick Actions row (customer + product shortcuts). */}
              <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Quick Actions</div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                  {quickActions.map((action) => (
                    <button
                      key={action.key}
                      type="button"
                      title={action.description}
                      onClick={action.onClick}
                      disabled={Boolean((action as { disabled?: boolean }).disabled)}
                      className="group flex flex-col items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2 py-3 text-center transition-colors hover:border-primary/40 hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-800 dark:bg-slate-900/40 dark:hover:bg-primary/10"
                    >
                      <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Icon icon={action.icon} className="h-4 w-4" />
                      </span>
                      <span className="text-xs font-medium text-slate-900 dark:text-slate-100">{action.label}</span>
                    </button>
                  ))}
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

              {/* 8.3: one drawer for all secondary details — references,
                  contacts, addresses, notes and profile fields. */}
              <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
                <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-xl" data-density={density}>
                  <div className="border-b px-5 py-4">
                    <SheetHeader>
                      <SheetTitle>Document details</SheetTitle>
                      <SheetDescription>
                        References, contacts, addresses, notes and profile fields.
                      </SheetDescription>
                    </SheetHeader>
                  </div>
                  <div className="space-y-4 px-5 py-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                {/* D4: Trading profile surfaces PO-related delivery date —
                    other profiles don't render it at all. */}
                {fieldProfile === 'trading' && (
                  <div>
                    <label className="block text-sm font-medium mb-1">Delivery Date</label>
                    <input
                      type="date"
                      value={deliveryDate}
                      onChange={(event) => setDeliveryDate(event.target.value)}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    />
                  </div>
                )}
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

              {/* Contacts and addresses are auto-filled from the customer, so they
                  stay collapsed to a one-line summary and only open for edits. */}
              <div className="mt-4 grid grid-cols-1 gap-3">
                <details
                  open={billingOpen}
                  onToggle={(event) => {
                    const open = (event.currentTarget as HTMLDetailsElement).open;
                    setBillingOpen(open);
                    // Accordion: only one section is expanded at a time.
                    if (open) setShippingOpen(false);
                  }}
                  className="group rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
                >
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-2">
                      <Icon icon="solar:card-bold-duotone" className="h-4 w-4 shrink-0 text-slate-400" />
                      <span className="min-w-0">
                        <span className="block text-sm font-semibold text-slate-900">Billing details</span>
                        <span className="mt-0.5 block truncate text-xs text-slate-500">
                          {contactNameById(billingContactId) || documentSnapshot.attention_to || 'No contact'}
                          {' · '}
                          {addressSummary(billingAddress) || 'No address'}
                        </span>
                      </span>
                    </span>
                    <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" />
                  </summary>
                  <div className="mt-3 space-y-3">
                    <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Contact</label>
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
                              void applyContactAddress('billing', contact as CustomerContact);
                            }
                          }}
                          placeholder="Select customer contact..."
                          customerId={customerId}
                          source="customer_contacts"
                        />
                      </div>
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Label</label>
                        <input
                          type="text"
                          value={billingAddress.label}
                          onChange={(event) => updateAddress('billing', 'label', event.target.value)}
                          className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                          placeholder="e.g. HQ"
                        />
                      </div>
                    </div>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                      {([
                        ['street', 'Street'], ['street2', 'Street 2'],
                        ['city', 'City'], ['state', 'State'], ['zip', 'ZIP'], ['country', 'Country'],
                      ] as const).map(([field, label]) => (
                        <div key={field} className={field === 'street' || field === 'street2' ? 'md:col-span-2' : ''}>
                          <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
                          <input
                            type="text"
                            value={billingAddress[field]}
                            onChange={(event) => updateAddress('billing', field, event.target.value)}
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                            placeholder={label}
                          />
                        </div>
                      ))}
                    </div>
                  </div>
                </details>

                <details
                  open={shippingOpen}
                  onToggle={(event) => {
                    const open = (event.currentTarget as HTMLDetailsElement).open;
                    setShippingOpen(open);
                    // Accordion: only one section is expanded at a time.
                    if (open) setBillingOpen(false);
                  }}
                  className="group rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"
                >
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-2">
                      <Icon icon="solar:delivery-bold-duotone" className="h-4 w-4 shrink-0 text-slate-400" />
                      <span className="min-w-0">
                        <span className="block text-sm font-semibold text-slate-900">Shipping details</span>
                        <span className="mt-0.5 block truncate text-xs text-slate-500">
                          {contactNameById(shippingContactId) || 'No contact'}
                          {' · '}
                          {addressSummary(shippingAddress) || 'No address'}
                        </span>
                      </span>
                    </span>
                    <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" />
                  </summary>
                  <div className="mt-3 space-y-3">
                    <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Contact</label>
                        <ContactSearchCombobox
                          value={shippingContactId}
                          onValueChange={(val, contact) => {
                            setShippingContactId(val);
                            if (contact) {
                              void applyContactAddress('shipping', contact as CustomerContact);
                            }
                          }}
                          placeholder="Select customer contact..."
                          customerId={customerId}
                          source="customer_contacts"
                        />
                      </div>
                      <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Label</label>
                        <input
                          type="text"
                          value={shippingAddress.label}
                          onChange={(event) => updateAddress('shipping', 'label', event.target.value)}
                          className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                          placeholder="e.g. Site B"
                        />
                      </div>
                    </div>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                      {([
                        ['street', 'Street'], ['street2', 'Street 2'],
                        ['city', 'City'], ['state', 'State'], ['zip', 'ZIP'], ['country', 'Country'],
                      ] as const).map(([field, label]) => (
                        <div key={field} className={field === 'street' || field === 'street2' ? 'md:col-span-2' : ''}>
                          <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
                          <input
                            type="text"
                            value={shippingAddress[field]}
                            onChange={(event) => updateAddress('shipping', field, event.target.value)}
                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
                            placeholder={label}
                          />
                        </div>
                      ))}
                    </div>
                  </div>
                </details>
              </div>

              {/* Terms live in the chip header; only internal notes belong here. */}
              <div className="mt-4 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <Icon icon="solar:notes-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                <div className="min-w-0 flex-1">
                  <label className="block text-xs font-medium text-slate-600">
                    Notes <span className="font-normal text-slate-400">(internal — not printed on the invoice)</span>
                  </label>
                  <textarea
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    rows={2}
                    className="mt-1 w-full resize-y rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800"
                    placeholder="Add a note for your team…"
                  />
                </div>
              </div>

                  </div>
                </SheetContent>
              </Sheet>
            </CardContent>
          </Card>

          {/* D4: case/patient fields render only for the Medical profile (or when
              the invoice already has case data, #316), and stay collapsed
              behind a one-line summary until they are needed. */}
          {showCases && (
          <Card className="mb-6">
            <CardHeader className="pb-3">
              <CardTitle>Cases</CardTitle>
            </CardHeader>
            <CardContent>
              <details className="group">
                <summary className="flex cursor-pointer list-none items-center gap-2 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm font-medium transition-colors hover:bg-accent">
                  <Icon icon="solar:folder-with-files-bold-duotone" className="h-4 w-4 text-primary" />
                  {caseCount > 0 ? `Cases (${caseCount})` : 'Add case'}
                  <span className="ml-auto truncate text-xs font-normal text-muted-foreground">
                    {caseCount > 0 ? 'View or edit case details' : 'Case number, patient, doctor…'}
                  </span>
                  <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <div className="mt-4">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Case Number</label>
                  <CaseSearchCombobox
                    value={caseNumber}
                    onValueChange={setCaseNumber}
                    onSelectCase={(medicalCase) => {
                      // Selecting an existing case populates the section;
                      // everything stays editable for a new case.
                      const row = applyMedicalCaseToPrimary(medicalCase);
                      setPrimaryMedicalCaseId(row.medical_case_id ?? null);
                      setCaseNumber(row.case_number);
                      setDateOfCase(row.date_of_case);
                      setCaseLocation(row.case_location);
                      setPatientName(row.patient_name);
                      if (row.doctor_contact_id) setDoctorContactId(row.doctor_contact_id);
                      if (row.requestor_contact_id) setRequestorContactId(row.requestor_contact_id);
                      if (row.buyer_contact_id) setBuyerContactId(row.buyer_contact_id);
                    }}
                    customerId={customerId}
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

              {/* Additional cases on the same invoice */}
              {extraCases.map((row, index) => (
                <div key={index} className="mt-4 space-y-4 rounded-lg border border-dashed p-4">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">Case {index + 2}</span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 rounded-full"
                      onClick={() => setExtraCases((prev) => prev.filter((_, i) => i !== index))}
                      aria-label={`Remove case ${index + 2}`}
                    >
                      <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                    </Button>
                  </div>
                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div>
                      <label className="block text-sm font-medium mb-1">Case Number</label>
                      <CaseSearchCombobox
                        value={row.case_number}
                        onValueChange={(v) => setExtraCases((prev) => prev.map((r, i) => (i === index ? { ...r, case_number: v } : r)))}
                        onSelectCase={(medicalCase) => {
                          const filled = applyMedicalCaseToPrimary(medicalCase);
                          setExtraCases((prev) => prev.map((r, i) => (i === index
                            ? { ...r, ...filled, date_of_case: filled.date_of_case || r.date_of_case, case_location: filled.case_location || r.case_location, patient_name: filled.patient_name || r.patient_name, doctor_contact_id: filled.doctor_contact_id || r.doctor_contact_id, requestor_contact_id: filled.requestor_contact_id || r.requestor_contact_id, buyer_contact_id: filled.buyer_contact_id || r.buyer_contact_id }
                            : r)))
                        }}
                        customerId={customerId}
                      />
                    </div>
                    {([
                      ['date_of_case', 'Date of Case', 'date'],
                      ['pr_no', 'PR No', 'text'],
                      ['contract_no', 'Contract No', 'text'],
                      ['plant_code', 'Plant Code', 'text'],
                      ['company_code', 'Company Code', 'text'],
                      ['case_location', 'Location', 'text'],
                      ['patient_name', 'Patient Name', 'text'],
                      ['cost_center_id', 'Cost Center ID', 'text'],
                      ['delivery_date', 'Delivery Date', 'date'],
                      ['po_number', 'PO Number', 'text'],
                    ] as const).map(([field, label, type]) => (
                      <div key={field}>
                        <label className="block text-sm font-medium mb-1">{label}</label>
                        <input
                          type={type}
                          value={row[field]}
                          onChange={(event) => setExtraCases((prev) => prev.map((r, i) => (i === index ? { ...r, [field]: event.target.value } : r)))}
                          className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                        />
                      </div>
                    ))}
                    <div>
                      <label className="block text-sm font-medium mb-1">Doctor</label>
                      <ContactSearchCombobox value={row.doctor_contact_id} onValueChange={(v) => setExtraCases((prev) => prev.map((r, i) => (i === index ? { ...r, doctor_contact_id: v } : r)))} placeholder="Select doctor..." customerId={customerId} source="customer_contacts" />
                    </div>
                    <div>
                      <label className="block text-sm font-medium mb-1">Requestor</label>
                      <ContactSearchCombobox value={row.requestor_contact_id} onValueChange={(v) => setExtraCases((prev) => prev.map((r, i) => (i === index ? { ...r, requestor_contact_id: v } : r)))} placeholder="Select requestor..." customerId={customerId} source="customer_contacts" />
                    </div>
                    <div>
                      <label className="block text-sm font-medium mb-1">Buyer</label>
                      <ContactSearchCombobox value={row.buyer_contact_id} onValueChange={(v) => setExtraCases((prev) => prev.map((r, i) => (i === index ? { ...r, buyer_contact_id: v } : r)))} placeholder="Select buyer..." customerId={customerId} source="customer_contacts" />
                    </div>
                  </div>
                </div>
              ))}

              <div className="mt-4">
                <HeaderActionButton
                  type="button"
                  variant="outline"
                  icon="solar:add-circle-bold-duotone"
                  onClick={() => setExtraCases((prev) => [...prev, emptyInvoiceCase()])}
                >
                  Add Case
                </HeaderActionButton>
              </div>
                </div>
              </details>
            </CardContent>
          </Card>
          )}

          <Card className="mb-6">
            <CardHeader>
              <CardTitle className="flex items-center justify-between">
                <span>Line Items</span>
                <div className="flex flex-wrap gap-2">
                  {/* D6(a): repeat the previous invoice's lines in one click. */}
                  <button
                    type="button"
                    onClick={repeatLastInvoice}
                    disabled={!lastInvoice?.lines?.length}
                    title={lastInvoice ? `Load lines from ${lastInvoice.number}` : 'No previous invoice for this customer'}
                    className="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-accent disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    <Icon icon="solar:refresh-bold-duotone" className="h-4 w-4" />
                    Repeat last invoice
                  </button>
                  {/* D6(c): paste rows copied from a spreadsheet. */}
                  <button
                    type="button"
                    onClick={() => setPasteOpen(true)}
                    className="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-accent"
                  >
                    <Icon icon="solar:clipboard-text-bold-duotone" className="h-4 w-4" />
                    Paste rows
                  </button>
                  <details className="relative">
                    <summary className="list-none cursor-pointer rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-accent">
                      <Icon icon="solar:settings-bold-duotone" className="inline h-4 w-4 mr-1" />
                      Show/Hide Fields
                    </summary>
                    <div className="absolute right-0 z-20 mt-2 w-52 rounded-md border bg-background p-3 shadow-lg">
                      {[
                        ['sku', 'SKU'], ['description', 'Description'], ['model_number', 'Model #'],
                        ['product_notes', 'Product Notes'], ['lot_number', 'Lot Number'],
                        ['expiry_date', 'Expiry Date'], ['tax_rate', 'Tax %'], ['stock', 'Available'],
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
              {/* D6(b): this customer's frequently bought items as one-click chips. */}
              {frequentProducts.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-1.5">
                  <span className="text-[11px] uppercase tracking-wide text-muted-foreground">Frequent</span>
                  {frequentProducts.map((product) => (
                    <button
                      key={product.id}
                      type="button"
                      onClick={() => addFrequentProduct(product)}
                      title={`Bought ${product.times ?? ''} time${(product.times ?? 0) === 1 ? '' : 's'}${product.sku ? ` · SKU ${product.sku}` : ''}`}
                      className="inline-flex h-7 items-center gap-1 rounded-full border border-border bg-background px-2.5 text-xs font-medium transition-colors hover:border-primary/40 hover:bg-primary/5"
                    >
                      <Icon icon="solar:plus-circle-bold" className="h-3.5 w-3.5 text-primary" />
                      <span className="max-w-[14rem] truncate">{product.name}</span>
                    </button>
                  ))}
                </div>
              )}
              <FieldSlot name="lines" highlight={false}>
              <div
                className="overflow-x-auto"
                onChangeCapture={() => { linesTouchedRef.current = true }}
                onInputCapture={() => { linesTouchedRef.current = true }}
                onClickCapture={() => { linesTouchedRef.current = true }}
                onPaste={handleGridPaste}
              >
                <LineItemsTable
                  variant="raw"
                  tableClassName="doc-lines-table w-full min-w-[1700px] text-sm"
                  headRowClassName="border-b bg-muted/30"
                  columns={productLineColumns({
                    sku: visibleLineColumns.sku,
                    description: visibleLineColumns.description,
                    modelNumber: visibleLineColumns.model_number,
                    productNotes: visibleLineColumns.product_notes,
                    lotNumber: visibleLineColumns.lot_number,
                    expiryDate: visibleLineColumns.expiry_date,
                    taxRate: visibleLineColumns.tax_rate,
                    stock: visibleLineColumns.stock,
                  })}
                >
                    {lines.map((line, index) => (
                      <tr key={index} className="doc-line-row border-b transition-colors hover:bg-muted/20" draggable={(line.line_type ?? 'product') === 'product'} onDragStart={() => setDraggedLineIndex(index)} onDragOver={(event) => event.preventDefault()} onDrop={() => { if (draggedLineIndex !== null && draggedLineIndex !== index) { fieldErrors.clearLines(); setLines(reorderDocumentLines(lines, draggedLineIndex, index)); } setDraggedLineIndex(null); }}>
                        {line.line_type === 'note' || line.line_type === 'section' ? <DocumentTextLineRow type={line.line_type} text={line.description} html={line.description_html || plainToRichHtml(line.description)} colSpan={13 + (visibleLineColumns.stock ? 1 : 0)} total={line.line_type === 'section' ? money(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined} onChange={(text) => updateLine(index, 'description', text)} onHtmlChange={(html) => setLines((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, description_html: html, description: htmlToPlainText(html) } : item))} onDelete={() => removeLine(index)} onDragStart={() => setDraggedLineIndex(index)} onDrop={() => { if (draggedLineIndex !== null) { fieldErrors.clearLines(); setLines(reorderDocumentLines(lines, draggedLineIndex, index)); } setDraggedLineIndex(null); }} /> : <>
                        <td data-label="#" className={cn(LINE_CELL, 'w-10')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                            {lines.slice(0, index + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                          </div>
                        </td>
                        <td data-label="Product" className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                          <FieldSlot name={lineFieldKey(index, 'product_id')}>
                            <ProductSearchCombobox
                              value={line.product_id}
                              onValueChange={(productId, product) => {
                                fieldErrors.clear(lineFieldKey(index, 'product_id'));
                                const updated = [...lines];
                                updated[index] = { ...updated[index], product_id: productId };
                                updated[index].lot_number = '';
                                updated[index].serial_number = '';
                                updated[index].product_batch_id = null;
                                updated[index].product_serial_id = null;
                                updated[index].expiry_date = '';
                                if (product) {
                                  updated[index].description = getProductLineDescription(product);
                                  // #1004: a new product pick replaces any old rich formatting.
                                  updated[index].description_html = '';
                                  updated[index].unit_price = parseFloat((product as any).price || (product as any).selling_price || '0');
                                  updated[index].model_number = (product as any).manufacturer_part_number || '';
                                  updated[index].sku = (product as any).sku || '';
                                  updated[index].tracking_method = (product as any).tracking_method || 'none';
                                  updated[index].tax_rate = resolveLineTaxRate(product, defaultTaxRate);
                                  // #287: a row product-pick must take the SAME pricing
                                  // path as updateLine() — mark the line 'auto' and run
                                  // the price-list resolver so the customer list price +
                                  // floor/minimum check apply (not the raw product price).
                                  updated[index].price_mode = 'auto';
                                  updated[index].pricing_source = null;
                                }
                                setLines(updated);
                                if (product) void resolvePricingForLines(customerId, updated);
                                // D6(e): picking on the last row advances to a new one.
                                if (product) appendLineIfLast(index);
                              }}
                              wrapText
                            />
                          </FieldSlot>
                        </td>
                        {visibleLineColumns.sku && <td data-label="SKU" className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                        </td>}
                        {visibleLineColumns.description && <td data-label="Description" className={cn(LINE_CELL, 'min-w-[16rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'description')}>
                            {/* #1004: WYSIWYG description (bold/italic/underline/colour); plain text stays in sync for search/exports. */}
                            <RichTextEditor
                              compact
                              value={line.description_html || plainToRichHtml(line.description)}
                              onChange={(html) => setLines((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, description_html: html, description: htmlToPlainText(html) } : item))}
                              placeholder="Description"
                              className="min-w-0 bg-transparent"
                            />
                          </FieldSlot>
                        </td>}
                        {visibleLineColumns.model_number && <td data-label="Model #" className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                        </td>}
                        {visibleLineColumns.product_notes && <td data-label="Product notes" className={cn(LINE_CELL, 'min-w-[10rem]')}>
                          <textarea
                            value={line.product_notes}
                            onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                            rows={2}
                            className={LINE_TEXTAREA}
                            placeholder="Product notes"
                          />
                        </td>}
                        {visibleLineColumns.lot_number && <td data-label="Lot / Serial" className={cn(LINE_CELL, 'min-w-[7.5rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'lot_number')}>
                            <ProductTrackingSelector
                              productId={line.product_id}
                              warehouseId={warehouseId}
                              trackingMethod={line.tracking_method}
                              batchId={line.product_batch_id}
                              serialId={line.product_serial_id}
                              lotNumber={line.lot_number}
                              serialNumber={line.serial_number}
                              onChange={(selection) => {
                                fieldErrors.clear(lineFieldKey(index, 'lot_number'));
                                setLines((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...selection } : item));
                              }}
                            />
                          </FieldSlot>
                          {line.tracking_method === 'none' || line.tracking_method === 'quantity' ? <span className="text-xs text-muted-foreground">Not tracked</span> : null}
                        </td>}
                        {visibleLineColumns.expiry_date && <td data-label="Expiry" className={cn(LINE_CELL, 'min-w-[9.5rem]')}>
                          <div className={LINE_VALUE}>{line.expiry_date || <span className="opacity-60">Expiry</span>}</div>
                        </td>}
                        <td data-label="Qty" className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'quantity')}>
                            <div className={LINE_CELL_BOX}>
                              <input
                                type="number"
                                min="0"
                                step={line.uom_id ? '0.0001' : '1'}
                                inputMode="decimal"
                                value={line.quantity}
                                onChange={(event) => {
                                  const raw = event.target.value;
                                  if (raw === '') { updateLine(index, 'quantity', ''); return; }
                                  const parsed = Number(raw);
                                  if (!Number.isNaN(parsed)) updateLine(index, 'quantity', parsed);
                                }}
                                onBlur={() => {
                                  if (line.quantity !== '') {
                                    updateLine(index, 'quantity', normalizeQuantity(line.quantity) as number);
                                  }
                                }}
                                onKeyDown={(event) => {
                                  // D6(e): Enter on the last row starts a new line.
                                  if (event.key === 'Enter') {
                                    event.preventDefault();
                                    appendLineIfLast(index);
                                  }
                                }}
                                className={LINE_INPUT_NUMERIC}
                              />
                            </div>
                          </FieldSlot>
                        </td>
                        {visibleLineColumns.stock && <td data-label="Available" className={cn(LINE_CELL, 'w-[8.5rem] min-w-[7.5rem]')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                            <StockAvailabilityChip
                              productId={line.product_id}
                              warehouseId={warehouseId}
                              requested={Number(line.quantity) || 0}
                            />
                          </div>
                        </td>}
                        <td data-label="Unit price" className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'unit_price')}>
                            <div className={LINE_CELL_BOX}>
                              <div className="flex w-full flex-col gap-0.5">
                                <input
                                  type="number"
                                  min="0"
                                  step="0.01"
                                  placeholder="0.00"
                                  value={line.unit_price === '' ? '' : line.unit_price}
                                  onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                                  onKeyDown={(event) => {
                                    // D6(e): Enter on the last row starts a new line.
                                    if (event.key === 'Enter') {
                                      event.preventDefault();
                                      appendLineIfLast(index);
                                    }
                                  }}
                                  className={LINE_INPUT_NUMERIC}
                                />
                                {/* FR-6.2 + D5: pricing source chip — hover explains exactly
                                    how the price was reached (list/rule, tier, FX, manual reason). */}
                                {line.price_mode === 'manual' && (
                                  <Tooltip>
                                    <TooltipTrigger asChild>
                                      <span className="self-start cursor-help rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                        Manual
                                      </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" className="max-w-64 text-xs">
                                      <ul className="space-y-1">
                                        {priceExplanation(line).map((note, noteIndex) => <li key={noteIndex}>{note}</li>)}
                                      </ul>
                                    </TooltipContent>
                                  </Tooltip>
                                )}
                                {line.price_mode !== 'manual' && line.pricing_source && (
                                  <Tooltip>
                                    <TooltipTrigger asChild>
                                      <span className="self-start max-w-full cursor-help truncate rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300">
                                        {line.pricing_source}
                                      </span>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" className="max-w-64 text-xs">
                                      <ul className="space-y-1">
                                        {priceExplanation(line).map((note, noteIndex) => <li key={noteIndex}>{note}</li>)}
                                      </ul>
                                    </TooltipContent>
                                  </Tooltip>
                                )}
                                {line.list_price != null && Number(line.list_price) > Number(line.unit_price || 0) && (
                                  <span className="self-start text-[10px] text-muted-foreground line-through">
                                    {money(Number(line.list_price))}
                                  </span>
                                )}
                                {currencyCode && pricingMeta?.base_currency_code && currencyCode !== pricingMeta.base_currency_code && line.unit_price !== '' && (
                                  <span className="self-start text-[10px] text-muted-foreground">
                                    ≈ {formatCurrency(Number(line.unit_price) * Number(pricingMeta.exchange_rate || 1), pricingMeta.base_currency_code)}
                                  </span>
                                )}
                              </div>
                            </div>
                          </FieldSlot>
                        </td>
                        {visibleLineColumns.tax_rate && <td data-label="Tax %" className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'tax_rate')}>
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
                          </FieldSlot>
                        </td>}
                        <td data-label="Total" className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                          <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                            {money(getLineTotal(line))}
                          </div>
                        </td>
                        <td data-label="" className={cn(LINE_CELL, 'w-[10.5rem]')}>
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
              </FieldSlot>

              <div className="mt-3 flex flex-wrap gap-2">
                <ProductCatalogDialog onAdd={addCatalogProducts} />
                <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold-duotone" onClick={addLine}>
                  Add Line
                </HeaderActionButton>
                <HeaderActionButton
                  type="button"
                  variant="outline"
                  icon="solar:refresh-bold-duotone"
                  title="Re-run pricing for all auto lines (customer, currency or list changes do this automatically)"
                  onClick={() => void resolvePricingForLines(customerId, lines)}
                >
                  Recalculate
                </HeaderActionButton>
                <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('section')}>Add Section</HeaderActionButton>
                <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('note')}>Add Note</HeaderActionButton>
              </div>

              <DocumentTotalsCard
                subtotal={subtotal}
                tax={taxTotal}
                total={total}
                money={money}
                discountMode={discountMode}
                onDiscountModeChange={setDiscountMode}
                discountAmount={discountAmount}
                onDiscountAmountChange={setDiscountAmount}
                lineDiscounts={lineDiscounts}
                documentDiscountAmount={0}
                footer={
                  ((currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode) || pricingMeta?.price_list_name) ? (
                    <>
                      {currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode && (
                        <div className="flex items-center justify-between">
                          <span>Base equivalent ({pricingMeta.base_currency_code})</span>
                          <span className="tabular-nums">{formatCurrency(total * Number(pricingMeta.exchange_rate || 1), pricingMeta.base_currency_code)}</span>
                        </div>
                      )}
                      {pricingMeta?.price_list_name && (
                        <div className="flex items-center justify-between">
                          <span>Priced by</span>
                          <span>{pricingMeta.price_list_name} ({pricingMeta.price_list_currency ?? currencyCode})</span>
                        </div>
                      )}
                    </>
                  ) : null
                }
              />
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Button type="button" variant="outline" onClick={() => draftGuard.guard(() => navigate(backPath))}>
              Cancel
            </Button>
            <Button type="submit" onClick={() => setSendAfterSave(false)} disabled={saveMutation.isPending || isInvoiceLoading}>
              {saveMutation.isPending && !sendAfterSave ? (
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
            {/* D8: Save & Send — one action: save, post if needed, email PDF +
                acceptance link + pay-now QR. */}
            {!isQuoteMode && (
              <Button
                type="submit"
                variant="outline"
                onClick={() => setSendAfterSave(true)}
                disabled={saveMutation.isPending || isInvoiceLoading}
                className="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800"
              >
                {saveMutation.isPending && sendAfterSave ? (
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-emerald-600 mr-2" />
                ) : (
                  <Icon icon="solar:plain-2-bold-duotone" className="h-4 w-4 mr-2" />
                )}
                Save &amp; Send
              </Button>
            )}
          </div>
        </form>
        </FieldErrorsProvider>

        <DuplicateInvoiceDialog
          check={likelyDuplicate}
          pending={saveMutation.isPending}
          onCancel={() => setLikelyDuplicate(null)}
          onConfirm={() => {
            setLikelyDuplicate(null);
            saveMutation.mutate({ ...(lastSavePayloadRef.current ?? {}), confirm_duplicate: true });
          }}
        />

        <Dialog open={overrideDialogOpen} onOpenChange={setOverrideDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Price override reason required</DialogTitle>
              <DialogDescription>
                One or more manual prices are below the allowed floor (below cost or more than the configured
                discount). Provide a reason for the audit trail — it is stored with each overridden line.
              </DialogDescription>
            </DialogHeader>
            <select
              value={PRESET_OVERRIDE_REASONS.includes(overrideReason) ? overrideReason : ''}
              onChange={(event) => {
                if (event.target.value) setOverrideReason(event.target.value);
              }}
              className="h-9 w-full rounded-full border border-input bg-background px-3 text-xs"
            >
              <option value="">Common reasons…</option>
              {PRESET_OVERRIDE_REASONS.map((reason) => (
                <option key={reason} value={reason}>{reason}</option>
              ))}
            </select>
            <Textarea
              rows={3}
              placeholder="e.g. tender award price approved by management"
              value={overrideReason}
              onChange={(event) => setOverrideReason(event.target.value)}
            />
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setOverrideDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="button" onClick={applyOverrideReasonAndRetry}>
                Save with reason
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      <Dialog open={customerDialogOpen} onOpenChange={setCustomerDialogOpen}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{customerDialogMode === 'create' ? 'Add Customer' : 'Edit Customer'}</DialogTitle>
          </DialogHeader>

          <form onSubmit={handleCustomerFormSubmit} className="space-y-4">
            <CustomerForm
              value={customerForm}
              onChange={setCustomerForm}
              idPrefix="invoice-customer"
              disabled={customerMutationPending}
            />

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

      {/* D6(c): paste rows copied from Excel — SKU ⇥ Qty [⇥ Price]. */}
      <Dialog open={pasteOpen} onOpenChange={setPasteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Paste rows from Excel</DialogTitle>
            <DialogDescription>
              Copy rows from your spreadsheet and paste them below. Columns: SKU or barcode, quantity
              (optional price). Unknown codes are added as free-text lines.
            </DialogDescription>
          </DialogHeader>
          <textarea
            value={pasteText}
            onChange={(event) => setPasteText(event.target.value)}
            rows={8}
            placeholder={'SKU-001\t2\t19.90\nSKU-002\t1'}
            className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-ring"
            autoFocus
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setPasteOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => void importPastedRows(pasteText)} disabled={pasteBusy || !pasteText.trim()}>
              {pasteBusy ? 'Adding...' : 'Add rows'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* D10: command bar — Ctrl/⌘+K. */}
      <Dialog
        open={commandOpen}
        onOpenChange={(open) => {
          setCommandOpen(open);
          if (!open) {
            setCommandText('');
            setCommandResults([]);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Command bar</DialogTitle>
            <DialogDescription>
              Type a command and press Enter — or type any product name/SKU to add it.
            </DialogDescription>
          </DialogHeader>
          <input
            autoFocus
            value={commandText}
            onChange={(event) => setCommandText(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault();
                void executeCommand(commandText);
              }
            }}
            placeholder="add 10 widget-a @90 · terms 60 · discount 5% · currency MYR · note …"
            className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          />
          {commandBusy && <p className="text-xs text-muted-foreground">Working…</p>}
          {!commandBusy && commandResults.length > 0 && (
            <div className="max-h-56 space-y-1 overflow-y-auto">
              {commandResults.map((product) => (
                <button
                  key={product.id}
                  type="button"
                  onClick={() => {
                    addProductToLines(product, 1, null);
                    setCommandOpen(false);
                    setCommandText('');
                    setCommandResults([]);
                  }}
                  className="flex w-full items-center justify-between gap-3 rounded-md border px-2.5 py-1.5 text-left text-xs hover:bg-accent"
                >
                  <span className="truncate font-medium">{product.name}</span>
                  <span className="shrink-0 text-muted-foreground">{product.sku}</span>
                </button>
              ))}
            </div>
          )}
          <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
            <div className="mb-1 font-medium text-foreground">Commands</div>
            <ul className="space-y-0.5">
              <li><span className="font-mono">add [qty] &lt;product&gt; [@ price]</span> — add a line</li>
              <li><span className="font-mono">terms 60</span> — payment terms &amp; due date</li>
              <li><span className="font-mono">discount 5%</span> or <span className="font-mono">discount 50</span></li>
              <li><span className="font-mono">currency MYR</span> — document currency</li>
              <li><span className="font-mono">note &lt;text&gt;</span> — replace internal notes</li>
            </ul>
          </div>
        </DialogContent>
      </Dialog>

      {/* D6(a): replacing existing lines needs an explicit confirmation. */}
      <Dialog open={repeatConfirmOpen} onOpenChange={setRepeatConfirmOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Replace current lines?</DialogTitle>
            <DialogDescription>
              The {lines.filter((line) => line.product_id || line.description.trim()).length} line(s) on this
              document will be replaced with the lines from {lastInvoice?.number}.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRepeatConfirmOpen(false)}>
              Cancel
            </Button>
            <Button onClick={performRepeatLastInvoice}>Replace lines</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* #298 (F6): switching warehouses lists exactly which picks are lost. */}
      <Dialog
        open={!!warehouseClearConfirm}
        onOpenChange={(open) => {
          if (!open) setWarehouseClearConfirm(null);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Change warehouse?</DialogTitle>
            <DialogDescription>
              {warehouseClearConfirm?.clearIndexes.length ?? 0} lot selection
              {(warehouseClearConfirm?.clearIndexes.length ?? 0) === 1 ? '' : 's'} will be cleared —
              they are not available in the new warehouse. Selections available in the new
              warehouse are kept.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setWarehouseClearConfirm(null)}>
              Cancel
            </Button>
            <Button onClick={applyWarehouseChange}>Change warehouse</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <SaveConflictDialog
        open={conflict !== null}
        conflict={conflict}
        canOverwrite={canOverwriteConflict}
        onOpenChange={(open) => { if (!open) setConflict(null); }}
        onReloadTheirs={handleReloadTheirs}
        onOverwrite={handleOverwrite}
      />

      <DocumentLeaveDialog guard={draftGuard} />
    </TooltipProvider>
  );
};

export default CreateInvoicePage;
