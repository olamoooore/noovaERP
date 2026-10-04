import React, { useEffect, useRef, useState } from 'react';
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard';
import { Icon } from '@iconify/react';
import { addCalendarDays, isValidProductQuantity, todayDateOnly } from '@/lib/dateOnly'
import { DEFAULT_QUOTE_VALIDITY_DAYS, followValidUntil } from '@/lib/quotationDates'
import { useToast } from '@/hooks/use-toast';
import { formatCurrency, getApiErrorMessage } from '@/lib/utils';
import api from '@/lib/api'
import { resolveLineTaxRate } from '@/lib/lineTax';
import { computeDocumentTotals } from '@/lib/documentTotals';
import pricingApi from '@/lib/pricingApi'
import { useAuthStore } from '@/store/authStore';
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import { SalesLineColumnMenu, SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility';
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog';
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow';
import { LineItemsTable } from '@/components/sales/LineItemsTable';
import { PageHeader } from '@/components/layout/PageHeader';
import { TagPicker } from '@/components/tags/TagPicker';
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  ProductLineActions,
  productLineColumns,
} from '@/components/sales/ProductLineTable';
import { cn } from '@/lib/utils';
import { emptyQuotationCaseInformation } from '@/components/sales/QuotationCaseInformation';
import { InternalReferenceTags } from '@/components/sales/InternalReferenceTags';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { CaseSearchCombobox } from '@/components/sales/CaseSearchCombobox';
import { ContactSearchCombobox } from '@/components/ui/ContactSearchCombobox';
import { HeaderChip } from '@/components/ui/HeaderChip';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';

const QUOTATION_CREATE_COLUMNS = ['#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax %', 'Line Total', 'Actions']
  .map((label, index) => ({ label, index: index + 1 }));

interface QuotationLine {
  line_type?: 'product' | 'note' | 'section';
  product_id: string;
  sku: string;
  model_number: string;
  description: string;
  product_notes: string;
  quantity: number;
  unit_price: number | '';
  tax_rate: number | '';
  discount_amount: number | '';
  // MRD pricelist-multicurrency FR-6.2/6.3: pricing intent per line.
  price_mode?: 'auto' | 'manual' | 'carried';
  override_reason?: string | null;
}

type AddressFormState = Record<'label' | 'street' | 'street2' | 'city' | 'state' | 'zip' | 'country', string>;

const emptyAddressForm: AddressFormState = {
  label: '', street: '', street2: '', city: '', state: '', zip: '', country: '',
};

