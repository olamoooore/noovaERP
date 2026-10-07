import React, { useEffect, useRef, useState } from 'react'
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard'
import { Icon } from '@iconify/react'
import { addCalendarDays, diffCalendarDays, isValidProductQuantity, todayDateOnly } from '@/lib/dateOnly'
import { DEFAULT_QUOTE_VALIDITY_DAYS, followValidUntil } from '@/lib/quotationDates'
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
import { SalesDocumentLineGrid, type SalesDocumentLineGridHandle } from '@/components/sales/SalesDocumentLineGrid'
import { EditingBanner } from '@/components/sales/EditingBanner'
import { SaveConflictDialog } from '@/components/sales/SaveConflictDialog'
import { extractSaveConflict, type SaveConflict } from '@/lib/recordConflict'
import { QuotationCaseInformation, emptyQuotationCaseInformation } from '@/components/sales/QuotationCaseInformation'
import { InternalReferenceTags } from '@/components/sales/InternalReferenceTags'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { useCustomerAutofill } from '@/hooks/useCustomerAutofill'
import { getCustomerContactDefaults, pickCustomerAddress } from '@/lib/customerDocumentDefaults'
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
import { useDocumentDraftGuard } from '@/hooks/useDocumentDraftGuard'
import { DocumentDraftBanner, DocumentLeaveDialog } from '@/components/sales/DocumentDraftControls'
import { openAppPathInNewTab } from '@/lib/openInNewTab'
import { useFieldErrors } from '@/hooks/useFieldErrors'
import { FieldErrorsProvider, FieldSlot } from '@/components/ui/field-errors'
import { lineFieldKey, payloadRowIndexes } from '@/lib/serverValidation'

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

