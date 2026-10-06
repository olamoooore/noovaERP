import React, { useEffect, useRef, useState } from 'react'
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard'
import { Icon } from '@iconify/react'
import { addCalendarDays, isValidProductQuantity, todayDateOnly } from '@/lib/dateOnly'
import { followValidUntil } from '@/lib/quotationDates'
import { useToast } from '@/hooks/use-toast'
import { useDensity } from '@/hooks/useDensity'
import { cn, formatCurrency, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import { computeDocumentTotals } from '@/lib/documentTotals'
import pricingApi from '@/lib/pricingApi'
import { useSalesDocumentPricing } from '@/hooks/useSalesDocumentPricing'
import { useAuthStore } from '@/store/authStore'
import { PageHeader } from '@/components/layout/PageHeader'
import { TagPicker } from '@/components/tags/TagPicker'
import { TagEditor } from '@/components/tags/TagEditor'
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid'
import { EditingBanner } from '@/components/sales/EditingBanner'
import { SaveConflictDialog } from '@/components/sales/SaveConflictDialog'
import { extractSaveConflict, type SaveConflict } from '@/lib/recordConflict'
import { QuotationCaseInformation, emptyQuotationCaseInformation } from '@/components/sales/QuotationCaseInformation'
import { InternalReferenceTags } from '@/components/sales/InternalReferenceTags'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { ContactSearchCombobox } from '@/components/ui/ContactSearchCombobox'
import { HeaderChip } from '@/components/ui/HeaderChip'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import {
  SALES_CONTROL,
  SALES_CONTROL_HEIGHT,
  SALES_FIELD_CONTROL,
  SALES_FIELD_SHELL,
  SALES_FORM_CLASS,
  SALES_RADIUS,
  SALES_TEXTAREA,
  SALES_TEXT_SIZE,
} from '@/components/sales/salesDesignTokens'
import { emptySalesDocumentLine, isBlankSalesDocumentLine, type SalesDocumentLine } from '@/lib/salesDocumentLines'
import { resolvedCustomerAddress } from '@/lib/customerAddresses'

/**
 * Issue #314: the single quotation create/edit implementation.
 *
 * QuotationCreatePage and QuotationEditPage used to be drifted copies: the
 * edit page had no price list / pricing engine (new lines were saved at the
 * catalogue price and no `price_mode` was sent — the same bypass as F1), no
 * SKU column and no drag state, while the create page lacked the line
 * discount column. Everything lives here once; the pages are thin wrappers.
 */

export type QuotationDocumentFormMode = 'create' | 'edit'

interface QuotationDocumentFormProps {
  mode: QuotationDocumentFormMode
  /** Quotation id — required for edit mode. */
  documentId?: string
}

type AddressFormState = Record<'label' | 'street' | 'street2' | 'city' | 'state' | 'zip' | 'country', string>

const emptyAddressForm: AddressFormState = {
  label: '', street: '', street2: '', city: '', state: '', zip: '', country: '',
}

const toAddressForm = (address?: any): AddressFormState => ({
  label: address?.label ?? '',
  street: address?.street ?? '',
  street2: address?.street2 ?? '',
  city: address?.city ?? '',
  state: address?.state ?? '',
  zip: address?.zip ?? '',
  country: address?.country ?? '',
})

const addressFormToSnapshot = (address: AddressFormState): string =>
  [address.label, address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join('\n')

export const QuotationDocumentForm: React.FC<QuotationDocumentFormProps> = ({ mode, documentId }) => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const canViewPriceLists = useAuthStore((state) => state.hasPermission('pricelists.view'))
  const currentUserId = useAuthStore((state) => state.user?.id)
  const canOverwrite = useAuthStore((state) => state.hasPermission('quotations.write'))
  // #323 (§8.3): Comfortable/Compact density, remembered per user.
  const [density, setDensity] = useDensity()

  const [tags, setTags] = useState<string[]>([])
  const [customerId, setCustomerId] = useState('')
  const [quotationDate, setQuotationDate] = useState(todayDateOnly())
  // #297 (Q11): local calendar defaults, never the UTC day.
  const [validUntil, setValidUntil] = useState(() => addCalendarDays(todayDateOnly(), 30))
  const [validUntilManuallyEdited, setValidUntilManuallyEdited] = useState(false)
  // Issue #408: commitment (promised) date + shipping policy. The expected
  // date is computed by the server from product/supplier lead times.
  const [commitmentDate, setCommitmentDate] = useState('')
  const [shippingPolicy, setShippingPolicy] = useState<'asap' | 'when_all_ready'>('asap')
  const [notes, setNotes] = useState('')
  const [terms, setTerms] = useState('')
  const [discountAmount, setDiscountAmount] = useState<number | ''>('')
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed')
  const [lines, setLines] = useState<SalesDocumentLine[]>([emptySalesDocumentLine()])
  const [customerReference, setCustomerReference] = useState('')
  const [internalReference, setInternalReference] = useState('')
  const [creditTerms, setCreditTerms] = useState('')
  const [attentionTo, setAttentionTo] = useState('')
  const [contactEmail, setContactEmail] = useState('')
  const [contactPhone, setContactPhone] = useState('')
  // Structured addresses (invoice parity) — snapshots derive from these.
  const [billingAddress, setBillingAddress] = useState<AddressFormState>(emptyAddressForm)
  const [shippingAddress, setShippingAddress] = useState<AddressFormState>(emptyAddressForm)
  // Edit mode keeps the document's saved snapshots editable verbatim.
  const [billingAddressSnapshot, setBillingAddressSnapshot] = useState('')
  const [shippingAddressSnapshot, setShippingAddressSnapshot] = useState('')
  const [billingContactId, setBillingContactId] = useState('')
  const [shippingContactId, setShippingContactId] = useState('')
  const [salesperson, setSalesperson] = useState('')
  // Issue #553: the document's sales team travels through edit untouched.
  const [salesTeam, setSalesTeam] = useState('')
  const [deliveryLeadTime, setDeliveryLeadTime] = useState('')
  const [shippingMethod, setShippingMethod] = useState('')
  const [shippingTerms, setShippingTerms] = useState('')
  // Issue #333: Incoterm code + place.
  const [incotermCode, setIncotermCode] = useState('')
  const [incotermPlace, setIncotermPlace] = useState('')
  const [validity, setValidity] = useState('')
  const [caseInformation, setCaseInformation] = useState(emptyQuotationCaseInformation)
  const [detailsOpen, setDetailsOpen] = useState(false)
  const [billingOpen, setBillingOpen] = useState(false)
  const [shippingOpen, setShippingOpen] = useState(false)
  const [customerMatch, setCustomerMatch] = useState<{ contacts: string[]; tags: string[]; addresses: string[] } | null>(null)
  const [customQuoteNumber, setCustomQuoteNumber] = useState('')
  // Issue #906: what to show when the server rejects a save as stale.
  const [conflict, setConflict] = useState<SaveConflict | null>(null)
  const lastPayloadRef = useRef<Record<string, any> | null>(null)
  const autofilledCustomerRef = useRef<string | null>(null)

  // One pick fills everything (invoice parity): primary contact, references
  // and the saved billing/shipping addresses pre-fill from the customer.
  const { data: customerDetail } = useQuery({
    queryKey: ['quotation-customer-detail', customerId],
    queryFn: async () => {
      const response = await api.get(`/customers/${customerId}`)
      return response.data?.data ?? response.data
    },
    enabled: Boolean(customerId),
  })

  const { data: activePriceLists } = useQuery({
    queryKey: ['price-lists', 'active'],
    queryFn: () => pricingApi.listPriceLists({ active_only: true, per_page: 200 }),
    enabled: canViewPriceLists,
  })
  const priceListRows: any[] = (activePriceLists as any)?.data ?? activePriceLists ?? []

  const { data: sellableCurrencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  })

  // Edit mode: load the document once and hydrate the form.
  const {
    data: quotation,
    isLoading,
    refetch: refetchQuotation,
  } = useQuery({
    queryKey: ['quotation', documentId],
    queryFn: async () => {
      const response = await api.get(`/quotations/${documentId}`)
      return response.data
    },
    enabled: mode === 'edit' && !!documentId,
  })

  const {
    currencyCode,
    setCurrencyCode,
    priceListId,
    setPriceListId,
    pricingMeta,
    pricingError,
    resolvePricingForLines,
  } = useSalesDocumentPricing<SalesDocumentLine>({
    customerId,
    documentDate: quotationDate,
    setLines,
  })

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.post('/quotations', data)
      return response.data
    },
    onSuccess: (data) => {
      toast({ title: 'Quotation created successfully' })
      const id = data?.data?.id ?? data?.id
      if (id) {
        navigate(`/quotations/${id}`)
      } else {
        navigate('/quotations')
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create quotation',
        description: getApiErrorMessage(error),
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: async (data: unknown) => {
      // Issue #906: keep the failed payload so "Overwrite" can resend it
      // against the conflict's current version.
      const payload = (data ?? {}) as Record<string, any>
      lastPayloadRef.current = payload
      const response = await api.put(`/quotations/${documentId}`, payload)
      return response.data
    },
    onSuccess: () => {
      setConflict(null)
      queryClient.invalidateQueries({ queryKey: ['quotation', documentId] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast({ title: 'Quotation updated successfully' })
      navigate(`/quotations/${documentId}`)
    },
    onError: (error: any, variables: unknown) => {
      // Issue #906: a stale 409 opens the compare-and-choose dialog instead
      // of the generic toast.
      const saveConflict = extractSaveConflict(error, (variables ?? {}) as Record<string, any>)
      if (saveConflict) {
        setConflict(saveConflict)
        return
      }
      toast({
        variant: 'destructive',
        title: 'Failed to update quotation',
        description: getApiErrorMessage(error),
      })
    },
  })

  useEffect(() => {
    if (!quotation) return
    const hydratedCustomerId = quotation.customer_id ? String(quotation.customer_id) : ''
    setCustomerId(hydratedCustomerId)
    // The document's stored values are authoritative on load — do not let the
    // customer-autofill effect overwrite them; a later customer change resets
    // this ref through the combobox handler.
    autofilledCustomerRef.current = hydratedCustomerId
    setQuotationDate(quotation.quotation_date ? String(quotation.quotation_date).split('T')[0] : todayDateOnly())
    setValidUntil(quotation.valid_until ? String(quotation.valid_until).split('T')[0] : '')
    setCommitmentDate(quotation.commitment_date ? String(quotation.commitment_date).split('T')[0] : '')
    setShippingPolicy(quotation.shipping_policy === 'when_all_ready' ? 'when_all_ready' : 'asap')
    setNotes(quotation.notes || '')
    setTerms(quotation.terms || '')
    setDiscountMode(quotation.discount_type === 'percentage' ? 'percentage' : 'fixed')
    setDiscountAmount(quotation.discount_value != null ? Number(quotation.discount_value) : (quotation.discount_amount ? Number(quotation.discount_amount) : ''))
    setCustomerReference(quotation.customer_reference || '')
    setInternalReference(quotation.internal_reference || '')
    setCreditTerms(quotation.credit_terms || '')
    setAttentionTo(quotation.attention_to || '')
    setContactEmail(quotation.contact_email || '')
    setContactPhone(quotation.contact_phone || '')
    setBillingAddressSnapshot(quotation.billing_address_snapshot || '')
    setShippingAddressSnapshot(quotation.shipping_address_snapshot || '')
    setBillingContactId(quotation.billing_contact_id ? String(quotation.billing_contact_id) : '')
    setShippingContactId(quotation.shipping_contact_id ? String(quotation.shipping_contact_id) : '')
    setSalesperson(quotation.salesperson || '')
    setSalesTeam(quotation.sales_team || '')
    setDeliveryLeadTime(quotation.delivery_lead_time || '')
    setShippingMethod(quotation.shipping_method || '')
    setShippingTerms(quotation.shipping_terms || '')
    setIncotermCode(quotation.incoterm_code || '')
    setIncotermPlace(quotation.incoterm_place || '')
    setValidity(quotation.validity || '')
    setCaseInformation({
      case_number: quotation.case_number || '',
      date_of_case: quotation.date_of_case ? String(quotation.date_of_case).split('T')[0] : '',
      case_location: quotation.case_location || '',
      patient_name: quotation.patient_name || '',
      cost_center_id: quotation.cost_center_id || '',
      delivery_date: quotation.delivery_date ? String(quotation.delivery_date).split('T')[0] : '',
      pr_no: quotation.pr_no || '',
      contract_no: quotation.contract_no || '',
      plant_code: quotation.plant_code || '',
      company_code: quotation.company_code || '',
    })

    const mappedLines = (quotation.lines || []).map((line: any): SalesDocumentLine => ({
      line_type: line.line_type || 'product',
      product_id: line.product_id ? String(line.product_id) : '',
      sku: line.product?.sku ?? line.sku ?? '',
      model_number: line.model_number || line.product?.manufacturer_part_number || '',
      description: line.description || '',
      product_notes: line.product_notes || '',
      quantity: line.quantity ?? 1,
      unit_price: line.unit_price != null ? Number(line.unit_price) : '',
      tax_rate: line.tax_rate != null ? Number(line.tax_rate) : '',
      discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
      // A saved price is carried verbatim: the engine may preview a new price
      // for a changed date/list but the server only stores the sent value.
      price_mode: (line.line_type ?? 'product') === 'product' && line.unit_price != null ? 'carried' : undefined,
    }))
    setLines(mappedLines.length > 0 ? mappedLines : [emptySalesDocumentLine()])
  }, [quotation])

  useEffect(() => {
    if (!customerDetail || Array.isArray(customerDetail)) return
    if (autofilledCustomerRef.current === customerId) return
    autofilledCustomerRef.current = customerId

    const contacts = customerDetail.customer_contacts ?? customerDetail.customerContacts ?? []
    const primary = contacts.find((contact: any) => contact.is_primary) ?? contacts[0]

    setAttentionTo(primary?.name ?? customerDetail.name ?? '')
    setContactEmail(primary?.email ?? customerDetail.email ?? '')
    setContactPhone(primary?.phone ?? primary?.mobile ?? customerDetail.phone ?? customerDetail.mobile ?? '')
    setCustomerReference((prev) => prev || customerDetail.code || '')

    const days = Number(customerDetail.payment_terms)
    if (Number.isFinite(days) && days >= 0) {
      setCreditTerms((prev) => prev || (days > 0 ? `Net ${days} days` : 'Due on receipt'))
    }

    const addresses = customerDetail.addresses ?? []
    // ADR-003B R4: prefer the backend resolver's bill-to/ship-to.
    const pick = (type: 'billing' | 'shipping') => {
      const resolved = resolvedCustomerAddress(customerDetail, type)
      if (resolved !== undefined) return resolved
      return addresses.find((address: any) => address.type === type && address.is_default)
        ?? addresses.find((address: any) => address.type === type)
    }
    const nextBilling = toAddressForm(pick('billing'))
    const nextShipping = toAddressForm(pick('shipping'))
    setBillingAddress(nextBilling)
    setShippingAddress(nextShipping)
    setBillingContactId('')
    setShippingContactId('')
    if (mode === 'edit') {
      // The edit payload ships the raw snapshots, so keep them in step with a
      // customer change too.
      setBillingAddressSnapshot(addressFormToSnapshot(nextBilling))
      setShippingAddressSnapshot(addressFormToSnapshot(nextShipping))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerDetail, customerId])

  // Customer changes re-resolve the default prices/lists (FR-2.3). Existing
  // documents keep their saved prices until an explicit change.
  useEffect(() => {
    if (mode !== 'create' || !customerId) return
    void resolvePricingForLines(lines)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId])

  const contactNameById = (id: string) => {
    const contacts = customerDetail?.customer_contacts ?? customerDetail?.customerContacts ?? []
    return contacts.find((contact: any) => String(contact.id) === id)?.name ?? ''
  }
  const addressSummary = (address: AddressFormState) =>
    [address.street, address.city, address.state].filter(Boolean).join(', ')
  const updateAddress = (type: 'billing' | 'shipping', field: keyof AddressFormState, value: string) => {
    const setter = type === 'billing' ? setBillingAddress : setShippingAddress
    setter((current) => ({ ...current, [field]: value }))
  }
  const caseCount = Object.values(caseInformation).some((value) => value) ? 1 : 0

  const hasPricingFeedback = Boolean(
    (currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode)
      || pricingMeta?.price_list_name
      || pricingError,
  )
  const money = (value: number) => formatCurrency(value, currencyCode || undefined)

  const productLineCount = lines.filter((line) => (line.line_type ?? 'product') === 'product').length

  const documentTotals = computeDocumentTotals(lines, { mode: discountMode, amount: discountAmount })
  // The totals card shows the gross subtotal + line discounts separately.
  const subtotal = documentTotals.grossSubtotal
  const lineDiscounts = documentTotals.lineDiscounts
  const documentDiscountAmount = documentTotals.documentDiscountAmount
  const taxAfterDocumentDiscount = documentTotals.taxTotal
  const total = documentTotals.total
  const discountValue = discountAmount === '' ? 0 : discountAmount
  const quoteNumberPreview = `QUO-${new Date(quotationDate).getFullYear()}-XXXX`
  // #323: the drawer trigger carries the live line/total summary.
  const detailsSummary = [
    `${productLineCount} line${productLineCount === 1 ? '' : 's'}`,
    money(total),
    customerReference ? `PO ${customerReference}` : null,
    caseCount > 0 ? `cases ${caseCount}` : null,
    notes ? 'notes' : null,
  ].filter(Boolean).join(' · ')

  // #297 (Q11): changing the quote date moves Valid Until with it (unless the
  // operator edited Valid Until themselves) and re-runs auto pricing against
  // the new date.
  const handleQuotationDateChange = (value: string) => {
    setQuotationDate(value)
    setValidUntil(followValidUntil(value, validUntil, validUntilManuallyEdited))
    // #299 (F7): the price list is date-effective — re-resolve auto lines for
    // the new date (state has not updated yet, so pass it explicitly).
    void resolvePricingForLines(lines, { dateOverride: value })
  }

  const handleValidUntilChange = (value: string) => {
    setValidUntil(value)
    setValidUntilManuallyEdited(true)
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' })
      return
    }

    // #290: ignore fully-empty untouched rows consistently (same as the payload
    // filter below) so a lingering starter row never blocks saving.
    const submissionLines = lines.filter((line) => !isBlankSalesDocumentLine(line))

    if (submissionLines.length === 0 || submissionLines.every((line) => !line.description && !line.product_id)) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' })
      return
    }

    const missingProductIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id
    )
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      })
      return
    }

    if (discountMode === 'percentage' && discountValue > 100) {
      toast({ variant: 'destructive', title: 'Percentage discount cannot exceed 100%' })
      return
    }

    // #295 (Q7): product lines need a quantity greater than 0 with at most
    // 4 decimals — the API rejects 0/empty and over-precision; report it here
    // with a clear message instead of a generic failure.
    const invalidQuantityIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !isValidProductQuantity(line.quantity)
    )
    if (invalidQuantityIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Invalid quantity on line ${invalidQuantityIndex + 1}`,
        description: 'Enter a quantity greater than 0 with at most 4 decimals.',
      })
      return
    }

    const payload: Record<string, any> = {
      customer_id: parseInt(customerId, 10),
      ...(mode === 'create' && customQuoteNumber.trim() ? { quotation_number: customQuoteNumber.trim() } : {}),
      quotation_date: quotationDate,
      valid_until: validUntil || null,
      // Issue #408: promised date + partial-delivery policy.
      commitment_date: commitmentDate || null,
      shipping_policy: shippingPolicy,
      customer_reference: customerReference || null,
      internal_reference: internalReference || null,
      credit_terms: creditTerms || null,
      attention_to: attentionTo || null,
      contact_email: contactEmail || null,
      contact_phone: contactPhone || null,
      billing_address_snapshot:
        (mode === 'edit' ? billingAddressSnapshot : addressFormToSnapshot(billingAddress)) || null,
      shipping_address_snapshot:
        (mode === 'edit' ? shippingAddressSnapshot : addressFormToSnapshot(shippingAddress)) || null,
      ...(billingContactId.includes('-') ? { billing_contact_id: billingContactId } : {}),
      ...(shippingContactId.includes('-') ? { shipping_contact_id: shippingContactId } : {}),
      salesperson: salesperson || null,
      // Issue #553: keep the stored team on edit; creation defaults are applied
      // server-side when this is blank.
      sales_team: salesTeam || null,
      delivery_lead_time: deliveryLeadTime || null,
      shipping_method: shippingMethod || null,
      shipping_terms: shippingTerms || null,
      incoterm_code: incotermCode || null,
      incoterm_place: incotermPlace || null,
      validity: validity || null,
      ...Object.fromEntries(Object.entries(caseInformation).map(([key, value]) => [key, value || null])),
      notes: notes || null,
      terms: terms || null,
      discount_amount: documentDiscountAmount || 0,
      // FR-2.3 step 1: explicit list selection wins over applicability.
      ...(priceListId ? { price_list_id: Number(priceListId) } : {}),
      // FR-5.1/5.2: explicit document currency; empty = server resolves.
      ...(currencyCode ? { currency_code: currencyCode } : {}),
      discount_type: discountMode,
      discount_value: discountValue,
      ...(mode === 'edit' ? { version: quotation?.lock_version ?? undefined } : {}),
      ...(mode === 'create' && tags.length ? { tags } : {}),
      lines: submissionLines
        .filter((line) => line.description || line.product_id)
        .map((line, index) => ({
          line_type: line.line_type || 'product',
          sequence: index,
          product_id: line.product_id ? parseInt(line.product_id, 10) : null,
          model_number: line.model_number || null,
          description: line.description || 'Item',
          product_notes: line.product_notes || null,
          quantity: line.quantity,
          unit_price: line.unit_price === '' ? 0 : line.unit_price,
          // FR-6.2/FR-6.3: the pricing intent travels with the line so the
          // server resolves auto lines and never silently replaces a manual one.
          price_mode: line.price_mode ?? undefined,
          override_reason: line.override_reason ?? undefined,
          tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
          discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
        })),
    }

    if (mode === 'edit') {
      updateMutation.mutate(payload)
    } else {
      createMutation.mutate(payload)
    }
  }

  // Issue #906: discard local edits, refetch and re-hydrate the server copy.
  const handleReloadTheirs = () => {
    setConflict(null)
    void queryClient.invalidateQueries({ queryKey: ['quotation', documentId] })
    void refetchQuotation()
  }

  // Issue #906: resend my payload against the version the conflict reported.
  const handleOverwrite = () => {
    if (!conflict || !lastPayloadRef.current) return
    updateMutation.mutate({ ...lastPayloadRef.current, version: conflict.currentVersion })
  }

  if (mode === 'edit' && isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading quotation...</p>
        </div>
      </div>
    )
  }

  const isPending = mode === 'edit' ? updateMutation.isPending : createMutation.isPending

  return (
    <div className={cn('p-6 space-y-6', SALES_FORM_CLASS)} data-density={density}>
      {mode === 'create' ? (
        <PageHeader
          title="Create Quotation"
          description="Prepare a quotation for your customer. Confirm to create a Sales Order."
          backTo="/quotations"
        >
          <div className="mt-2">
            <TagPicker value={tags} onChange={setTags} />
          </div>
        </PageHeader>
      ) : (
        <div>
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
            onClick={() => navigate(`/quotations/${documentId}`)}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Edit Quotation</h1>
            <p className="text-muted-foreground mt-1">
              {quotation?.quotation_number ? `Editing ${quotation.quotation_number}` : 'Update quotation details'}
            </p>
            {documentId && <div className="mt-2"><TagEditor subjectType="quotation" subjectId={documentId} /></div>}
          </div>
        </div>
      )}

      {mode === 'edit' && (
        <EditingBanner activeEditor={quotation?.active_lock} currentUserId={currentUserId} />
      )}

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
                  className={cn(SALES_CONTROL, 'justify-between')}
                  value={customerId}
                  onValueChange={(id, customer) => {
                    autofilledCustomerRef.current = null
                    setCustomerId(id)
                    setCustomerMatch(customer ? {
                      contacts: (customer as any).matched_contacts ?? [],
                      tags: (customer as any).matched_tags ?? [],
                      addresses: (customer as any).matched_addresses ?? [],
                    } : null)
                    if (customer && (customer as any).code) {
                      setCustomerReference((current) => current || (customer as any).code)
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
                  className={SALES_CONTROL}
                />
              </div>
              <div className="col-span-1 md:col-span-1 xl:col-span-3">
                <label className="mb-1 block text-xs font-medium">Valid Until</label>
                <input
                  type="date"
                  value={validUntil}
                  onChange={(event) => handleValidUntilChange(event.target.value)}
                  className={SALES_CONTROL}
                />
              </div>
            </div>

            {/* Row 2: direct dropdowns + chips, one compact strip. */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <label className={SALES_FIELD_SHELL} title="Document currency — auto follows the customer's price list">
                <span className="text-muted-foreground">Currency</span>
                <select
                  aria-label="Document currency"
                  value={currencyCode}
                  onChange={(event) => {
                    const next = event.target.value
                    setCurrencyCode(next)
                    // FR-5.2: changing currency re-prices auto lines through
                    // the engine; manual lines keep their value.
                    void resolvePricingForLines(lines, { currencyOverride: next })
                  }}
                  className={SALES_FIELD_CONTROL}
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
              <label className={SALES_FIELD_SHELL} title="Price list — Auto applies the customer's list">
                <span className="text-muted-foreground">List</span>
                <select
                  aria-label="Price list"
                  value={priceListId}
                  onChange={(event) => {
                    const next = event.target.value
                    setPriceListId(next)
                    // Pass the selection explicitly — state has not committed.
                    void resolvePricingForLines(lines, { listOverride: next })
                  }}
                  className={cn(SALES_FIELD_CONTROL, 'max-w-[18rem]')}
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
              <label className={SALES_FIELD_SHELL} title="Commitment (promised) delivery date">
                <span className="text-muted-foreground">Commitment</span>
                <input
                  type="date"
                  value={commitmentDate}
                  onChange={(event) => setCommitmentDate(event.target.value)}
                  aria-label="Commitment Date"
                  className={SALES_FIELD_CONTROL}
                />
              </label>
              <label className={SALES_FIELD_SHELL} title="Shipping policy for delivery orders">
                <span className="text-muted-foreground">Shipping</span>
                <select
                  value={shippingPolicy}
                  onChange={(event) => setShippingPolicy(event.target.value as 'asap' | 'when_all_ready')}
                  aria-label="Shipping Policy"
                  className={SALES_FIELD_CONTROL}
                >
                  <option value="asap">As soon as possible</option>
                  <option value="when_all_ready">When all products are ready</option>
                </select>
              </label>
              {mode === 'create' && (
                <HeaderChip
                  icon="solar:document-text-bold-duotone"
                  label="Number"
                  value={customQuoteNumber || 'Auto'}
                  title="Quotation number"
                  className={cn(SALES_CONTROL_HEIGHT, SALES_RADIUS, SALES_TEXT_SIZE)}
                >
                  <input
                    type="text"
                    value={customQuoteNumber}
                    onChange={(event) => setCustomQuoteNumber(event.target.value)}
                    placeholder={quoteNumberPreview}
                    className={cn(SALES_CONTROL, 'font-mono')}
                  />
                  <p className="mt-1 text-[11px] text-muted-foreground">Leave blank to use the next auto-generated number ({quoteNumberPreview}).</p>
                </HeaderChip>
              )}
              <button
                type="button"
                onClick={() => setDetailsOpen(true)}
                title="References, contacts, addresses, logistics and terms"
                className={cn(
                  SALES_FIELD_SHELL,
                  'max-w-[24rem] font-medium text-muted-foreground transition-colors hover:bg-muted/60',
                )}
              >
                <Icon icon="solar:document-add-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">Details{detailsSummary ? ` · ${detailsSummary}` : ''}</span>
              </button>
              {/* #323 (§8.3): Comfortable/Compact row density, per user. */}
              <button
                type="button"
                data-testid="sales-density-toggle"
                onClick={() => setDensity(density === 'compact' ? 'comfortable' : 'compact')}
                title="Switch between comfortable and compact row density"
                aria-pressed={density === 'compact'}
                className={cn(
                  SALES_FIELD_SHELL,
                  'font-medium text-muted-foreground transition-colors hover:bg-muted/60',
                )}
              >
                <Icon icon="solar:slider-vertical-bold" className="h-3.5 w-3.5 shrink-0" />
                {density === 'compact' ? 'Compact' : 'Comfortable'}
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

            {/* One compact Quick Actions row (invoice parity, create only). */}
            {mode === 'create' && (
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
            )}
          </CardContent>
        </Card>

        <Sheet open={detailsOpen} onOpenChange={setDetailsOpen}>
          <SheetContent side="right" className="w-full overflow-y-auto p-0 sm:max-w-xl" data-density={density}>
            <div className="border-b px-5 py-4">
              <SheetHeader>
                <SheetTitle>Quotation details</SheetTitle>
                <SheetDescription>
                  References, contacts, addresses, logistics, notes, terms and case information.
                </SheetDescription>
              </SheetHeader>
              {/* #323: live line/total summary of the document being edited. */}
              <div
                data-testid="sales-details-summary"
                className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 rounded-lg border bg-muted/30 px-3 py-2 text-xs"
              >
                <span className="flex items-baseline gap-1">
                  <span className="text-muted-foreground">Lines</span>
                  <span className="font-semibold tabular-nums" data-testid="summary-line-count">
                    {productLineCount}
                  </span>
                </span>
                <span className="flex items-baseline gap-1">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span className="font-semibold tabular-nums" data-testid="summary-subtotal">
                    {money(subtotal)}
                  </span>
                </span>
                <span className="flex items-baseline gap-1">
                  <span className="text-muted-foreground">Total</span>
                  <span className="font-semibold tabular-nums" data-testid="summary-total">
                    {money(total)}
                  </span>
                </span>
              </div>
            </div>
            <div className="space-y-4 px-5 py-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Customer Ref.</label>
                  <input
                    type="text"
                    value={customerReference}
                    onChange={(event) => setCustomerReference(event.target.value)}
                    className={SALES_CONTROL}
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
                    className={SALES_CONTROL}
                    placeholder="Primary contact"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Email</label>
                  <input
                    type="email"
                    value={contactEmail}
                    onChange={(event) => setContactEmail(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="billing@customer.com"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Contact Phone</label>
                  <input
                    type="text"
                    value={contactPhone}
                    onChange={(event) => setContactPhone(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="Phone or mobile"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Salesperson</label>
                  <input
                    type="text"
                    value={salesperson}
                    onChange={(event) => setSalesperson(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="Assigned salesperson"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Delivery Lead Time</label>
                  <input
                    type="text"
                    value={deliveryLeadTime}
                    onChange={(event) => setDeliveryLeadTime(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. 2 weeks"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Method</label>
                  <input
                    type="text"
                    value={shippingMethod}
                    onChange={(event) => setShippingMethod(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. Sea freight, Air"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Shipping Terms</label>
                  <input
                    type="text"
                    value={shippingTerms}
                    onChange={(event) => setShippingTerms(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. FOB, CIF, EXW"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Incoterm Code</label>
                  <input
                    type="text"
                    aria-label="Incoterm Code"
                    value={incotermCode}
                    onChange={(event) => setIncotermCode(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. FOB, CIF, DDP"
                    maxLength={20}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Incoterm Place</label>
                  <input
                    type="text"
                    aria-label="Incoterm Place"
                    value={incotermPlace}
                    onChange={(event) => setIncotermPlace(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. Port Klang"
                    maxLength={255}
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Credit Terms</label>
                  <input
                    type="text"
                    value={creditTerms}
                    onChange={(event) => setCreditTerms(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. Net 30 days"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Validity</label>
                  <input
                    type="text"
                    value={validity}
                    onChange={(event) => setValidity(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="e.g. 30 days"
                  />
                </div>
              </div>

              {mode === 'create' ? (
                /* Addresses are auto-filled from the customer, so they stay
                   collapsed to a one-line summary and only open for edits. */
                <div className="grid grid-cols-1 gap-3">
                  <details
                    open={billingOpen}
                    onToggle={(event) => {
                      const open = (event.currentTarget as HTMLDetailsElement).open
                      setBillingOpen(open)
                      if (open) setShippingOpen(false)
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
                            className={cn(SALES_CONTROL, 'justify-between')}
                            value={billingContactId}
                            onValueChange={(val, contact) => {
                              setBillingContactId(val)
                              if (contact) {
                                setAttentionTo((current) => (contact as any).name || (contact as any).display_name || current)
                                setContactEmail((current) => contact.email || current)
                                setContactPhone((current) => contact.phone || (contact as any).mobile || current)
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
                            className={SALES_CONTROL}
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
                              className={SALES_CONTROL}
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
                      const open = (event.currentTarget as HTMLDetailsElement).open
                      setShippingOpen(open)
                      if (open) setBillingOpen(false)
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
                            className={cn(SALES_CONTROL, 'justify-between')}
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
                            className={SALES_CONTROL}
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
                              className={SALES_CONTROL}
                              placeholder={label}
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  </details>
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-3">
                  <div>
                    <label className="block text-sm font-medium mb-1">Billing Address</label>
                    <textarea
                      value={billingAddressSnapshot}
                      onChange={(event) => setBillingAddressSnapshot(event.target.value)}
                      rows={2}
                      className={SALES_TEXTAREA}
                      placeholder="Billing address"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Shipping Address</label>
                    <textarea
                      value={shippingAddressSnapshot}
                      onChange={(event) => setShippingAddressSnapshot(event.target.value)}
                      rows={2}
                      className={SALES_TEXTAREA}
                      placeholder="Delivery address"
                    />
                  </div>
                </div>
              )}

              {/* #323: notes/terms no longer live inline — they are part of
                  the single right-side details drawer. */}
              <section className="space-y-3 border-t pt-4">
                <h3 className="text-sm font-semibold">Notes and terms</h3>
                <div>
                  <label className="mb-1 block text-xs font-medium">
                    Notes <span className="font-normal text-muted-foreground">(internal — not printed on the quotation)</span>
                  </label>
                  <textarea
                    value={notes}
                    onChange={(event) => setNotes(event.target.value)}
                    rows={2}
                    className={SALES_TEXTAREA}
                    placeholder="Internal notes..."
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium">Terms</label>
                  <textarea
                    value={terms}
                    onChange={(event) => setTerms(event.target.value)}
                    rows={2}
                    className={SALES_TEXTAREA}
                    placeholder="Payment terms..."
                  />
                </div>
              </section>

              <section className="space-y-3 border-t pt-4">
                <h3 className="text-sm font-semibold">Case information</h3>
                <QuotationCaseInformation value={caseInformation} onChange={setCaseInformation} embedded />
              </section>
            </div>
          </SheetContent>
        </Sheet>

        <Card className="mb-6">
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Line Items</span>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <SalesDocumentLineGrid
              lines={lines}
              setLines={setLines}
              money={money}
              resolvePricing={(nextLines) => { void resolvePricingForLines(nextLines) }}
              defaultHiddenColumns={[6]}
              tableClass="quotation-document-columns"
            />

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
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate(mode === 'edit' ? `/quotations/${documentId}` : '/quotations')}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? (
              <>
                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2" />
                {mode === 'edit' ? 'Saving...' : 'Creating...'}
              </>
            ) : (
              <>
                <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-2" />
                {mode === 'edit' ? 'Save Changes' : 'Create Quotation'}
              </>
            )}
          </Button>
        </div>
      </form>

      {mode === 'edit' && (
        <SaveConflictDialog
          open={conflict !== null}
          conflict={conflict}
          canOverwrite={canOverwrite}
          onOpenChange={(open) => { if (!open) setConflict(null) }}
          onReloadTheirs={handleReloadTheirs}
          onOverwrite={handleOverwrite}
        />
      )}
    </div>
  )
}

export default QuotationDocumentForm