const toAddressForm = (address?: any): AddressFormState => ({
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

const emptyLine = (): QuotationLine => ({
  line_type: 'product',
  product_id: '',
  sku: '',
  model_number: '',
  description: '',
  product_notes: '',
  quantity: 1,
  unit_price: '',
  tax_rate: '',
  discount_amount: '',
});

// A fully-empty, untouched product row — ignored consistently by validation and
// the payload filter, and replaced (not appended to) by catalogue adds (#290).
const isBlankPlaceholderLine = (line: QuotationLine): boolean =>
  (line.line_type ?? 'product') === 'product'
  && !line.product_id
  && !line.description.trim()
  && !(line.sku ?? '').trim();

export const QuotationCreatePage: React.FC = () => {
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>([6])
  const [tags, setTags] = useState<string[]>([])
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)
  const navigate = useNavigate();
  const { toast } = useToast()
  const canViewPriceLists = useAuthStore((state) => state.hasPermission('pricelists.view'));

  const [customerId, setCustomerId] = useState('');
  // Explicit price-list selection (FR-2.3 step 1); '' = auto.
  const [priceListId, setPriceListId] = useState('');
  const [currencyCode, setCurrencyCode] = useState('');
  const [pricingMeta, setPricingMeta] = useState<{
    base_currency_code?: string;
    exchange_rate?: number;
    rate_date?: string | null;
    rate_source?: string | null;
    rate_stale?: boolean;
    price_list_name?: string | null;
    price_list_currency?: string | null;
  } | null>(null);
  const [pricingError, setPricingError] = useState<string | null>(null);
  // #297 (Q11): date-only business values use the local calendar day, not the
  // UTC day — before 08:00 in MY/SG the old defaults were yesterday.
  const [quotationDate, setQuotationDate] = useState(todayDateOnly());
  const [validUntil, setValidUntil] = useState(
    () => new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  );
  const [validUntilManuallyEdited, setValidUntilManuallyEdited] = useState(false);
  // Issue #408: commitment (promised) date + shipping policy. The expected
  // date is computed by the server from product/supplier lead times.
  const [commitmentDate, setCommitmentDate] = useState('');
  const [shippingPolicy, setShippingPolicy] = useState<'asap' | 'when_all_ready'>('asap');
  const [notes, setNotes] = useState('');
  const [discountAmount, setDiscountAmount] = useState<number | ''>('');
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed');
  const [lines, setLines] = useState<QuotationLine[]>([emptyLine()]);
  const [customerReference, setCustomerReference] = useState('');
  const [internalReference, setInternalReference] = useState('');
  const [creditTerms, setCreditTerms] = useState('');
  const [attentionTo, setAttentionTo] = useState('');
  const [contactEmail, setContactEmail] = useState('');
  const [contactPhone, setContactPhone] = useState('');
  // Structured addresses (invoice parity) — snapshots derive from these.
  const [billingAddress, setBillingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [shippingAddress, setShippingAddress] = useState<AddressFormState>(emptyAddressForm);
  const [billingContactId, setBillingContactId] = useState('');
  const [shippingContactId, setShippingContactId] = useState('');
  const [salesperson, setSalesperson] = useState('');
  const [deliveryLeadTime, setDeliveryLeadTime] = useState('');
  const [shippingMethod, setShippingMethod] = useState('');
  const [shippingTerms, setShippingTerms] = useState('');
  const [validity, setValidity] = useState('');
  const [caseInformation, setCaseInformation] = useState(emptyQuotationCaseInformation);
  // Invoice-page parity: secondary fields live in one drawer, not inline.
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [billingOpen, setBillingOpen] = useState(false);
  const [shippingOpen, setShippingOpen] = useState(false);
  const [customerMatch, setCustomerMatch] = useState<{ contacts: string[]; tags: string[]; addresses: string[] } | null>(null);
  const autofilledCustomerRef = useRef<string | null>(null);
  // #296: id of the newest async price resolution. A response may only apply
  // if no newer request has superseded it.
  const pricingRequestSeqRef = useRef(0);

  const { data: products } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const response = await api.get('/products?per_page=1000');
      return response.data.data || response.data || [];
    },
  });

  // One pick fills everything (invoice parity): primary contact, references
  // and the saved billing/shipping addresses pre-fill from the customer.
  const { data: customerDetail } = useQuery({
    queryKey: ['quotation-customer-detail', customerId],
    queryFn: async () => {
      const response = await api.get(`/customers/${customerId}`);
      return response.data?.data ?? response.data;
    },
    enabled: Boolean(customerId),
  });

  useEffect(() => {
    if (!customerDetail || autofilledCustomerRef.current === customerId) return;
    autofilledCustomerRef.current = customerId;

    const contacts = customerDetail.customer_contacts ?? customerDetail.customerContacts ?? [];
    const primary = contacts.find((contact: any) => contact.is_primary) ?? contacts[0];

    setAttentionTo(primary?.name ?? customerDetail.name ?? '');
    setContactEmail(primary?.email ?? customerDetail.email ?? '');
    setContactPhone(primary?.phone ?? primary?.mobile ?? customerDetail.phone ?? customerDetail.mobile ?? '');
    setCustomerReference((prev) => prev || customerDetail.code || '');

    const days = Number(customerDetail.payment_terms);
    if (Number.isFinite(days) && days >= 0) {
      setCreditTerms((prev) => prev || (days > 0 ? `Net ${days} days` : 'Due on receipt'));
    }

    const addresses = customerDetail.addresses ?? [];
    const pick = (type: string) => addresses.find((address: any) => address.type === type && address.is_default)
      ?? addresses.find((address: any) => address.type === type);
    setBillingAddress(toAddressForm(pick('billing')));
    setShippingAddress(toAddressForm(pick('shipping')));
    setBillingContactId('');
    setShippingContactId('');
  }, [customerDetail, customerId]);

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.post('/quotations', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({ title: 'Quotation created successfully' });
      const id = data?.data?.id;
      if (id) {
        navigate(`/quotations/${id}`);
      } else {
        navigate('/quotations');
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create quotation',
        description: getApiErrorMessage(error),
      });
    },
  });

  /**
   * FR-4.6/FR-6.2: resolve auto prices from the applicable price list via
   * the shared engine; manual lines are never overwritten. The server
   * re-resolves authoritatively on save (price_mode='auto').
   */
  const { data: activePriceLists } = useQuery({
    queryKey: ['price-lists', 'active'],
    queryFn: () => pricingApi.listPriceLists({ active_only: true, per_page: 200 }),
    enabled: canViewPriceLists,
  });
  const priceListRows: any[] = (activePriceLists as any)?.data ?? activePriceLists ?? [];

  const { data: sellableCurrencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  });

  const { data: salesSettings } = useQuery({
    queryKey: ['sales-settings'],
    queryFn: async () => (await api.get('/settings/sales')).data,
  });
  const fieldProfile = ((salesSettings as any)?.quotation?.field_profile
    ?? (salesSettings as any)?.invoice?.field_profile
    ?? 'medical') as 'medical' | 'trading' | 'services';

  const contactNameById = (id: string) => {
    const contacts = customerDetail?.customer_contacts ?? customerDetail?.customerContacts ?? [];
    return contacts.find((contact: any) => String(contact.id) === id)?.name ?? '';
  };
  const addressSummary = (address: AddressFormState) =>
    [address.street, address.city, address.state].filter(Boolean).join(', ');
  const updateAddress = (type: 'billing' | 'shipping', field: keyof AddressFormState, value: string) => {
    const setter = type === 'billing' ? setBillingAddress : setShippingAddress;
    setter((current) => ({ ...current, [field]: value }));
  };
  const caseCount = Object.values(caseInformation).some((value) => value) ? 1 : 0;

  const hasPricingFeedback = Boolean(
    (currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode)
      || pricingMeta?.price_list_name
      || pricingError,
  );
  const money = (value: number) => formatCurrency(value, currencyCode || undefined);

  const detailsSummary = [
    customerReference ? `PO ${customerReference}` : null,
    attentionTo || null,
    caseCount > 0 ? `cases ${caseCount}` : null,
    notes ? 'notes' : null,
  ].filter(Boolean).join(' · ');

  const resolvePricingForLines = async (nextLines: QuotationLine[], listOverride?: string, currencyOverride?: string, dateOverride?: string) => {
    const productLines = nextLines
      .map((line, index) => ({ line, index }))
      .filter(({ line }) => (line.line_type ?? 'product') === 'product' && !!line.product_id);
    if (productLines.length === 0) return;

    // #296: stamp the request before awaiting; a stale response must never
    // apply after a newer resolution has been started.
    const requestId = ++pricingRequestSeqRef.current;

    try {
      const result = await pricingApi.resolveBatch(
        {
          customer_id: customerId ? parseInt(customerId, 10) : null,
          date: (dateOverride ?? quotationDate) || undefined,
          // The editor's currency wins; empty lets the server resolve from
          // the customer's list (FR-5.2).
          document_currency: (currencyOverride ?? currencyCode) || undefined,
          price_list_id: ((listOverride !== undefined ? listOverride : priceListId) || '') !== ''
            ? Number(listOverride !== undefined ? listOverride : priceListId)
            : undefined,
        },
        productLines.map(({ line }) => ({
          product_id: parseInt(line.product_id, 10),
          quantity: Number(line.quantity) || 1,
        })),
      );

      // A newer resolve was started while this one was in flight — drop it.
      if (requestId !== pricingRequestSeqRef.current) return;

      setCurrencyCode(result.currency_code);
      setPricingMeta({
        base_currency_code: result.base_currency_code,
        exchange_rate: result.exchange_rate,
        rate_date: result.rate_date,
        rate_source: (result as any).rate_source ?? null,
        rate_stale: Boolean((result as any).rate_stale),
        price_list_name: result.price_list_name,
        price_list_currency: result.price_list_currency,
      });

      // Surface resolution failures instead of silently keeping stale prices.
      const lineError = (result.lines ?? []).map((quote: any) => quote?.error).find(Boolean) ?? null;
      setPricingError(lineError);

      // Apply against the CURRENT lines, not the request snapshot, and only to
      // the line that still is the product/quantity the quote was requested
      // for — edits made while the request was in flight survive.
      setLines((current) => {
        const updated = [...current];
        result.lines.forEach((quote: any, quoteIndex: number) => {
          const requested = productLines[quoteIndex];
          const target = requested?.index;
          if (target === undefined || quote?.error) return;
          const existing = updated[target];
          if (!existing) return;
          if (String(existing.product_id) !== String(requested.line.product_id)) return;
          const requestedQty = Number(requested.line.quantity) || 1;
          const currentQty = Number(existing.quantity) || 1;
          if (currentQty !== requestedQty) return;
          if (existing.price_mode === 'manual') return;
          updated[target] = { ...existing, unit_price: quote.unit_price, price_mode: 'auto' };
        });
        return updated;
      });
    } catch {
      // Engine errors surface on save with actionable messages.
    }
  };

  // Customer changes re-resolve the default prices/lists (FR-2.3).
  useEffect(() => {
    if (!customerId) return;
    void resolvePricingForLines(lines);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId]);

  const updateLine = (index: number, field: keyof QuotationLine, value: string | number) => {
    const updated = [...lines];
    updated[index] = { ...updated[index], [field]: value };

  if (field === 'product_id' && value && products) {
    const product = products.find((item: any) => String(item.id) === String(value));
    if (product) {
      updated[index].description = product.name || product.description || '';
      updated[index].unit_price = parseFloat(product.price || product.selling_price || '0');
      updated[index].model_number = product.manufacturer_part_number || '';
      updated[index].sku = product.sku || '';
      updated[index].price_mode = 'auto';
      updated[index].tax_rate = resolveLineTaxRate(product);
      setLines(updated);
      void resolvePricingForLines(updated);
      return;
    }
  }

    if (field === 'unit_price') {
      updated[index].price_mode = 'manual';
    }

    setLines(updated);
    if (field === 'quantity' && updated[index].price_mode === 'auto') {
      void resolvePricingForLines(updated);
    }
  };

  const addLine = () => setLines([...lines, emptyLine()]);
  const addTextLine = (line_type: 'note' | 'section') => setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }]);
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => {
    // #290: replace the lone untouched starter row instead of appending after it.
    const next: QuotationLine[] = lines.length === 1 && isBlankPlaceholderLine(lines[0])
      ? []
      : [...lines];
    selected.forEach(({ product, quantity }) => {
      const existingIndex = next.findIndex(
        (line) => line.line_type === 'product' && line.product_id === String(product.id),
      );
      if (existingIndex >= 0) {
        // #288(d): never mutate state in place — build a new object.
        next[existingIndex] = { ...next[existingIndex], quantity: next[existingIndex].quantity + quantity };
      } else {
        next.push({
          ...emptyLine(),
          product_id: String(product.id),
          sku: product.sku || '',
          // #288(c): shared description helper, not the bare name.
          description: getProductLineDescription(product),
          unit_price: Number(product.price || 0),
          quantity,
          // #288(a): same pricing path as a row pick.
          price_mode: 'auto',
          // #288(b): tax from the same resolver the row pick uses, not hard 0.
          tax_rate: resolveLineTaxRate(product),
        });
      }
    });
    setLines(next);
    // #288(a): catalogue additions must price through the engine too.
    void resolvePricingForLines(next);
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
    if (targetIndex < 0 || targetIndex >= lines.length) return;
    const updated = [...lines];
    [updated[index], updated[targetIndex]] = [updated[targetIndex], updated[index]];
    setLines(updated);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, currentIndex) => currentIndex !== index));
    }
  };

  /** Clear a line in place so a lone row can be emptied without deleting it. */
  const resetLine = (index: number) => {
    setLines((current) => current.map((line, i) => (i === index ? emptyLine() : line)));
  };

  const getLineTotal = (line: QuotationLine) => {
    const unitPrice = line.unit_price === '' ? 0 : line.unit_price;
    const taxRate = line.tax_rate === '' ? 0 : line.tax_rate;
    const discount = line.discount_amount === '' ? 0 : line.discount_amount;
    const base = line.quantity * unitPrice;
    const discounted = base - discount;
    const tax = discounted * (taxRate / 100);
    return discounted + tax;
  };

  // Mirrors Invoice::calculateTotals() via the shared helper so the quotation
  // screen agrees with the invoice screen and the server (issue #289).
  const documentTotals = computeDocumentTotals(lines, { mode: discountMode, amount: discountAmount });
  // The totals card shows the gross subtotal + line discounts separately.
  const subtotal = documentTotals.grossSubtotal;
  const lineDiscounts = documentTotals.lineDiscounts;
  const documentDiscountAmount = documentTotals.documentDiscountAmount;
  const taxAfterDocumentDiscount = documentTotals.taxTotal;
  const total = documentTotals.total;
  const discountValue = discountAmount === '' ? 0 : discountAmount;
  const [customQuoteNumber, setCustomQuoteNumber] = useState('');
  // #297 (Q11): changing the quote date moves Valid Until with it (unless the
  // operator edited Valid Until themselves) and re-runs auto pricing against
  // the new date.
  const handleQuotationDateChange = (value: string) => {
    setQuotationDate(value);

    setValidUntil(followValidUntil(value, validUntil, validUntilManuallyEdited));

    // #299 (F7): the price list is date-effective — re-resolve auto lines for
    // the new date (state has not updated yet, so pass it explicitly).
    void resolvePricingForLines(lines, undefined, undefined, value);
  };

  const handleValidUntilChange = (value: string) => {
    setValidUntil(value);
    setValidUntilManuallyEdited(true);
  };

  const quoteNumberPreview = `QUO-${new Date(quotationDate).getFullYear()}-XXXX`;

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' });
      return;
    }

    // #290: ignore fully-empty untouched rows consistently (same as the payload
    // filter below) so a lingering starter row never blocks saving.
    const submissionLines = lines.filter((line) => !isBlankPlaceholderLine(line));

    if (submissionLines.length === 0 || submissionLines.every((line) => !line.description && !line.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' });
      return;
    }

    const missingProductIndex = submissionLines.findIndex((line) =>
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

    if (discountMode === 'percentage' && discountValue > 100) {
      toast({ variant: 'destructive', title: 'Percentage discount cannot exceed 100%' });
      return;
    }

    // #295 (Q7): product lines need a quantity greater than 0 with at most
    // 4 decimals — the API rejects 0/empty and over-precision; report it here
    // with a clear message instead of a generic failure.
    const invalidQuantityIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !isValidProductQuantity(line.quantity)
    );
    if (invalidQuantityIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Invalid quantity on line ${invalidQuantityIndex + 1}`,
        description: 'Enter a quantity greater than 0 with at most 4 decimals.',
      });
      return;
    }

    const payload = {
      customer_id: parseInt(customerId, 10),
      ...(customQuoteNumber.trim() ? { quotation_number: customQuoteNumber.trim() } : {}),
      quotation_date: quotationDate,
      valid_until: validUntil,
      // Issue #408: promised date + partial-delivery policy.
      commitment_date: commitmentDate || undefined,
      shipping_policy: shippingPolicy,
      customer_reference: customerReference || undefined,
      internal_reference: internalReference || undefined,
      credit_terms: creditTerms || undefined,
      attention_to: attentionTo || undefined,
      contact_email: contactEmail || undefined,
      contact_phone: contactPhone || undefined,
      billing_address_snapshot: addressFormToSnapshot(billingAddress) || undefined,
      shipping_address_snapshot: addressFormToSnapshot(shippingAddress) || undefined,
      ...(billingContactId.includes('-') ? { billing_contact_id: billingContactId } : {}),
      ...(shippingContactId.includes('-') ? { shipping_contact_id: shippingContactId } : {}),
      salesperson: salesperson || undefined,
      delivery_lead_time: deliveryLeadTime || undefined,
      shipping_method: shippingMethod || undefined,
      shipping_terms: shippingTerms || undefined,
      validity: validity || undefined,
      ...Object.fromEntries(Object.entries(caseInformation).map(([key, value]) => [key, value || null])),
      notes: notes || undefined,
      discount_amount: documentDiscountAmount || 0,
      // FR-2.3 step 1: explicit list selection wins over applicability.
      ...(priceListId ? { price_list_id: Number(priceListId) } : {}),
      // FR-5.1/5.2: explicit document currency; empty = server resolves.
      ...(currencyCode ? { currency_code: currencyCode } : {}),
      discount_type: discountMode,
      discount_value: discountValue,
      ...(tags.length ? { tags } : {}),
    lines: lines
      .filter((line) => line.description || line.product_id)
      .map((line) => ({
        line_type: line.line_type || 'product',
        sequence: lines.indexOf(line),
        product_id: line.product_id ? parseInt(line.product_id, 10) : null,
        model_number: line.model_number || null,
        description: line.description || 'Item',
        product_notes: line.product_notes || null,
        quantity: line.quantity,
        unit_price: line.unit_price === '' ? 0 : line.unit_price,
        price_mode: line.price_mode ?? undefined,
        override_reason: line.override_reason ?? undefined,
        tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
        discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
      })),
    };

    createMutation.mutate(payload);
  };

  return (
    <div className="p-6 space-y-6">
      <PageHeader
        title="Create Quotation"
        description="Prepare a quotation for your customer. Confirm to create a Sales Order."
        backTo="/quotations"
      >
        <div className="mt-2">
          <TagPicker value={tags} onChange={setTags} />
        </div>
      </PageHeader>

      <form onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Quotation Details</CardTitle>
          </CardHeader>
          <CardContent>
            {/* Compact header (invoice parity): customer + dates on row 1,
                commercial pills on row 2; secondary fields stay in the drawer. */}
            <div className="grid grid-cols-2 items-end gap-3 md:grid-cols-4 xl:grid-cols-12">
              <div className="col-span-2 md:col-span-2 xl:col-span-6">
                <label className="mb-1 block text-xs font-medium">Customer *</label>
                <CustomerSearchCombobox
                  compact
                  value={customerId}
                  onValueChange={(id, customer) => {
                    autofilledCustomerRef.current = null;
                    setCustomerId(id);
                    setCustomerMatch(customer ? {
                      contacts: (customer as any).matched_contacts ?? [],
                      tags: (customer as any).matched_tags ?? [],
                      addresses: (customer as any).matched_addresses ?? [],
                    } : null);
                    if (customer && (customer as any).code) {
                      setCustomerReference((current) => current || (customer as any).code);
                    }
                  }}
                  placeholder="Search customers..."
                />
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
                <label className="mb-1 block text-xs font-medium">Quote Date</label>
                <input
                  type="date"
                  value={quotationDate}
                  onChange={(event) => handleQuotationDateChange(event.target.value)}
                  className="h-9 w-full min-w-0 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>
              <div className="col-span-1 md:col-span-1 xl:col-span-3">
                <label className="mb-1 block text-xs font-medium">Valid Until</label>
                <input
                  type="date"
                  value={validUntil}
                  onChange={(event) => handleValidUntilChange(event.target.value)}
                  className="h-9 w-full min-w-0 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>
            </div>

            {/* Row 2: direct dropdowns + chips, one compact strip. */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Document currency — auto follows the customer's price list">
                <span className="text-muted-foreground">Currency</span>
                <select
                  value={currencyCode}
                  onChange={(event) => {
                    const next = event.target.value;
                    setCurrencyCode(next);
                    // FR-5.2: changing currency re-prices auto lines through
                    // the engine; manual lines keep their value.
                    void resolvePricingForLines(lines, undefined, next || undefined);
                  }}
                  className="bg-transparent text-xs font-medium focus:outline-none"
                >
                  <option value="">Auto</option>
                  {currencyCode && !(sellableCurrencies as any[]).some((currency: any) => currency.code === currencyCode) && (
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
                    // Pass the selection explicitly — state has not committed.
                    void resolvePricingForLines(lines, next);
                  }}
                  className="max-w-[18rem] bg-transparent text-xs font-medium focus:outline-none"
                >
                  <option value="">Auto</option>
                  {priceListRows.map((list: any) => (
                    <option key={list.id} value={String(list.id)}>
                      {list.name} · {list.currency_code}
                    </option>
                  ))}
                </select>
              </label>
              {/* Issue #408: commitment date + shipping policy (Odoo promise
                  date). Expected date is computed server-side from lead times. */}
              <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Commitment (promised) delivery date">
                <span className="text-muted-foreground">Commitment</span>
                <input
                  type="date"
                  value={commitmentDate}
                  onChange={(event) => setCommitmentDate(event.target.value)}
                  aria-label="Commitment Date"
                  className="bg-transparent text-xs font-medium focus:outline-none"
                />
              </label>
              <label className="inline-flex h-8 items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs" title="Shipping policy for delivery orders">
                <span className="text-muted-foreground">Shipping</span>
                <select
                  value={shippingPolicy}
                  onChange={(event) => setShippingPolicy(event.target.value as 'asap' | 'when_all_ready')}
                  aria-label="Shipping Policy"
                  className="bg-transparent text-xs font-medium focus:outline-none"
                >
                  <option value="asap">As soon as possible</option>
                  <option value="when_all_ready">When all products are ready</option>
                </select>
              </label>
              <HeaderChip
                icon="solar:document-text-bold-duotone"
                label="Number"
                value={customQuoteNumber || 'Auto'}
                title="Quotation number"
              >
                <input
                  type="text"
                  value={customQuoteNumber}
                  onChange={(event) => setCustomQuoteNumber(event.target.value)}
                  placeholder={quoteNumberPreview}
                  className="h-8 w-full rounded-md border border-input bg-background px-2.5 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-ring"
                />
                <p className="mt-1 text-[11px] text-muted-foreground">Leave blank to use the next auto-generated number ({quoteNumberPreview}).</p>
              </HeaderChip>
              <button
                type="button"
                onClick={() => setDetailsOpen(true)}
                title="References, contacts, addresses, logistics and terms"
                className="inline-flex h-8 max-w-[24rem] items-center gap-1.5 rounded-full border border-border bg-background px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/60"
              >
                <Icon icon="solar:document-add-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">Details{detailsSummary ? ` · ${detailsSummary}` : ''}</span>
              </button>
            </div>

            {/* Pricing feedback (invoice parity): rate snapshot, applied list,
                resolution errors — rendered only when there is something. */}
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

            {/* One compact Quick Actions row (invoice parity). */}
            <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900/40">
              <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Quick Actions</div>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {[
                  { key: 'customers', label: 'Manage Customers', icon: 'solar:users-group-rounded-bold-duotone', onClick: () => navigate('/customers') },
                  { key: 'profile', label: 'Open Profile', icon: 'solar:user-id-bold-duotone', onClick: () => customerId && navigate(`/customers/${customerId}`), disabled: !customerId },
                  { key: 'products', label: 'Product List', icon: 'solar:box-bold-duotone', onClick: () => navigate('/products') },
                  { key: 'new-product', label: 'New Product', icon: 'solar:add-square-bold-duotone', onClick: () => navigate('/products/new') },
                ].map((action) => (
                  <button
                    key={action.key}
                    type="button"
                    onClick={action.onClick}
                    disabled={Boolean(action.disabled)}
                    className="group flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-left transition-colors hover:border-primary/40 hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-800 dark:bg-slate-900/40 dark:hover:bg-primary/10"
                  >
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                      <Icon icon={action.icon} className="h-4 w-4" />
                    </span>
                    <span className="min-w-0 flex-1 text-xs font-medium text-slate-900 dark:text-slate-100">{action.label}</span>
                    <Icon icon="solar:alt-arrow-right-linear" className="h-4 w-4 shrink-0 text-slate-300 transition-colors group-hover:text-primary" />
                  </button>
                ))}
              </div>
            </div>

          </CardContent>
        </Card>

        <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
          <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-xl">
            <div className="border-b px-5 py-4">
              <SheetHeader>
                <SheetTitle>Quotation details</SheetTitle>
                <SheetDescription>
                  References, contacts, addresses and terms.
                </SheetDescription>
              </SheetHeader>
            </div>
            <div className="space-y-4 px-5 py-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Customer Ref.</label>
                  <input
                    type="text"
                    value={customerReference}
                    onChange={(event) => setCustomerReference(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="PO / customer code"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Internal Ref.</label>
                  <InternalReferenceTags value={internalReference} onChange={setInternalReference} />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Attention To</label>
                  <input
                    type="text"
                    value={attentionTo}
                    onChange={(event) => setAttentionTo(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Primary contact"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Email</label>
                  <input
                    type="email"
                    value={contactEmail}
                    onChange={(event) => setContactEmail(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="billing@customer.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Phone</label>
                  <input
                    type="text"
                    value={contactPhone}
                    onChange={(event) => setContactPhone(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Phone or mobile"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Salesperson</label>
                  <input
                    type="text"
                    value={salesperson}
                    onChange={(event) => setSalesperson(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="Assigned salesperson"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Delivery Lead Time</label>
                  <input
                    type="text"
                    value={deliveryLeadTime}
                    onChange={(event) => setDeliveryLeadTime(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="e.g. 2 weeks"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Method</label>
                  <input
                    type="text"
                    value={shippingMethod}
                    onChange={(event) => setShippingMethod(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="e.g. Sea freight, Air"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Terms</label>
                  <input
                    type="text"
                    value={shippingTerms}
                    onChange={(event) => setShippingTerms(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="e.g. FOB, CIF, EXW"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Credit Terms</label>
                  <input
                    type="text"
                    value={creditTerms}
                    onChange={(event) => setCreditTerms(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="e.g. Net 30 days"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Validity</label>
                  <input
                    type="text"
                    value={validity}
                    onChange={(event) => setValidity(event.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    placeholder="e.g. 30 days"
                  />
                </div>
              </div>

              {/* Addresses are auto-filled from the customer, so they stay
                  collapsed to a one-line summary and only open for edits. */}
              <div className="grid grid-cols-1 gap-3">
                <details
                  open={billingOpen}
                  onToggle={(event) => {
                    const open = (event.currentTarget as HTMLDetailsElement).open;
                    setBillingOpen(open);
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
                          {contactNameById(billingContactId) || attentionTo || 'No contact'}
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
                              setAttentionTo((current) => (contact as any).name || (contact as any).display_name || current);
                              setContactEmail((current) => contact.email || current);
                              setContactPhone((current) => contact.phone || (contact as any).mobile || current);
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
                          onValueChange={(val) => setShippingContactId(val)}
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

              <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <Icon icon="solar:notes-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                <div className="min-w-0 flex-1">
                  <label className="block text-xs font-medium text-slate-600">
                    Notes <span className="font-normal text-slate-400">(internal — not printed on the quotation)</span>
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

        {/* D4 (invoice parity): case/patient fields render only for the
            Medical profile and stay collapsed behind a one-line summary. */}
        {fieldProfile === 'medical' && (
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
                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                  <div>
                    <label className="block text-sm font-medium mb-1">Case Number</label>
                    <CaseSearchCombobox
                      value={caseInformation.case_number}
                      onValueChange={(value) => setCaseInformation((current) => ({ ...current, case_number: value }))}
                      onSelectCase={(medicalCase) => {
                        // Selecting an existing case populates the section;
                        // everything stays editable for a new case.
                        setCaseInformation((current) => ({
                          ...current,
                          case_number: medicalCase.case_no ?? current.case_number,
                          date_of_case: medicalCase.case_date ? String(medicalCase.case_date).slice(0, 10) : current.date_of_case,
                          case_location: medicalCase.location ?? current.case_location,
                          patient_name: medicalCase.patient_name ?? current.patient_name,
                        }));
                      }}
                      customerId={customerId}
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Date of Case</label>
                    <input
                      type="date"
                      value={caseInformation.date_of_case}
                      onChange={(event) => setCaseInformation((current) => ({ ...current, date_of_case: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    />
                  </div>
                  {([
                    ['pr_no', 'PR No'], ['contract_no', 'Contract No'],
                    ['plant_code', 'Plant Code'], ['company_code', 'Company Code'],
                  ] as const).map(([field, label]) => (
                    <div key={field}>
                      <label className="block text-sm font-medium mb-1">{label}</label>
                      <input
                        type="text"
                        value={caseInformation[field]}
                        onChange={(event) => setCaseInformation((current) => ({ ...current, [field]: event.target.value }))}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                      />
                    </div>
                  ))}
                  <div>
                    <label className="block text-sm font-medium mb-1">Location</label>
                    <input
                      type="text"
                      value={caseInformation.case_location}
                      onChange={(event) => setCaseInformation((current) => ({ ...current, case_location: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                      placeholder="Case / site location"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Patient Name</label>
                    <input
                      type="text"
                      value={caseInformation.patient_name}
                      onChange={(event) => setCaseInformation((current) => ({ ...current, patient_name: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                      placeholder="Patient name"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Cost Center ID</label>
                    <input
                      type="text"
                      value={caseInformation.cost_center_id}
                      onChange={(event) => setCaseInformation((current) => ({ ...current, cost_center_id: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                      placeholder="Cost center reference"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Delivery Date</label>
                    <input
                      type="date"
                      value={caseInformation.delivery_date}
                      onChange={(event) => setCaseInformation((current) => ({ ...current, delivery_date: event.target.value }))}
                      className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"
                    />
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
                <SalesLineColumnMenu columns={QUOTATION_CREATE_COLUMNS} hidden={hiddenLineColumns} setHidden={setHiddenLineColumns} />
              </div>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <SalesLineColumnVisibility tableClass="quotation-create-columns" columns={QUOTATION_CREATE_COLUMNS} hiddenColumns={hiddenLineColumns} onHiddenColumnsChange={setHiddenLineColumns} showMenu={false}>
              <LineItemsTable
                variant="raw"
                tableClassName="w-full min-w-[1150px] text-sm"
                headRowClassName="border-b bg-muted/30"
                columns={productLineColumns({
                  sku: true,
                  description: true,
                  modelNumber: true,
                  productNotes: true,
                  taxRate: true,
                })}
              >
                  {lines.map((line, index) => (
                    <tr
                      key={index}
                      className="border-b transition-colors hover:bg-muted/20"
                      draggable={(line.line_type ?? 'product') === 'product'}
                      onDragStart={() => setDraggedLineIndex(index)}
                      onDragOver={(event) => event.preventDefault()}
                      onDrop={() => {
                        if (draggedLineIndex !== null && draggedLineIndex !== index) setLines(reorderDocumentLines(lines, draggedLineIndex, index))
                        setDraggedLineIndex(null)
                      }}
                    >
                      {line.line_type === 'note' || line.line_type === 'section' ? (
                        <DocumentTextLineRow
                          type={line.line_type}
                          text={line.description}
                          colSpan={11}
                          total={line.line_type === 'section' ? money(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined}
                          onChange={(text) => updateLine(index, 'description', text)}
                          onDelete={() => removeLine(index)}
                          onDragStart={() => setDraggedLineIndex(index)}
                          onDrop={() => { if (draggedLineIndex !== null) setLines(reorderDocumentLines(lines, draggedLineIndex, index)); setDraggedLineIndex(null) }}
                        />
                      ) : <>
                      <td className={cn(LINE_CELL, 'w-10')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                          {lines.slice(0, index + 1).filter((item) => (item.line_type ?? 'product') === 'product').length}
                        </div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                        <ProductSearchCombobox
                          value={line.product_id}
                          onValueChange={(val, product) => {
                            const productChanged = val !== lines[index].product_id
                            // #296: one state update per pick — the pricing
                            // resolver runs on the same array, never a stale one.
                            if (product) {
                              const updated = [...lines]
                              updated[index] = {
                                ...updated[index],
                                product_id: val,
                                description: productChanged
                                  ? getProductLineDescription(product)
                                  : (updated[index].description || getProductLineDescription(product)),
                                // Re-price from the NEW product; only a no-op
                                // re-select of the same product keeps edits.
                                unit_price: productChanged
                                  ? (parseFloat((product as any).price || (product as any).selling_price || '0') || 0)
                                  : updated[index].unit_price,
                                price_mode: productChanged ? ('auto' as const) : updated[index].price_mode,
                                model_number: (product as any).manufacturer_part_number || (productChanged ? '' : updated[index].model_number),
                                sku: (product as any).sku || (productChanged ? '' : updated[index].sku),
                                tax_rate: productChanged ? resolveLineTaxRate(product) : updated[index].tax_rate,
                              }
                              setLines(updated)
                              void resolvePricingForLines(updated)
                              return
                            }
                            updateLine(index, 'product_id', val)
                          }}
                          placeholder="Search product..."
                          wrapText
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <div className={LINE_VALUE}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[16rem]')}>
                        <textarea
                          value={line.description}
                          onChange={(event) => updateLine(index, 'description', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Description"
                          required
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[7rem]')}>
                        <div className={LINE_VALUE}>{line.model_number || <span className="opacity-60">Model #</span>}</div>
                      </td>

                      <td className={cn(LINE_CELL, 'min-w-[10rem]')}>
                        <textarea
                          value={line.product_notes}
                          onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA}
                          placeholder="Product notes"
                        />
                      </td>

                      <td className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                        <div className={LINE_CELL_BOX}>
                        <input
                          type="number"
                          min="0"
                          value={line.quantity}
                          onChange={(event) => {
                            const raw = event.target.value
                            // HR71-08: keep literal intent — empty is allowed
                            // while typing (caught on submit), no silent
                            // substitution of 1 or 0.
                            if (raw === '') { updateLine(index, 'quantity', ''); return }
                            const parsed = parseFloat(raw)
                            if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
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

                      <td className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
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
                      </td>

                      <td className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                          {money(getLineTotal(line))}
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
              </SalesLineColumnVisibility>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
              <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold-duotone" onClick={addLine}>
                Add Line
              </HeaderActionButton>
              <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('section')}>Add Section</HeaderActionButton>
              <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('note')}>Add Note</HeaderActionButton>
            </div>

            <DocumentTotalsCard
              subtotal={subtotal}
              tax={taxAfterDocumentDiscount}
              total={total}
              money={money}
              discountLabel="Discount"
              discountMode={discountMode}
              onDiscountModeChange={setDiscountMode}
              discountAmount={discountAmount}
              onDiscountAmountChange={setDiscountAmount}
              lineDiscounts={lineDiscounts}
              documentDiscountAmount={documentDiscountAmount}
            />
            </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate('/quotations')}>
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
                Create Quotation
              </>
            )}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default QuotationCreatePage;