// '' for an all-empty address so an empty form counts as "not filled in".
const serializeAddressForm = (address: AddressFormState): string =>
  Object.values(address).some(Boolean) ? JSON.stringify(address) : ''

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
  // Issue #550: per-quotation online acceptance requirements. null = not
  // touched, so the server stamps the tenant's Sales Settings value.
  const [requireSignature, setRequireSignature] = useState<boolean | null>(null)
  const [requirePayment, setRequirePayment] = useState<boolean | null>(null)
  const [discountAmount, setDiscountAmount] = useState<number | ''>('')
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed')
  const [lines, setLines] = useState<SalesDocumentLine[]>([emptySalesDocumentLine()])
  const [customerReference, setCustomerReference] = useState('')
  const [internalReference, setInternalReference] = useState('')
  const [creditTerms, setCreditTerms] = useState('')
  // Issue #321: the payment term comes from the payment-terms master; the
  // credit-terms text follows the picked term's name.
  const [paymentTermId, setPaymentTermId] = useState('')
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
  // #318: inline field errors; server line indexes map back through the grid
  // rows that were actually sent.
  const fieldErrors = useFieldErrors()
  const payloadRowsRef = useRef<number[]>([])
  // #317: edit mode is "ready" for dirty tracking once the saved document
  // has been put into the form.
  const [hydrated, setHydrated] = useState(false)
  // #319: keyboard-first entry — a new document starts on the customer, and
  // a customer pick moves on to line 1's product.
  const customerFieldRef = useRef<HTMLDivElement>(null)
  const lineGridRef = useRef<SalesDocumentLineGridHandle>(null)
  useEffect(() => {
    if (mode !== 'create') return
    customerFieldRef.current?.querySelector<HTMLElement>('button[role="combobox"]')?.focus()
  }, [mode])
  const { fill: autofillField, setBaseline: setAutofillBaseline } = useCustomerAutofill()

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

  // Issue #316 (D4): the tenant's industry field profile decides whether the
  // Case Information group renders. Read from the defaults endpoint every
  // document creator can reach (#540). Until it resolves (or if it fails) the
  // historical behaviour ('medical' = shown) is kept.
  const { data: salesDefaults } = useQuery({
    queryKey: ['sales-settings', 'defaults'],
    queryFn: async () => {
      const response = await api.get('/settings/sales/defaults')
      return response.data?.data ?? null
    },
    staleTime: 60_000,
  })
  const fieldProfile = String((salesDefaults as any)?.invoice?.field_profile ?? 'medical')
  // Issue #545: Sales Settings → Quotations. Valid Until follows the quote
  // date by the tenant's validity days (0 = no default expiry); until the
  // defaults resolve the historical 30 days is kept.
  const rawValidityDays = Number((salesDefaults as any)?.quotation?.default_validity_days)
  const tenantValidityDays = Number.isFinite(rawValidityDays) && rawValidityDays >= 0
    ? rawValidityDays
    : DEFAULT_QUOTE_VALIDITY_DAYS
  const quotationDefaultsAppliedRef = useRef(false)
  const draftRestoredRef = useRef(false)

  // Issue #321: master data for the header pickers.
  const { data: paymentTermRows = [] } = useQuery({
    queryKey: ['payment-terms'],
    queryFn: async () => {
      const response = await api.get('/accounting/payment-terms')
      const rows = Array.isArray(response.data) ? response.data : response.data?.data
      return (Array.isArray(rows) ? rows : []) as Array<{ id: number; name: string; type?: string; days?: number; is_active?: boolean }>
    },
  })
  const { data: tenantUserRows = [] } = useQuery({
    queryKey: ['tenant-users'],
    queryFn: async () => {
      const response = await api.get('/tenant-users')
      const rows = response.data?.data ?? response.data
      return (Array.isArray(rows) ? rows : []) as Array<{ id: number; name: string; email?: string }>
    },
  })

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

  // #317: unsaved-changes guard + local (this device only) draft autosave.
  const draftGuard = useDocumentDraftGuard({
    kind: 'quotation',
    documentId: mode === 'edit' ? documentId : null,
    version: mode === 'edit' ? quotation?.lock_version ?? null : null,
    ready: mode === 'create' || hydrated,
    snapshot: {
      tags, customerId, quotationDate, validUntil, validUntilManuallyEdited, commitmentDate, shippingPolicy,
      notes, terms, discountAmount, discountMode, lines, customerReference, internalReference, creditTerms,
      paymentTermId, attentionTo, contactEmail, contactPhone, billingAddress, shippingAddress,
      billingAddressSnapshot, shippingAddressSnapshot, billingContactId, shippingContactId, salesperson,
      salesTeam, deliveryLeadTime, shippingMethod, shippingTerms, incotermCode, incotermPlace,
      caseInformation, customQuoteNumber, currencyCode, priceListId,
    },
    apply: (draft) => {
      // The restored values are the user's own: the customer auto-fill must
      // not overwrite them when the restored customer's detail loads.
      autofilledCustomerRef.current = draft.customerId
      draftRestoredRef.current = true
      setTags(draft.tags)
      setCustomerId(draft.customerId)
      setQuotationDate(draft.quotationDate)
      setValidUntil(draft.validUntil)
      setValidUntilManuallyEdited(draft.validUntilManuallyEdited)
      setCommitmentDate(draft.commitmentDate)
      setShippingPolicy(draft.shippingPolicy)
      setNotes(draft.notes)
      setTerms(draft.terms)
      setDiscountAmount(draft.discountAmount)
      setDiscountMode(draft.discountMode)
      setLines(draft.lines)
      setCustomerReference(draft.customerReference)
      setInternalReference(draft.internalReference)
      setCreditTerms(draft.creditTerms)
      setPaymentTermId(draft.paymentTermId)
      setAttentionTo(draft.attentionTo)
      setContactEmail(draft.contactEmail)
      setContactPhone(draft.contactPhone)
      setBillingAddress(draft.billingAddress)
      setShippingAddress(draft.shippingAddress)
      setBillingAddressSnapshot(draft.billingAddressSnapshot)
      setShippingAddressSnapshot(draft.shippingAddressSnapshot)
      setBillingContactId(draft.billingContactId)
      setShippingContactId(draft.shippingContactId)
      setSalesperson(draft.salesperson)
      setSalesTeam(draft.salesTeam)
      setDeliveryLeadTime(draft.deliveryLeadTime)
      setShippingMethod(draft.shippingMethod)
      setShippingTerms(draft.shippingTerms)
      setIncotermCode(draft.incotermCode)
      setIncotermPlace(draft.incotermPlace)
      setCaseInformation(draft.caseInformation)
      setCustomQuoteNumber(draft.customQuoteNumber)
      setCurrencyCode(draft.currencyCode)
      setPriceListId(draft.priceListId)
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: unknown) => {
      const response = await api.post('/quotations', data)
      return response.data
    },
    onSuccess: (data) => {
      draftGuard.clearDraft()
      toast({ title: 'Quotation created successfully' })
      const id = data?.data?.id ?? data?.id
      if (id) {
        navigate(`/quotations/${id}`)
      } else {
        navigate('/quotations')
      }
    },
    onError: (error: any) => {
      const inline = fieldErrors.showServer(error, payloadRowsRef.current)
      toast({
        variant: 'destructive',
        title: inline ? 'Please fix the highlighted fields' : 'Failed to create quotation',
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
      draftGuard.clearDraft()
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
      const inline = fieldErrors.showServer(error, payloadRowsRef.current)
      toast({
        variant: 'destructive',
        title: inline ? 'Please fix the highlighted fields' : 'Failed to update quotation',
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
    setRequireSignature(typeof quotation.require_signature === 'boolean' ? quotation.require_signature : null)
    setRequirePayment(typeof quotation.require_payment === 'boolean' ? quotation.require_payment : null)
    setDiscountMode(quotation.discount_type === 'percentage' ? 'percentage' : 'fixed')
    setDiscountAmount(quotation.discount_value != null ? Number(quotation.discount_value) : (quotation.discount_amount ? Number(quotation.discount_amount) : ''))
    setCustomerReference(quotation.customer_reference || '')
    setInternalReference(quotation.internal_reference || '')
    setCreditTerms(quotation.credit_terms || '')
    setPaymentTermId(quotation.payment_term_id ? String(quotation.payment_term_id) : '')
    setAttentionTo(quotation.attention_to || '')
    setContactEmail(quotation.contact_email || '')
    setContactPhone(quotation.contact_phone || '')
    setBillingAddressSnapshot(quotation.billing_address_snapshot || '')
    setShippingAddressSnapshot(quotation.shipping_address_snapshot || '')
    setBillingContactId(quotation.billing_contact_id ? String(quotation.billing_contact_id) : '')
    setShippingContactId(quotation.shipping_contact_id ? String(quotation.shipping_contact_id) : '')
    // #320: the saved values are the auto-fill baseline — a later customer
    // change may replace them until the user edits them.
    setAutofillBaseline({
      attentionTo: quotation.attention_to || '',
      contactEmail: quotation.contact_email || '',
      contactPhone: quotation.contact_phone || '',
      customerReference: quotation.customer_reference || '',
      creditTerms: quotation.credit_terms || '',
      billingAddressSnapshot: quotation.billing_address_snapshot || '',
      shippingAddressSnapshot: quotation.shipping_address_snapshot || '',
    })
    setSalesperson(quotation.salesperson || '')
    setSalesTeam(quotation.sales_team || '')
    setDeliveryLeadTime(quotation.delivery_lead_time || '')
    setShippingMethod(quotation.shipping_method || '')
    setShippingTerms(quotation.shipping_terms || '')
    setIncotermCode(quotation.incoterm_code || '')
    setIncotermPlace(quotation.incoterm_place || '')
    // #322 (Q14): the saved document currency drives every amount on the
    // form. The header currency + rate snapshot is not re-derived on a draft
    // edit (FR-1.7), so it is shown as-is rather than falling back to the
    // tenant default.
    setCurrencyCode(quotation.currency_code ? String(quotation.currency_code).toUpperCase() : '')
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
      // Issue #409: keep the shipping-line marker through an edit.
      ...(line.is_delivery ? { is_delivery: true } : {}),
    }))
    setLines(mappedLines.length > 0 ? mappedLines : [emptySalesDocumentLine()])
    setHydrated(true)
  }, [quotation, setAutofillBaseline, setCurrencyCode])

  useEffect(() => {
    if (!customerDetail || Array.isArray(customerDetail)) return
    if (autofilledCustomerRef.current === customerId) return
    autofilledCustomerRef.current = customerId

    // #320: invoice-parity defaults (billing contact first, default addresses),
    // filled only into fields the user has not edited — a customer change
    // refreshes untouched fields and keeps the user's own values.
    const contactDefaults = getCustomerContactDefaults(customerDetail)
    autofillField('attentionTo', attentionTo, contactDefaults.attentionTo, setAttentionTo)
    autofillField('contactEmail', contactEmail, contactDefaults.contactEmail, setContactEmail)
    autofillField('contactPhone', contactPhone, contactDefaults.contactPhone, setContactPhone)
    autofillField('customerReference', customerReference, customerDetail.code ?? '', setCustomerReference)

    // #321: prefer the master payment term matching the customer's day count;
    // without one the day count is kept as text (no term is invented).
    const days = Number(customerDetail.payment_terms)
    // #324: the customer's own default master term wins over a day-count match.
    const customerDefaultTerm = customerDetail.payment_term_id
      ? paymentTermRows.find((term) => term.id === Number(customerDetail.payment_term_id))
      : undefined
    const matchingTerm = customerDefaultTerm ?? (Number.isFinite(days) && days >= 0
      ? paymentTermRows.find((term) => term.is_active !== false && (
        days === 0
          ? term.type === 'immediate' || (term.type === 'net_days' && Number(term.days) === 0)
          : term.type === 'net_days' && Number(term.days) === days
      ))
      : undefined)
    const nextCreditTerms = matchingTerm
      ? matchingTerm.name
      : Number.isFinite(days) && days >= 0 ? (days > 0 ? `Net ${days} days` : 'Due on receipt') : ''
    autofillField('creditTerms', creditTerms, nextCreditTerms, (value: string) => {
      setCreditTerms(value)
      setPaymentTermId(matchingTerm ? String(matchingTerm.id) : '')
    })

    const nextBilling = toAddressForm(pickCustomerAddress(customerDetail, 'billing'))
    const nextShipping = toAddressForm(pickCustomerAddress(customerDetail, 'shipping'))
    autofillField('billingAddress', billingAddress, nextBilling, setBillingAddress, serializeAddressForm)
    autofillField('shippingAddress', shippingAddress, nextShipping, setShippingAddress, serializeAddressForm)
    setBillingContactId('')
    setShippingContactId('')
    if (mode === 'edit') {
      // The edit payload ships the raw snapshots, so keep them in step with a
      // customer change too (unless the user edited them).
      autofillField('billingAddressSnapshot', billingAddressSnapshot, addressFormToSnapshot(nextBilling), setBillingAddressSnapshot)
      autofillField('shippingAddressSnapshot', shippingAddressSnapshot, addressFormToSnapshot(nextShipping), setShippingAddressSnapshot)
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
  // #316: a document that already carries case data keeps showing it, so
  // existing data stays readable/editable whatever the profile says.
  const showCaseInformation = fieldProfile === 'medical' || caseCount > 0

  const hasPricingFeedback = Boolean(
    (currencyCode && pricingMeta?.base_currency_code && pricingMeta.base_currency_code !== currencyCode)
      || pricingMeta?.price_list_name
      || pricingError,
  )
  // #322 (Q14): every amount is shown in the document currency — the explicit
  // or engine-resolved currency first, else the currency of the price list the
  // user picked (the server resolves the document currency from that list).
  // Only with neither does it fall back to the tenant default.
  const selectedPriceListCurrency = priceListId
    ? priceListRows.find((list: any) => String(list.id) === priceListId)?.currency_code
    : undefined
  const documentCurrency = (currencyCode || selectedPriceListCurrency || '') || undefined
  const money = (value: number) => formatCurrency(value, documentCurrency)

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

  // Issue #545: a NEW quotation takes the tenant's quotation defaults once
  // they load — never in edit mode, after a restored draft, or over a value
  // the user already entered.
  useEffect(() => {
    if (mode !== 'create' || !salesDefaults || quotationDefaultsAppliedRef.current) return
    quotationDefaultsAppliedRef.current = true
    if (draftRestoredRef.current) return
    if (!validUntilManuallyEdited) {
      setValidUntil(tenantValidityDays > 0 ? addCalendarDays(quotationDate, tenantValidityDays) : '')
    }
    const defaultTerms = String((salesDefaults as any)?.quotation?.default_terms ?? '')
    if (defaultTerms) {
      setTerms((current) => current || defaultTerms)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mode, salesDefaults])

  // #297 (Q11): changing the quote date moves Valid Until with it (unless the
  // operator edited Valid Until themselves) and re-runs auto pricing against
  // the new date.
  const handleQuotationDateChange = (value: string) => {
    setQuotationDate(value)
    setValidUntil(
      tenantValidityDays > 0
        ? followValidUntil(value, validUntil, validUntilManuallyEdited, tenantValidityDays)
        : validUntil,
    )
    // #299 (F7): the price list is date-effective — re-resolve auto lines for
    // the new date (state has not updated yet, so pass it explicitly).
    void resolvePricingForLines(lines, { dateOverride: value })
  }

  const handleValidUntilChange = (value: string) => {
    setValidUntil(value)
    setValidUntilManuallyEdited(true)
  }

  // #321: the printed "Validity" follows Valid Until so the two can never
  // contradict each other.
  const validityDays = validUntil && quotationDate ? diffCalendarDays(quotationDate, validUntil) : null
  const derivedValidity = validityDays !== null && Number.isFinite(validityDays) && validityDays >= 0
    ? `${validityDays} day${validityDays === 1 ? '' : 's'}`
    : null

  // #321: the term/salesperson already on a document stays selectable even
  // when it is no longer in the master list (deactivated term, departed user).
  const paymentTermOptions = paymentTermRows.filter(
    (term) => term.is_active !== false || String(term.id) === paymentTermId,
  )
  const salespersonInList = tenantUserRows.some((user) => user.name === salesperson)

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    fieldErrors.clearAll()

    if (!customerId) {
      fieldErrors.show({ customer_id: 'Please select a customer' })
      toast({ variant: 'destructive', title: 'Please select a customer' })
      return
    }

    // #290: ignore fully-empty untouched rows consistently (same as the payload
    // filter below) so a lingering starter row never blocks saving.
    const submissionLines = lines.filter((line) => !isBlankSalesDocumentLine(line))
    // #318: grid row of each submission line, for inline line errors.
    const submissionRows = payloadRowIndexes(lines, (line) => !isBlankSalesDocumentLine(line))

    if (submissionLines.length === 0 || submissionLines.every((line) => !line.description && !line.product_id)) {
      fieldErrors.show({ lines: 'Add at least one line item' })
      toast({ variant: 'destructive', title: 'Please add at least one line item' })
      return
    }

    const missingProductIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id
    )
    if (missingProductIndex >= 0) {
      fieldErrors.show({ [lineFieldKey(submissionRows[missingProductIndex], 'product_id')]: 'Select a product before saving.' })
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      })
      return
    }

    if (discountMode === 'percentage' && discountValue > 100) {
      fieldErrors.show({ discount_value: 'Percentage discount cannot exceed 100%' })
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
      fieldErrors.show({
        [lineFieldKey(submissionRows[invalidQuantityIndex], 'quantity')]: 'Enter a quantity greater than 0 with at most 4 decimals.',
      })
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
      payment_term_id: paymentTermId ? Number(paymentTermId) : null,
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
      // #321: validity is derived from Valid Until, never typed separately.
      validity: derivedValidity,
      ...Object.fromEntries(Object.entries(caseInformation).map(([key, value]) => [key, value || null])),
      notes: notes || null,
      terms: terms || null,
      ...(requireSignature !== null ? { require_signature: requireSignature } : {}),
      ...(requirePayment !== null ? { require_payment: requirePayment } : {}),
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
          // Issue #409: the shipping line round-trips its marker.
          ...(line.is_delivery ? { is_delivery: true } : {}),
        })),
    }

    payloadRowsRef.current = payloadRowIndexes(
      lines,
      (line) => !isBlankSalesDocumentLine(line) && Boolean(line.description || line.product_id),
    )

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
    <div className={cn('p-6 space-y-6', SALES_FORM_CLASS)} data-density={density} {...draftGuard.trackProps}>
      {mode === 'create' ? (
        <PageHeader
          title="Create Quotation"
          description="Prepare a quotation for your customer. Confirm to create a Sales Order."
          backTo="/quotations"
          guardBack={draftGuard.guard}
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
            onClick={() => draftGuard.guard(() => navigate(`/quotations/${documentId}`))}
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

      <DocumentDraftBanner guard={draftGuard} label="quotation" />

      <FieldErrorsProvider value={fieldErrors}>
      {/* #318: validation is reported inline on the field (scroll + focus);
          native browser bubbles would block submit before it can run. */}
      <form noValidate onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
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
                <FieldSlot name="customer_id">
                  <div ref={customerFieldRef}>
                    <CustomerSearchCombobox
                      focusAfterSelect={() => lineGridRef.current?.focusFirstOpenProduct()}
                      className={cn(SALES_CONTROL, 'justify-between')}
                      value={customerId}
                      onValueChange={(id, customer) => {
                        fieldErrors.clear('customer_id')
                        autofilledCustomerRef.current = null
                        setCustomerId(id)
                        setCustomerMatch(customer ? {
                          contacts: (customer as any).matched_contacts ?? [],
                          tags: (customer as any).matched_tags ?? [],
                          addresses: (customer as any).matched_addresses ?? [],
                        } : null)
                      }}
                      placeholder="Search customers..."
                    />
                  </div>
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
                <label className="mb-1 block text-xs font-medium">Quote Date</label>
                <FieldSlot name="quotation_date">
                  <input
                    type="date"
                    value={quotationDate}
                    onChange={(event) => handleQuotationDateChange(event.target.value)}
                    className={SALES_CONTROL}
                  />
                </FieldSlot>
              </div>
              <div className="col-span-1 md:col-span-1 xl:col-span-3">
                <label className="mb-1 block text-xs font-medium">Valid Until</label>
                <FieldSlot name="valid_until">
                  <input
                    type="date"
                    aria-label="Valid until"
                    value={validUntil}
                    onChange={(event) => handleValidUntilChange(event.target.value)}
                    className={SALES_CONTROL}
                  />
                </FieldSlot>
              </div>
            </div>

            {/* Row 2: direct dropdowns + chips, one compact strip. */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <label
                className={SALES_FIELD_SHELL}
                title={mode === 'edit'
                  ? 'Document currency — fixed once the quotation is saved'
                  : "Document currency — auto follows the customer's price list"}
              >
                <span className="text-muted-foreground">Currency</span>
                <select
                  aria-label="Document currency"
                  value={currencyCode}
                  // #322: the server keeps the saved currency + rate snapshot
                  // on edit, so the selector must not offer a change it drops.
                  disabled={mode === 'edit'}
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
                    { key: 'customers', label: 'Manage Customers', icon: 'solar:users-group-rounded-bold-duotone', onClick: () => openAppPathInNewTab('/customers') },
                    { key: 'profile', label: 'Open Profile', icon: 'solar:user-id-bold-duotone', onClick: () => customerId && openAppPathInNewTab(`/customers/${customerId}`), disabled: !customerId },
                    { key: 'products', label: 'Product List', icon: 'solar:box-bold-duotone', onClick: () => openAppPathInNewTab('/products') },
                    { key: 'new-product', label: 'New Product', icon: 'solar:add-square-bold-duotone', onClick: () => openAppPathInNewTab('/products/new') },
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
                  <label className="block text-sm font-medium mb-1" htmlFor="quotation-salesperson">Salesperson</label>
                  {/* #321: picked from the tenant's users (stored by name, the
                      convention the defaults, followers and digests resolve). */}
                  <select
                    id="quotation-salesperson"
                    value={salesperson}
                    onChange={(event) => setSalesperson(event.target.value)}
                    className={SALES_CONTROL}
                  >
                    <option value="">No salesperson</option>
                    {salesperson && !salespersonInList && (
                      <option value={salesperson}>{salesperson}</option>
                    )}
                    {tenantUserRows.map((user) => (
                      <option key={user.id} value={user.name}>{user.name}</option>
                    ))}
                  </select>
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
                  <label className="block text-sm font-medium mb-1" htmlFor="quotation-payment-term">Payment Terms</label>
                  {/* #321: picked from the payment-terms master; carried to the
                      sales order and invoice, where it sets the due date. */}
                  <select
                    id="quotation-payment-term"
                    value={paymentTermId}
                    onChange={(event) => {
                      const next = event.target.value
                      setPaymentTermId(next)
                      const term = paymentTermRows.find((row) => String(row.id) === next)
                      setCreditTerms(term ? term.name : '')
                    }}
                    className={SALES_CONTROL}
                  >
                    {/* A saved free-text term (legacy, or a customer day count
                        with no matching master term) is shown, not lost. */}
                    <option value="">{!paymentTermId && creditTerms ? creditTerms : 'Select payment term'}</option>
                    {paymentTermOptions.map((term) => (
                      <option key={term.id} value={String(term.id)}>{term.name}</option>
                    ))}
                  </select>
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
                {/* Issue #550: overrides of Sales Settings → Quotations. */}
                <div className="space-y-2">
                  <span className="block text-xs font-medium">Online acceptance</span>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      aria-label="Require online signature"
                      checked={requireSignature ?? Boolean((salesDefaults as any)?.quotation?.require_signature)}
                      onChange={(event) => setRequireSignature(event.target.checked)}
                    />
                    Require signature
                  </label>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      aria-label="Require online payment"
                      checked={requirePayment ?? Boolean((salesDefaults as any)?.quotation?.require_payment)}
                      onChange={(event) => setRequirePayment(event.target.checked)}
                    />
                    Require prepayment before confirmation
                  </label>
                </div>
              </section>

              {showCaseInformation && (
                <section className="space-y-3 border-t pt-4">
                  <h3 className="text-sm font-semibold">Case information</h3>
                  <QuotationCaseInformation value={caseInformation} onChange={setCaseInformation} embedded />
                </section>
              )}
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
            <FieldSlot name="lines" highlight={false}>
              <SalesDocumentLineGrid
                ref={lineGridRef}
                lines={lines}
                setLines={setLines}
                money={money}
                resolvePricing={(nextLines) => { void resolvePricingForLines(nextLines) }}
                defaultHiddenColumns={[6]}
                tableClass="quotation-document-columns"
              />
            </FieldSlot>

            <DocumentTotalsCard
              subtotal={subtotal}
              tax={taxAfterDocumentDiscount}
              total={total}
              money={money}
              discountLabel="Discount"
              discountFieldKey="discount_value"
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
            onClick={() => draftGuard.guard(() => navigate(mode === 'edit' ? `/quotations/${documentId}` : '/quotations'))}
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
      </FieldErrorsProvider>

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

      <DocumentLeaveDialog guard={draftGuard} />
    </div>
  )
}

export default QuotationDocumentForm
