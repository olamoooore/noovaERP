import React, { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { preventImplicitSubmit } from '@/lib/prevent-enter-submit'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { PageHeader } from '@/components/layout/PageHeader'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { DocumentTotalsCard } from '@/components/sales/DocumentTotalsCard'
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid'
import { EditingBanner } from '@/components/sales/EditingBanner'
import { SaveConflictDialog } from '@/components/sales/SaveConflictDialog'
import {
  SALES_CONTROL,
  SALES_FIELD_CONTROL,
  SALES_FIELD_SHELL,
  SALES_FORM_CLASS,
  SALES_TEXTAREA,
} from '@/components/sales/salesDesignTokens'
import { useToast } from '@/hooks/use-toast'
import { useDensity } from '@/hooks/useDensity'
import { useSalesDocumentPricing } from '@/hooks/useSalesDocumentPricing'
import { useAuthStore } from '@/store/authStore'
import api from '@/lib/api'
import pricingApi from '@/lib/pricingApi'
import { computeDocumentTotals } from '@/lib/documentTotals'
import { isValidProductQuantity, todayDateOnly } from '@/lib/dateOnly'
import { extractSaveConflict, type SaveConflict } from '@/lib/recordConflict'
import { cn, formatCurrency, getApiErrorMessage } from '@/lib/utils'
import { emptySalesDocumentLine, isBlankSalesDocumentLine, type SalesDocumentLine } from '@/lib/salesDocumentLines'
import { useDocumentDraftGuard } from '@/hooks/useDocumentDraftGuard'
import { DocumentDraftBanner, DocumentLeaveDialog } from '@/components/sales/DocumentDraftControls'

/**
 * Issue #374 (SALES-SO / O5): create and edit a Sales Order directly.
 *
 * Built from the shared sales-document pieces (#314) rather than a forked
 * line editor: the same SalesDocumentLineGrid, line model, pricing hook
 * (useSalesDocumentPricing → engine batch resolution), totals preview and
 * the #906 editing banner / save-conflict dialog the quotation form uses.
 * The server re-prices every line through the same service path as order
 * creation, so the preview here is never the source of truth.
 *
 * Lines are editable only while the order is a draft; a confirmed order is
 * amended from its detail page under the #407 lock/amend rules.
 */

export type SalesOrderDocumentFormMode = 'create' | 'edit'

interface SalesOrderDocumentFormProps {
  mode: SalesOrderDocumentFormMode
  /** Sales order id — required for edit mode. */
  documentId?: string
}

/** Line fields the grid does not show but a saved line must keep on edit. */
type SalesOrderLineExtras = {
  income_account_id?: number | null
  project_id?: number | null
  department_id?: number | null
  invoicing_policy?: string | null
}

type SalesOrderFormLine = SalesDocumentLine & SalesOrderLineExtras

const dateOnly = (value: unknown): string => (value ? String(value).split('T')[0] : '')

export const SalesOrderDocumentForm: React.FC<SalesOrderDocumentFormProps> = ({ mode, documentId }) => {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const canViewPriceLists = useAuthStore((state) => state.hasPermission('pricelists.view'))
  const canOverwrite = useAuthStore((state) => state.hasPermission('sales.write'))
  const currentUserId = useAuthStore((state) => state.user?.id)
  const [density] = useDensity()

  const [customerId, setCustomerId] = useState('')
  const [orderDate, setOrderDate] = useState(todayDateOnly())
  const [requiredDate, setRequiredDate] = useState('')
  const [commitmentDate, setCommitmentDate] = useState('')
  const [shippingPolicy, setShippingPolicy] = useState<'asap' | 'when_all_ready'>('asap')
  const [customerReference, setCustomerReference] = useState('')
  const [salesperson, setSalesperson] = useState('')
  const [incotermCode, setIncotermCode] = useState('')
  const [incotermPlace, setIncotermPlace] = useState('')
  const [notes, setNotes] = useState('')
  const [internalNotes, setInternalNotes] = useState('')
  const [discountAmount, setDiscountAmount] = useState<number | ''>('')
  const [discountMode, setDiscountMode] = useState<'fixed' | 'percentage'>('fixed')
  const [lines, setLines] = useState<SalesDocumentLine[]>([emptySalesDocumentLine()])
  // Issue #906: what to show when the server rejects a save as stale.
  const [conflict, setConflict] = useState<SaveConflict | null>(null)
  const lastPayloadRef = useRef<Record<string, any> | null>(null)
  // #317: edit mode is "ready" for dirty tracking once the order is loaded.
  const [hydrated, setHydrated] = useState(false)

  const { data: order, isLoading, refetch: refetchOrder } = useQuery({
    queryKey: ['sales-order', documentId],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${documentId}`)
      return response.data?.data ?? response.data
    },
    enabled: mode === 'edit' && !!documentId,
  })

  const { data: activePriceLists } = useQuery({
    queryKey: ['price-lists', 'active'],
    queryFn: () => pricingApi.listPriceLists({ active_only: true, per_page: 200 }),
    enabled: canViewPriceLists && mode === 'create',
  })
  const priceListRows: any[] = (activePriceLists as any)?.data ?? activePriceLists ?? []

  const { data: sellableCurrencies = [] } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
    enabled: mode === 'create',
  })

  const {
    currencyCode,
    setCurrencyCode,
    priceListId,
    setPriceListId,
    pricingError,
    resolvePricingForLines,
  } = useSalesDocumentPricing<SalesDocumentLine>({
    customerId,
    documentDate: orderDate,
    setLines,
  })

  useEffect(() => {
    if (!order) return
    setCustomerId(order.customer_id ? String(order.customer_id) : '')
    setOrderDate(dateOnly(order.order_date) || todayDateOnly())
    setRequiredDate(dateOnly(order.required_date))
    setCommitmentDate(dateOnly(order.commitment_date))
    setShippingPolicy(order.shipping_policy === 'when_all_ready' ? 'when_all_ready' : 'asap')
    setCustomerReference(order.customer_reference || '')
    setSalesperson(order.salesperson || '')
    setIncotermCode(order.incoterm_code || '')
    setIncotermPlace(order.incoterm_place || '')
    setNotes(order.notes || '')
    setInternalNotes(order.internal_notes || '')
    setDiscountMode('fixed')
    setDiscountAmount(order.discount_amount ? Number(order.discount_amount) : '')
    // The order's currency snapshot is fixed once created.
    setCurrencyCode(order.currency_code || '')

    const mapped = (order.lines || [])
      .slice()
      .sort((a: any, b: any) => Number(a.sequence ?? 0) - Number(b.sequence ?? 0))
      .map((line: any): SalesOrderFormLine => ({
        line_type: line.line_type || 'product',
        product_id: line.product_id ? String(line.product_id) : '',
        sku: line.product?.sku ?? line.sku ?? '',
        model_number: line.model_number || '',
        description: line.description || '',
        product_notes: line.product_notes || '',
        quantity: line.quantity != null ? Number(line.quantity) : 1,
        unit_price: line.unit_price != null ? Number(line.unit_price) : '',
        tax_rate: line.tax_rate != null ? Number(line.tax_rate) : '',
        discount_amount: line.discount_amount != null ? Number(line.discount_amount) : '',
        // A saved price is carried verbatim; the engine only re-prices lines
        // the user changes (FR-1.7).
        price_mode: (line.line_type ?? 'product') === 'product' && line.unit_price != null ? 'carried' : undefined,
        income_account_id: line.income_account_id ?? null,
        project_id: line.project_id ?? null,
        department_id: line.department_id ?? null,
        invoicing_policy: line.invoicing_policy ?? null,
      }))
    setLines(mapped.length > 0 ? mapped : [emptySalesDocumentLine()])
    setHydrated(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [order])

  // #317: unsaved-changes guard + local (this device only) draft autosave.
  // Only a draft order is editable here, so only then is anything guarded.
  const draftGuard = useDocumentDraftGuard({
    kind: 'sales-order',
    documentId: mode === 'edit' ? documentId : null,
    version: mode === 'edit' ? order?.lock_version ?? null : null,
    ready: mode === 'create' || (hydrated && order?.status === 'draft'),
    snapshot: {
      customerId, orderDate, requiredDate, commitmentDate, shippingPolicy, customerReference, salesperson,
      incotermCode, incotermPlace, notes, internalNotes, discountAmount, discountMode, lines,
      currencyCode, priceListId,
    },
    apply: (draft) => {
      setCustomerId(draft.customerId)
      setOrderDate(draft.orderDate)
      setRequiredDate(draft.requiredDate)
      setCommitmentDate(draft.commitmentDate)
      setShippingPolicy(draft.shippingPolicy)
      setCustomerReference(draft.customerReference)
      setSalesperson(draft.salesperson)
      setIncotermCode(draft.incotermCode)
      setIncotermPlace(draft.incotermPlace)
      setNotes(draft.notes)
      setInternalNotes(draft.internalNotes)
      setDiscountAmount(draft.discountAmount)
      setDiscountMode(draft.discountMode)
      setLines(draft.lines)
      setCurrencyCode(draft.currencyCode)
      setPriceListId(draft.priceListId)
    },
  })

  // A new order re-resolves engine prices when the customer changes (FR-2.3).
  useEffect(() => {
    if (mode !== 'create' || !customerId) return
    void resolvePricingForLines(lines)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [customerId])

  const createMutation = useMutation({
    mutationFn: async (payload: Record<string, any>) => {
      const response = await api.post('/sales/orders', payload)
      return response.data
    },
    onSuccess: (data) => {
      draftGuard.clearDraft()
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast({ title: 'Sales order created' })
      const id = data?.data?.id ?? data?.id
      navigate(id ? `/sales/orders/${id}` : '/sales/orders')
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Failed to create sales order', description: getApiErrorMessage(error) })
    },
  })

  const updateMutation = useMutation({
    mutationFn: async (payload: Record<string, any>) => {
      lastPayloadRef.current = payload
      const response = await api.put(`/sales/orders/${documentId}`, payload)
      return response.data
    },
    onSuccess: () => {
      setConflict(null)
      draftGuard.clearDraft()
      queryClient.invalidateQueries({ queryKey: ['sales-order', documentId] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      toast({ title: 'Sales order updated' })
      navigate(`/sales/orders/${documentId}`)
    },
    onError: (error: any, variables: Record<string, any>) => {
      const saveConflict = extractSaveConflict(error, variables ?? {})
      if (saveConflict) {
        setConflict(saveConflict)
        return
      }
      toast({ variant: 'destructive', title: 'Failed to update sales order', description: getApiErrorMessage(error) })
    },
  })

  const money = (value: number) => formatCurrency(value, currencyCode || undefined)
  const totals = computeDocumentTotals(lines, { mode: discountMode, amount: discountAmount })
  const discountValue = discountAmount === '' ? 0 : discountAmount

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()

    if (!customerId) {
      toast({ variant: 'destructive', title: 'Please select a customer' })
      return
    }

    const submissionLines = (lines as SalesOrderFormLine[])
      .filter((line) => !isBlankSalesDocumentLine(line))
      .filter((line) => line.description || line.product_id)

    if (submissionLines.length === 0) {
      toast({ variant: 'destructive', title: 'Please add at least one line item' })
      return
    }

    const missingProductIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !line.product_id,
    )
    if (missingProductIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Product required on line ${missingProductIndex + 1}`,
        description: 'Select a product before saving.',
      })
      return
    }

    const invalidQuantityIndex = submissionLines.findIndex((line) =>
      (line.line_type ?? 'product') === 'product' && !isValidProductQuantity(line.quantity),
    )
    if (invalidQuantityIndex >= 0) {
      toast({
        variant: 'destructive',
        title: `Invalid quantity on line ${invalidQuantityIndex + 1}`,
        description: 'Enter a quantity greater than 0 with at most 4 decimals.',
      })
      return
    }

    if (discountMode === 'percentage' && discountValue > 100) {
      toast({ variant: 'destructive', title: 'Percentage discount cannot exceed 100%' })
      return
    }

    const payload: Record<string, any> = {
      customer_id: parseInt(customerId, 10),
      required_date: requiredDate || null,
      commitment_date: commitmentDate || null,
      shipping_policy: shippingPolicy,
      customer_reference: customerReference || null,
      incoterm_code: incotermCode || null,
      incoterm_place: incotermPlace || null,
      notes: notes || null,
      internal_notes: internalNotes || null,
      // The order header stores a fixed amount; a percentage is resolved here
      // exactly like the quotation form does.
      discount_amount: totals.documentDiscountAmount || 0,
      lines: submissionLines.map((line, index) => ({
        line_type: line.line_type || 'product',
        sequence: index,
        product_id: line.product_id ? parseInt(line.product_id, 10) : null,
        model_number: line.model_number || null,
        description: line.description || 'Item',
        product_notes: line.product_notes || null,
        quantity: line.quantity === '' ? 0 : line.quantity,
        unit_price: line.unit_price === '' ? 0 : line.unit_price,
        // FR-6.2/FR-6.3: the server resolves auto lines through the engine
        // and runs the override guard on manual ones.
        price_mode: line.price_mode ?? undefined,
        override_reason: line.override_reason ?? undefined,
        tax_rate: line.tax_rate === '' ? 0 : line.tax_rate,
        discount_amount: line.discount_amount === '' ? 0 : line.discount_amount,
        ...(line.income_account_id ? { income_account_id: line.income_account_id } : {}),
        ...(line.project_id ? { project_id: line.project_id } : {}),
        ...(line.department_id ? { department_id: line.department_id } : {}),
        ...(line.invoicing_policy ? { invoicing_policy: line.invoicing_policy } : {}),
      })),
    }

    if (mode === 'edit') {
      payload.version = order?.lock_version ?? undefined
      updateMutation.mutate(payload)
      return
    }

    createMutation.mutate({
      ...payload,
      channel: 'admin',
      order_date: orderDate,
      salesperson: salesperson || null,
      ...(currencyCode ? { currency_code: currencyCode } : {}),
      ...(priceListId ? { price_list_id: Number(priceListId) } : {}),
    })
  }

  const handleReloadTheirs = () => {
    setConflict(null)
    void queryClient.invalidateQueries({ queryKey: ['sales-order', documentId] })
    void refetchOrder()
  }

  const handleOverwrite = () => {
    if (!conflict || !lastPayloadRef.current) return
    updateMutation.mutate({ ...lastPayloadRef.current, version: conflict.currentVersion })
  }

  if (mode === 'edit' && isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading sales order...</p>
        </div>
      </div>
    )
  }

  // Lines are editable only on a draft (#374); a confirmed order is amended
  // from its detail page under the #407 lock/amend rules.
  if (mode === 'edit' && order && order.status !== 'draft') {
    return (
      <div className="space-y-6 p-6">
        <PageHeader title="Edit Sales Order" backTo={`/sales/orders/${documentId}`} />
        <Alert variant="warning" data-testid="sales-order-not-editable">
          <Icon icon="solar:lock-keyhole-bold-duotone" className="h-4 w-4" />
          <AlertTitle>Only draft orders can be edited here</AlertTitle>
          <AlertDescription>
            {order.sales_order_number ?? 'This order'} is {String(order.status)}. Confirmed orders are amended from the
            order itself after unlocking; other states are read-only.
          </AlertDescription>
        </Alert>
        <Button variant="outline" onClick={() => navigate(`/sales/orders/${documentId}`)}>
          Back to order
        </Button>
      </div>
    )
  }

  const isPending = mode === 'edit' ? updateMutation.isPending : createMutation.isPending

  return (
    <div className={cn('space-y-6 p-6', SALES_FORM_CLASS)} data-density={density} {...draftGuard.trackProps}>
      <PageHeader
        title={mode === 'create' ? 'Create Sales Order' : 'Edit Sales Order'}
        description={
          mode === 'create'
            ? 'Create a draft sales order. Confirm it from the order to reserve stock.'
            : order?.sales_order_number
              ? `Editing ${order.sales_order_number}`
              : 'Update the draft sales order'
        }
        backTo={mode === 'create' ? '/sales/orders' : `/sales/orders/${documentId}`}
        guardBack={draftGuard.guard}
      />

      {mode === 'edit' && <EditingBanner activeEditor={order?.active_lock} currentUserId={currentUserId} />}

      <DocumentDraftBanner guard={draftGuard} label="sales order" />

      <form onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Order Details</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="grid grid-cols-2 items-end gap-3 md:grid-cols-4 xl:grid-cols-12">
              <div className="col-span-2 md:col-span-2 xl:col-span-6">
                <label className="mb-1 block text-xs font-medium">Customer *</label>
                <CustomerSearchCombobox
                  className={cn(SALES_CONTROL, 'justify-between')}
                  value={customerId}
                  onValueChange={(id, customer) => {
                    setCustomerId(id)
                    if (customer && (customer as any).code) {
                      setCustomerReference((current) => current || (customer as any).code)
                    }
                  }}
                  placeholder="Search customers..."
                />
              </div>
              <div className="col-span-1 xl:col-span-3">
                <label className="mb-1 block text-xs font-medium" htmlFor="so-order-date">Order Date</label>
                <input
                  id="so-order-date"
                  type="date"
                  value={orderDate}
                  disabled={mode === 'edit'}
                  onChange={(event) => {
                    setOrderDate(event.target.value)
                    void resolvePricingForLines(lines, { dateOverride: event.target.value })
                  }}
                  className={SALES_CONTROL}
                />
              </div>
              <div className="col-span-1 xl:col-span-3">
                <label className="mb-1 block text-xs font-medium" htmlFor="so-required-date">Required Date</label>
                <input
                  id="so-required-date"
                  type="date"
                  value={requiredDate}
                  onChange={(event) => setRequiredDate(event.target.value)}
                  className={SALES_CONTROL}
                />
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
              {mode === 'create' ? (
                <>
                  <label className={SALES_FIELD_SHELL}>
                    <span className="text-muted-foreground">Currency</span>
                    <select
                      aria-label="Document currency"
                      value={currencyCode}
                      onChange={(event) => {
                        setCurrencyCode(event.target.value)
                        void resolvePricingForLines(lines, { currencyOverride: event.target.value })
                      }}
                      className={SALES_FIELD_CONTROL}
                    >
                      <option value="">Auto</option>
                      {currencyCode && !(sellableCurrencies as any[]).some((c: any) => c.code === currencyCode) && (
                        <option value={currencyCode}>{currencyCode}</option>
                      )}
                      {(sellableCurrencies as any[]).map((currency: any) => (
                        <option key={currency.code} value={currency.code}>{currency.code}</option>
                      ))}
                    </select>
                  </label>
                  <label className={SALES_FIELD_SHELL}>
                    <span className="text-muted-foreground">List</span>
                    <select
                      aria-label="Price list"
                      value={priceListId}
                      onChange={(event) => {
                        setPriceListId(event.target.value)
                        void resolvePricingForLines(lines, { listOverride: event.target.value })
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
                </>
              ) : (
                <span className={SALES_FIELD_SHELL} title="The order's currency is fixed once created">
                  <span className="text-muted-foreground">Currency</span>
                  <span className="font-medium" data-testid="sales-order-currency">{currencyCode || '—'}</span>
                </span>
              )}
              <label className={SALES_FIELD_SHELL}>
                <span className="text-muted-foreground">Commitment</span>
                <input
                  type="date"
                  aria-label="Commitment Date"
                  value={commitmentDate}
                  onChange={(event) => setCommitmentDate(event.target.value)}
                  className={SALES_FIELD_CONTROL}
                />
              </label>
              <label className={SALES_FIELD_SHELL}>
                <span className="text-muted-foreground">Shipping</span>
                <select
                  aria-label="Shipping Policy"
                  value={shippingPolicy}
                  onChange={(event) => setShippingPolicy(event.target.value as 'asap' | 'when_all_ready')}
                  className={SALES_FIELD_CONTROL}
                >
                  <option value="asap">As soon as possible</option>
                  <option value="when_all_ready">When all products are ready</option>
                </select>
              </label>
            </div>

            {pricingError && (
              <p className="flex items-center gap-1 text-xs font-medium text-destructive">
                <Icon icon="solar:danger-triangle-bold" className="h-3.5 w-3.5" />
                {pricingError}
              </p>
            )}

            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div>
                <label className="mb-1 block text-xs font-medium" htmlFor="so-customer-ref">Customer Ref.</label>
                <input
                  id="so-customer-ref"
                  type="text"
                  value={customerReference}
                  maxLength={100}
                  onChange={(event) => setCustomerReference(event.target.value)}
                  className={SALES_CONTROL}
                  placeholder="PO / customer code"
                />
              </div>
              {mode === 'create' && (
                <div>
                  <label className="mb-1 block text-xs font-medium" htmlFor="so-salesperson">Salesperson</label>
                  <input
                    id="so-salesperson"
                    type="text"
                    value={salesperson}
                    onChange={(event) => setSalesperson(event.target.value)}
                    className={SALES_CONTROL}
                    placeholder="Defaults to you"
                  />
                </div>
              )}
              <div>
                <label className="mb-1 block text-xs font-medium" htmlFor="so-incoterm-code">Incoterm Code</label>
                <input
                  id="so-incoterm-code"
                  type="text"
                  value={incotermCode}
                  maxLength={20}
                  onChange={(event) => setIncotermCode(event.target.value)}
                  className={SALES_CONTROL}
                  placeholder="e.g. FOB, CIF, DDP"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium" htmlFor="so-incoterm-place">Incoterm Place</label>
                <input
                  id="so-incoterm-place"
                  type="text"
                  value={incotermPlace}
                  maxLength={255}
                  onChange={(event) => setIncotermPlace(event.target.value)}
                  className={SALES_CONTROL}
                  placeholder="e.g. Port Klang"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Line Items</CardTitle>
          </CardHeader>
          <CardContent>
            <SalesDocumentLineGrid
              lines={lines}
              setLines={setLines}
              money={money}
              resolvePricing={(nextLines) => { void resolvePricingForLines(nextLines) }}
              defaultHiddenColumns={[6]}
            />

            <DocumentTotalsCard
              subtotal={totals.grossSubtotal}
              tax={totals.taxTotal}
              total={totals.total}
              money={money}
              discountLabel="Discount"
              discountMode={discountMode}
              onDiscountModeChange={setDiscountMode}
              discountAmount={discountAmount}
              onDiscountAmountChange={setDiscountAmount}
              lineDiscounts={totals.lineDiscounts}
              documentDiscountAmount={totals.documentDiscountAmount}
            />
          </CardContent>
        </Card>

        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Notes</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs font-medium" htmlFor="so-notes">Notes (printed)</label>
              <textarea
                id="so-notes"
                rows={3}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                className={SALES_TEXTAREA}
                placeholder="Notes for the customer..."
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium" htmlFor="so-internal-notes">Internal notes</label>
              <textarea
                id="so-internal-notes"
                rows={3}
                value={internalNotes}
                onChange={(event) => setInternalNotes(event.target.value)}
                className={SALES_TEXTAREA}
                placeholder="Internal notes..."
              />
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button
            type="button"
            variant="outline"
            onClick={() => draftGuard.guard(() => navigate(mode === 'edit' ? `/sales/orders/${documentId}` : '/sales/orders'))}
          >
            Cancel
          </Button>
          <Button type="submit" disabled={isPending}>
            <Icon icon="solar:check-circle-bold-duotone" className="mr-2 h-4 w-4" />
            {isPending
              ? (mode === 'edit' ? 'Saving...' : 'Creating...')
              : (mode === 'edit' ? 'Save Changes' : 'Create Sales Order')}
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

      <DocumentLeaveDialog guard={draftGuard} />
    </div>
  )
}

export default SalesOrderDocumentForm
