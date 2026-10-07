import React, { forwardRef, useImperativeHandle, useLayoutEffect, useRef, useState } from 'react'
import { Icon } from '@iconify/react'
import { cn, normalizeQuantity } from '@/lib/utils'
import { useMediaQuery } from '@/hooks/useBreakpoint'
import { Button } from '@/components/ui/button'
import { getProductLineDescription, ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import { resolveLineTaxRate } from '@/lib/lineTax'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { DocumentTextLineRow, getDocumentSectionTotal, reorderDocumentLines } from '@/components/sales/DocumentTextLineRow'
import { LineItemsTable } from '@/components/sales/LineItemsTable'
import { SalesLineColumnMenu, SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { SALES_RADIUS } from '@/components/sales/salesDesignTokens'
import {
  LINE_CELL,
  LINE_CELL_BOX,
  LINE_CELL_TIGHT,
  LINE_INPUT,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  ProductLineActions,
  productLineColumns,
} from '@/components/sales/ProductLineTable'
import {
  CREDIT_QUANTITY_EPSILON,
  creditableQuantity,
  duplicateSalesDocumentLine,
  emptySalesDocumentLine,
  exceedsCreditableQuantity,
  getSalesDocumentLineTotal,
  isBlankSalesDocumentLine,
  moveSalesDocumentLine,
  removeSalesDocumentLine,
  resetSalesDocumentLine,
  withCreditQuantity,
  type SalesDocumentLine,
} from '@/lib/salesDocumentLines'
import type { LineItemsColumn } from '@/components/sales/LineItemsTable'
import { lineFieldKey } from '@/lib/serverValidation'
import { FieldSlot } from '@/components/ui/field-errors'
import { useFieldErrorsContext } from '@/hooks/useFieldErrors'
import { EditorLinePriceChip, type EditorPriceContext } from '@/components/sales/PriceExplanationChip'

/**
 * Issue #314: one line grid for every sales-document editor.
 *
 * Before this, QuotationCreatePage, QuotationEditPage and CreateInvoicePage
 * each owned a copy of the row markup and the add/duplicate/move/reset
 * handlers, which is how the create page lost the discount column and the
 * edit page lost the SKU column. The grid owns the row markup, the drag
 * reorder, the column visibility menu and the line mutations; callers supply
 * the lines state and an optional pricing resolver.
 *
 * Issue #323 (§8.3): the grid follows the shared sales-form tokens (one
 * `rounded-lg` radius on every control) and, below 768px, renders each line
 * as a stacked card instead of the 1,200px-wide table.
 *
 * Issue #319: keyboard-first entry. Tab walks product -> qty -> price
 * (-> discount) and Enter on the last cell moves to the next row, adding one
 * when needed. An empty row is always present at the end: the starter/added
 * blank row (ignored on save by `isBlankSalesDocumentLine`) or, once the last
 * line has content, an entry row that is not part of the lines state and
 * becomes a real line when a product is picked on it. Typing or scanning a
 * SKU/barcode on a product cell picks the exact match (ProductSearchCombobox
 * `keyboardEntry`).
 *
 * Issue #526 (CN-UX5): `credit` mode for credit notes. The lines are the
 * invoice's lines (each carrying `credit` info); the grid adds the Invoiced,
 * Already credited and To credit columns, caps the quantity at what is still
 * creditable (#522/#513), keeps the shared line-total calculation and the
 * keyboard flow (Tab qty -> price, Enter -> next creditable line), and drops
 * product picking, new lines and row actions.
 */

/** Focusable line cells, in keyboard (Tab/Enter) order. */
type LineFlowCell = 'product_id' | 'quantity' | 'unit_price' | 'discount_amount'

export interface SalesDocumentLineGridHandle {
  /** Focus the product cell of the first product row without a product (line 1 on a new document). */
  focusFirstOpenProduct: () => void
}

/** Matches the `@media (max-width: 767px)` card breakpoint in index.css. */
export const SALES_LINE_CARDS_MEDIA_QUERY = '(max-width: 767px)'

// #323: the shared ProductLineTable constants keep their own radius for the
// other documents; the sales document grid overrides to the single token.
const LINE_INPUT_TOKEN = cn(LINE_INPUT, SALES_RADIUS)
const LINE_INPUT_NUMERIC_TOKEN = cn(LINE_INPUT_NUMERIC, SALES_RADIUS)
const LINE_TEXTAREA_TOKEN = cn(LINE_TEXTAREA, SALES_RADIUS)
const LINE_VALUE_TOKEN = cn(LINE_VALUE, SALES_RADIUS)

export interface SalesDocumentLineGridProps {
  lines: SalesDocumentLine[]
  setLines: React.Dispatch<React.SetStateAction<SalesDocumentLine[]>>
  /** Optional in-memory products for the legacy product_id lookup; the catalogue searches the server (#301). */
  products?: any[]
  money: (value: number) => string
  /** Called after every change that must reprice auto lines through the engine. */
  resolvePricing?: (nextLines: SalesDocumentLine[]) => void
  showSku?: boolean
  showDiscount?: boolean
  showTaxRate?: boolean
  showModelNumber?: boolean
  showProductNotes?: boolean
  defaultTaxRate?: number | null
  defaultHiddenColumns?: number[]
  tableClass?: string
  tableMinWidth?: string
  /** Extra action buttons (Repeat last invoice, Recalculate, ...). */
  extraActions?: React.ReactNode
  /** #526: credit-note mode; `readOnly` shows the quantities/prices as text (full credit). */
  credit?: { readOnly?: boolean }
  /** #342 (D5): document FX snapshot for the per-line price explanation. */
  priceContext?: EditorPriceContext | null
}

/** #526: credit-mode columns — the invoice line plus what it allows to be credited. */
function creditLineColumns(options: { taxRate: boolean; discount: boolean }): LineItemsColumn[] {
  return [
    { key: 'index', label: '#', align: 'center', minWidth: 'w-10', className: 'w-10' },
    { key: 'description', label: 'Description', minWidth: 'min-w-[14rem]' },
    { key: 'invoiced', label: 'Invoiced', align: 'right', minWidth: 'min-w-[5rem]' },
    { key: 'credited', label: 'Already credited', align: 'right', minWidth: 'min-w-[6rem]' },
    { key: 'qty', label: 'To credit', align: 'right', minWidth: 'min-w-[6rem]' },
    { key: 'unit_price', label: 'Unit Price', align: 'right', minWidth: 'min-w-[7rem]' },
    { key: 'tax_rate', label: 'Tax %', align: 'right', minWidth: 'min-w-[4rem]', hidden: !options.taxRate },
    { key: 'discount', label: 'Disc', align: 'right', minWidth: 'min-w-[5rem]', hidden: !options.discount },
    { key: 'line_total', label: 'Line Total', align: 'right', minWidth: 'min-w-[7rem]' },
  ]
}

const fmtCreditQty = (value: number) => String(Number(value.toFixed(4)))

export const SalesDocumentLineGrid = forwardRef<SalesDocumentLineGridHandle, SalesDocumentLineGridProps>(function SalesDocumentLineGrid({
  lines,
  setLines,
  products,
  money,
  resolvePricing,
  showSku = true,
  showDiscount = true,
  showTaxRate = true,
  showModelNumber = true,
  showProductNotes = true,
  defaultTaxRate,
  defaultHiddenColumns = [],
  tableClass = 'sales-document-columns',
  tableMinWidth = 'min-w-[1200px]',
  extraActions,
  credit,
  priceContext,
}, ref) {
  const isCredit = !!credit
  const creditReadOnly = !!credit?.readOnly
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(defaultHiddenColumns)
  const isNarrow = useMediaQuery(SALES_LINE_CARDS_MEDIA_QUERY)
  // #318: inline line errors are keyed by row — a move/insert/delete makes
  // them point at the wrong row, so those drop the line errors.
  const fieldErrors = useFieldErrorsContext()
  const restructure = (next: SalesDocumentLine[]) => {
    fieldErrors?.clearLines()
    setLines(next)
  }
  const emptyLine = (): SalesDocumentLine => emptySalesDocumentLine()
  const getLineTotal = (line: SalesDocumentLine) => getSalesDocumentLineTotal(line)

  const columns = isCredit
    ? creditLineColumns({ taxRate: showTaxRate, discount: showDiscount })
    : productLineColumns({
        sku: showSku,
        description: true,
        modelNumber: showModelNumber,
        productNotes: showProductNotes,
        taxRate: showTaxRate,
        discount: showDiscount,
      })
  const menuColumns = columns.map((column, index) => ({
    label: String(column.label ?? '') || 'Actions',
    index: index + 1,
  }))
  const visibleColumns = columns.filter((column) => !column.hidden)
  const visibleColumnCount = visibleColumns.length

  // #323: the column menu hides columns by their position among the visible
  // ones (the CSS uses `th:nth-child`); the stacked cards mirror that.
  const columnVisible = (key: string) => {
    const position = visibleColumns.findIndex((column) => column.key === key) + 1
    return position > 0 && !hiddenLineColumns.includes(position)
  }

  // #319: keyboard flow. Cells are found through their #318 FieldSlot keys.
  const containerRef = useRef<HTMLDivElement>(null)
  const pendingFocusRef = useRef<{ row: number; cell: LineFlowCell } | null>(null)
  // #526: credit lines keep their invoice product/discount — only qty and price are entered.
  const flowCells: LineFlowCell[] = isCredit
    ? (creditReadOnly ? [] : ['quantity', 'unit_price'])
    : [
        'product_id',
        'quantity',
        'unit_price',
        ...(showDiscount && columnVisible('discount') ? (['discount_amount'] as const) : []),
      ]
  const isCreditableRow = (line?: SalesDocumentLine) =>
    !!line?.credit && creditableQuantity(line.credit) > CREDIT_QUANTITY_EPSILON
  const isProductRow = (line?: SalesDocumentLine) => !!line && (line.line_type ?? 'product') === 'product'

  const focusCell = (row: number, cell: LineFlowCell): boolean => {
    const slot = containerRef.current?.querySelector(`[data-field-key="${lineFieldKey(row, cell)}"]`)
    const control = slot?.querySelector<HTMLElement>(cell === 'product_id' ? 'button[role="combobox"]' : 'input')
    if (!control) return false
    control.focus()
    return true
  }

  /** Focus a cell now, or after the next render when the row is being added. */
  const focusCellSoon = (row: number, cell: LineFlowCell) => {
    if (!focusCell(row, cell)) pendingFocusRef.current = { row, cell }
  }

  useLayoutEffect(() => {
    const pending = pendingFocusRef.current
    if (pending && focusCell(pending.row, pending.cell)) pendingFocusRef.current = null
  })

  // #319: the entry row stands in for the empty row at the end whenever the
  // last line has content; it lives at index `lines.length`.
  const entryIndex = lines.length
  const showEntryRow = !isCredit && (lines.length === 0 || !isBlankSalesDocumentLine(lines[lines.length - 1]))
  // Row a pick on the entry row turned into (the entry row then moves down).
  const entryPickedRowRef = useRef<number | null>(null)

  useImperativeHandle(ref, () => ({
    focusFirstOpenProduct: () => {
      const row = lines.findIndex((line) => isProductRow(line) && !line.product_id)
      focusCellSoon(row >= 0 ? row : entryIndex, 'product_id')
    },
  }))

  /** Enter on a row's last cell: the next product row, or a new empty one. */
  const goToNextRow = (index: number) => {
    if (isCredit) {
      // #526: credit lines are the invoice's lines — move on, never add one.
      const next = lines.findIndex((line, row) => row > index && isCreditableRow(line))
      if (next >= 0) focusCell(next, 'quantity')
      return
    }
    if (isProductRow(lines[index + 1])) {
      focusCell(index + 1, 'product_id')
      return
    }
    const next = [...lines]
    next.splice(index + 1, 0, emptyLine())
    pendingFocusRef.current = { row: index + 1, cell: 'product_id' }
    restructure(next)
  }

  const handleFlowKeyDown = (index: number, cell: LineFlowCell) => (event: React.KeyboardEvent<HTMLElement>) => {
    if (event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return
    const target = event.target as HTMLElement
    // The product popover is portalled but its events bubble through React.
    if (cell === 'product_id' ? target.getAttribute('role') !== 'combobox' : target.tagName !== 'INPUT') return
    const position = flowCells.indexOf(cell)
    if (position < 0) return

    if (event.key === 'Tab') {
      const nextPosition = event.shiftKey ? position - 1 : position + 1
      if (nextPosition < 0 || nextPosition >= flowCells.length) return // leave the row normally
      if (focusCell(index, flowCells[nextPosition])) event.preventDefault()
      return
    }

    // Enter on the product cell keeps opening the search / picking.
    if (event.key === 'Enter' && cell !== 'product_id' && !event.shiftKey) {
      event.preventDefault()
      if (position < flowCells.length - 1) {
        focusCell(index, flowCells[position + 1])
      } else {
        goToNextRow(index)
      }
    }
  }

  const productLineNumber = (index: number) =>
    lines.slice(0, index + 1).filter((item) => (item.line_type ?? 'product') === 'product').length

  const updateLine = (index: number, field: keyof SalesDocumentLine, value: string | number) => {
    const updated = lines.map((line, currentIndex) =>
      currentIndex === index ? { ...line, [field]: value } : line,
    )
    const target = updated[index]
    if (!target) return

    if (field === 'product_id' && value && products) {
      const product = products.find((item: any) => String(item.id) === String(value))
      if (product) {
        updated[index] = {
          ...target,
          description: getProductLineDescription(product),
          unit_price: parseFloat(product.price || product.selling_price || '0') || 0,
          model_number: product.manufacturer_part_number || '',
          sku: product.sku || '',
          price_mode: 'auto',
          // #342: the previous product's trace no longer explains this line.
          pricing_trace: null,
          list_price: null,
          tax_rate: resolveLineTaxRate(product, defaultTaxRate),
        }
        setLines(updated)
        resolvePricing?.(updated)
        return
      }
    }

    if (field === 'unit_price') {
      updated[index] = { ...updated[index], price_mode: 'manual' }
    }

    // #526: a credit line's discount share follows its quantity to credit.
    if (field === 'quantity' && updated[index].credit) {
      updated[index] = withCreditQuantity(updated[index], value as number | '')
    }

    setLines(updated)
    // Qty changes re-evaluate auto lines against quantity tiers (FR-6.2).
    if (field === 'quantity' && updated[index].price_mode === 'auto') {
      resolvePricing?.(updated)
    }
  }

  const pickProduct = (index: number, value: string, product?: any) => {
    // #319: a pick on the entry row adds it as a real line.
    if (index >= lines.length) {
      if (!product) return
      const base = [...lines, emptyLine()]
      const updated = base.map((line, currentIndex) => currentIndex === index
        ? {
            ...line,
            product_id: value,
            description: getProductLineDescription(product),
            unit_price: parseFloat(product.price || product.selling_price || '0') || 0,
            price_mode: 'auto' as const,
            model_number: product.manufacturer_part_number || '',
            sku: product.sku || '',
            tax_rate: resolveLineTaxRate(product, defaultTaxRate),
          }
        : line)
      entryPickedRowRef.current = index
      setLines(updated)
      resolvePricing?.(updated)
      return
    }
    if (!product) {
      fieldErrors?.clear(lineFieldKey(index, 'product_id'))
      updateLine(index, 'product_id', value)
      return
    }
    fieldErrors?.clear(lineFieldKey(index, 'product_id'))
    const productChanged = value !== lines[index]?.product_id
    const updated = lines.map((line, currentIndex) =>
      currentIndex === index
        ? {
            ...line,
            product_id: value,
            // Re-price from the NEW product; only a no-op re-select of the
            // same product keeps edits.
            description: productChanged
              ? getProductLineDescription(product)
              : (line.description || getProductLineDescription(product)),
            unit_price: productChanged
              ? (parseFloat(product.price || product.selling_price || '0') || 0)
              : line.unit_price,
            price_mode: productChanged ? ('auto' as const) : line.price_mode,
            // #342: the previous product's trace no longer explains this line.
            ...(productChanged ? { pricing_trace: null, list_price: null } : {}),
            model_number: product.manufacturer_part_number || (productChanged ? '' : line.model_number),
            sku: product.sku || (productChanged ? '' : line.sku),
            tax_rate: productChanged ? resolveLineTaxRate(product, defaultTaxRate) : line.tax_rate,
          }
        : line,
    )
    setLines(updated)
    // A row product-pick must price through the engine — the catalogue number
    // is only a first paint, the list/FX resolution is authoritative.
    resolvePricing?.(updated)
  }

  const addLine = () => {
    // #319: an untouched last row already is the new line — focus it.
    const last = lines.length - 1
    if (last >= 0 && isBlankSalesDocumentLine(lines[last])) {
      focusCell(last, 'product_id')
      return
    }
    pendingFocusRef.current = { row: lines.length, cell: 'product_id' }
    setLines([...lines, emptyLine()])
  }
  const addTextLine = (line_type: 'note' | 'section') =>
    setLines([...lines, { ...emptyLine(), line_type, quantity: 0, unit_price: 0, tax_rate: 0, discount_amount: 0 }])
  const addCatalogProducts = (selected: Array<{ product: any; quantity: number }>) => {
    // #290: replace the lone untouched starter row instead of appending after it.
    const next: SalesDocumentLine[] = lines.length === 1 && isBlankSalesDocumentLine(lines[0])
      ? []
      : [...lines]
    selected.forEach(({ product, quantity }) => {
      const existingIndex = next.findIndex(
        (line) => line.line_type === 'product' && line.product_id === String(product.id),
      )
      if (existingIndex >= 0) {
        // #288(d): never mutate state in place — build a new object.
        next[existingIndex] = {
          ...next[existingIndex],
          quantity: Number(next[existingIndex].quantity || 0) + quantity,
        }
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
          tax_rate: resolveLineTaxRate(product, defaultTaxRate),
        })
      }
    })
    restructure(next)
    // #288(a): catalogue additions must price through the engine too.
    resolvePricing?.(next)
  }

  const handleDrop = (index: number) => {
    if (draggedLineIndex !== null && draggedLineIndex !== index) {
      restructure(reorderDocumentLines(lines, draggedLineIndex, index))
    }
    setDraggedLineIndex(null)
  }

  const lineActions = (index: number, canMoveDown: boolean) => (
    <ProductLineActions
      onMoveUp={() => restructure(moveSalesDocumentLine(lines, index, 'up'))}
      onMoveDown={() => restructure(moveSalesDocumentLine(lines, index, 'down'))}
      canMoveUp={index !== 0}
      canMoveDown={canMoveDown}
      onDuplicate={() => restructure(duplicateSalesDocumentLine(lines, index))}
      onReset={() => restructure(resetSalesDocumentLine(lines, index, emptyLine()))}
      onDelete={() => restructure(removeSalesDocumentLine(lines, index))}
    />
  )

  const renderTextCard = (line: SalesDocumentLine, index: number) => (
    <div
      key={index}
      data-testid="sales-line-card"
      className={cn(
        'sales-line-card flex items-center gap-2 rounded-lg border p-3',
        line.line_type === 'section' ? 'bg-muted/60' : 'bg-muted/30',
      )}
      onDragOver={(event) => event.preventDefault()}
      onDrop={(event) => {
        event.preventDefault()
        event.stopPropagation()
        handleDrop(index)
      }}
    >
      <span
        draggable
        onDragStart={() => setDraggedLineIndex(index)}
        className="cursor-grab active:cursor-grabbing"
        title="Drag to reorder"
      >
        <Icon icon="solar:hamburger-menu-linear" className="h-5 w-5 text-muted-foreground" />
      </span>
      <input
        className={cn(
          'min-w-0 flex-1 border-0 bg-transparent p-2 text-sm outline-none',
          line.line_type === 'section' ? 'font-semibold' : 'italic text-muted-foreground',
        )}
        placeholder={line.line_type === 'section' ? 'Section title' : 'Note text'}
        value={line.description}
        onChange={(event) => updateLine(index, 'description', event.target.value)}
      />
      {line.line_type === 'section' && (
        <span className="whitespace-nowrap px-2 text-right text-sm font-semibold tabular-nums">
          {money(getDocumentSectionTotal(lines, index, getLineTotal))}
        </span>
      )}
      <Button
        type="button"
        variant="ghost"
        size="sm"
        aria-label="Delete line"
        onClick={() => restructure(removeSalesDocumentLine(lines, index))}
      >
        <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
      </Button>
    </div>
  )

  const renderProductCard = (line: SalesDocumentLine, index: number) => (
    <div
      key={index}
      data-testid="sales-line-card"
      className="sales-line-card space-y-2 rounded-lg border bg-card p-3 shadow-sm"
      draggable
      onDragStart={() => setDraggedLineIndex(index)}
      onDragOver={(event) => event.preventDefault()}
      onDrop={() => handleDrop(index)}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-medium text-muted-foreground">Line {productLineNumber(index)}</span>
        <span className="text-sm font-semibold tabular-nums">{money(getLineTotal(line))}</span>
      </div>

      <FieldSlot name={lineFieldKey(index, 'product_id')}>
        <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Product</label>
        <div onKeyDown={handleFlowKeyDown(index, 'product_id')}>
          <ProductSearchCombobox
            value={line.product_id}
            onValueChange={(val, product) => pickProduct(index, val, product)}
            placeholder="Search product..."
            wrapText
            keyboardEntry
            focusAfterSelect={() => focusCell(index, 'quantity')}
          />
        </div>
      </FieldSlot>

      {columnVisible('sku') && (
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">SKU</label>
          <div className={LINE_VALUE_TOKEN}>{line.sku || <span className="opacity-60">SKU</span>}</div>
        </div>
      )}

      <FieldSlot name={lineFieldKey(index, 'description')}>
        <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Description</label>
        <textarea
          value={line.description}
          onChange={(event) => updateLine(index, 'description', event.target.value)}
          rows={2}
          className={LINE_TEXTAREA_TOKEN}
          placeholder="Description"
          required
        />
      </FieldSlot>

      {columnVisible('model_number') && (
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Model #</label>
          <input
            type="text"
            value={line.model_number}
            onChange={(event) => updateLine(index, 'model_number', event.target.value)}
            className={LINE_INPUT_TOKEN}
            placeholder="Model #"
          />
        </div>
      )}

      {columnVisible('product_notes') && (
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Product notes</label>
          <textarea
            value={line.product_notes}
            onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
            rows={2}
            className={LINE_TEXTAREA_TOKEN}
            placeholder="Product notes"
          />
        </div>
      )}

      <div className="grid grid-cols-2 gap-2">
        <FieldSlot name={lineFieldKey(index, 'quantity')}>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Qty</label>
          <input
            type="number"
            min="0"
            aria-label="Quantity"
            onKeyDown={handleFlowKeyDown(index, 'quantity')}
            value={line.quantity}
            onChange={(event) => {
              // HR71-08: keep literal intent — empty is allowed while typing
              // (caught on submit), no silent substitution of 0.
              const raw = event.target.value
              if (raw === '') { updateLine(index, 'quantity', ''); return }
              const parsed = parseFloat(raw)
              if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
            }}
            onBlur={() => {
              if (line.quantity !== '') updateLine(index, 'quantity', normalizeQuantity(line.quantity) as number)
            }}
            className={LINE_INPUT_NUMERIC_TOKEN}
          />
        </FieldSlot>
        <FieldSlot name={lineFieldKey(index, 'unit_price')}>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Unit price</label>
          <input
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            aria-label="Unit price"
            onKeyDown={handleFlowKeyDown(index, 'unit_price')}
            value={line.unit_price === '' ? '' : line.unit_price}
            onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
            className={LINE_INPUT_NUMERIC_TOKEN}
          />
          <EditorLinePriceChip line={line} context={priceContext} money={money} className="mt-0.5" />
        </FieldSlot>
        {columnVisible('tax_rate') && (
          <FieldSlot name={lineFieldKey(index, 'tax_rate')}>
            <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Tax %</label>
            <input
              type="number"
              min="0"
              max="100"
              step="0.01"
              placeholder="0"
              aria-label="Tax rate"
              value={line.tax_rate === '' ? '' : line.tax_rate}
              onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
              className={LINE_INPUT_NUMERIC_TOKEN}
            />
          </FieldSlot>
        )}
        {columnVisible('discount') && (
          <FieldSlot name={lineFieldKey(index, 'discount_amount')}>
            <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Disc</label>
            <input
              type="number"
              min="0"
              step="0.01"
              placeholder="0.00"
              aria-label="Line discount"
              onKeyDown={handleFlowKeyDown(index, 'discount_amount')}
              value={line.discount_amount === '' ? '' : line.discount_amount}
              onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
              className={LINE_INPUT_NUMERIC_TOKEN}
            />
          </FieldSlot>
        )}
      </div>

      <div className="flex items-center justify-between gap-2">
        <span className="text-xs text-muted-foreground">Line total</span>
        <span className="text-sm font-semibold tabular-nums">{money(getLineTotal(line))}</span>
      </div>

      <div className="flex justify-end">{lineActions(index, index !== lines.length - 1)}</div>
    </div>
  )

  // #526: credit-mode cells (shared by the table row and the stacked card).
  const creditQuantityInput = (line: SalesDocumentLine, index: number) => {
    const info = line.credit!
    const remaining = creditableQuantity(info)
    const fullyCredited = remaining <= CREDIT_QUANTITY_EPSILON
    if (creditReadOnly) {
      return <span className="text-sm tabular-nums">{fullyCredited ? 'Fully credited' : fmtCreditQty(Number(line.quantity) || 0)}</span>
    }
    const tooMuch = exceedsCreditableQuantity(line)
    return (
      <div>
        <input
          type="number"
          min="0"
          max={remaining}
          step="0.01"
          aria-label={`Quantity to credit for ${line.description}`}
          aria-invalid={tooMuch || undefined}
          disabled={fullyCredited}
          onKeyDown={handleFlowKeyDown(index, 'quantity')}
          value={line.quantity}
          onChange={(event) => {
            const raw = event.target.value
            if (raw === '') { updateLine(index, 'quantity', ''); return }
            const parsed = parseFloat(raw)
            if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
          }}
          onBlur={() => {
            if (line.quantity !== '') updateLine(index, 'quantity', normalizeQuantity(line.quantity) as number)
          }}
          className={cn(LINE_INPUT_NUMERIC_TOKEN, tooMuch && 'border-destructive')}
        />
        {tooMuch && <p className="mt-1 text-xs text-destructive">Max {fmtCreditQty(remaining)}</p>}
      </div>
    )
  }

  const creditPriceInput = (line: SalesDocumentLine, index: number) => {
    if (creditReadOnly) {
      return <span className="text-sm tabular-nums">{money(Number(line.unit_price) || 0)}</span>
    }
    return (
      <input
        type="number"
        min="0"
        step="0.01"
        aria-label={`Unit price for ${line.description}`}
        disabled={!isCreditableRow(line)}
        onKeyDown={handleFlowKeyDown(index, 'unit_price')}
        value={line.unit_price === '' ? '' : line.unit_price}
        onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
        className={LINE_INPUT_NUMERIC_TOKEN}
      />
    )
  }

  const renderCreditRow = (line: SalesDocumentLine, index: number) => {
    const info = line.credit!
    return (
      <tr
        key={info.invoice_line_id}
        className={cn('doc-line-row border-b transition-colors hover:bg-muted/20', !isCreditableRow(line) && 'text-muted-foreground')}
      >
        <td data-label="#" className={cn(LINE_CELL, 'w-10')}>
          <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>{index + 1}</div>
        </td>
        <td data-label="Description" className={cn(LINE_CELL, 'min-w-[14rem]')}>
          <div className={cn(LINE_CELL_BOX, 'text-sm')}>{line.description}</div>
        </td>
        <td data-label="Invoiced" aria-label="Invoiced" className={LINE_CELL_TIGHT}>{fmtCreditQty(info.invoiced_quantity)}</td>
        <td data-label="Already credited" aria-label="Already credited" className={LINE_CELL_TIGHT}>{fmtCreditQty(info.credited_quantity)}</td>
        <td data-label="To credit" className={cn(LINE_CELL, 'text-right')}>
          <FieldSlot name={lineFieldKey(index, 'quantity')}>{creditQuantityInput(line, index)}</FieldSlot>
        </td>
        <td data-label="Unit price" className={cn(LINE_CELL, 'text-right')}>
          <FieldSlot name={lineFieldKey(index, 'unit_price')}>{creditPriceInput(line, index)}</FieldSlot>
        </td>
        {showTaxRate && (
          <td data-label="Tax %" className={LINE_CELL_TIGHT}>{line.tax_inclusive ? `${line.tax_rate}% incl.` : `${line.tax_rate}%`}</td>
        )}
        {showDiscount && (
          <td data-label="Disc" aria-label="Line discount" className={LINE_CELL_TIGHT}>{money(Number(line.discount_amount) || 0)}</td>
        )}
        <td data-label="Total" aria-label="Line total" className={cn(LINE_CELL_TIGHT, 'font-semibold')}>{money(getLineTotal(line))}</td>
      </tr>
    )
  }

  const renderCreditCard = (line: SalesDocumentLine, index: number) => {
    const info = line.credit!
    return (
      <div
        key={info.invoice_line_id}
        data-testid="sales-line-card"
        className={cn('sales-line-card space-y-2 rounded-lg border bg-card p-3 shadow-sm', !isCreditableRow(line) && 'text-muted-foreground')}
      >
        <div className="flex items-center justify-between gap-2">
          <span className="text-sm font-medium">{line.description}</span>
          <span aria-label="Line total" className="text-sm font-semibold tabular-nums">{money(getLineTotal(line))}</span>
        </div>
        <div className="grid grid-cols-2 gap-2 text-xs">
          <div>Invoiced: <span aria-label="Invoiced">{fmtCreditQty(info.invoiced_quantity)}</span></div>
          <div>Already credited: <span aria-label="Already credited">{fmtCreditQty(info.credited_quantity)}</span></div>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <FieldSlot name={lineFieldKey(index, 'quantity')}>
            <label className="mb-0.5 block text-xs font-medium text-muted-foreground">To credit</label>
            {creditQuantityInput(line, index)}
          </FieldSlot>
          <FieldSlot name={lineFieldKey(index, 'unit_price')}>
            <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Unit price</label>
            {creditPriceInput(line, index)}
          </FieldSlot>
        </div>
        <div className="flex justify-between text-xs text-muted-foreground">
          {showTaxRate && <span>Tax {line.tax_rate}%{line.tax_inclusive ? ' incl.' : ''}</span>}
          {showDiscount && <span>Disc <span aria-label="Line discount">{money(Number(line.discount_amount) || 0)}</span></span>}
        </div>
      </div>
    )
  }

  if (isCredit) {
    return (
      <div ref={containerRef} className="contents">
        {isNarrow ? (
          <div className="space-y-3" data-testid="sales-line-cards">
            {lines.map((line, index) => (line.credit ? renderCreditCard(line, index) : null))}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <SalesLineColumnVisibility
              tableClass={tableClass}
              columns={menuColumns}
              hiddenColumns={hiddenLineColumns}
              onHiddenColumnsChange={setHiddenLineColumns}
              showMenu={false}
            >
              <LineItemsTable
                variant="raw"
                tableClassName={cn('doc-lines-table w-full text-sm', tableMinWidth)}
                headRowClassName="border-b bg-muted/30"
                columns={columns}
              >
                {lines.map((line, index) => (line.credit ? renderCreditRow(line, index) : null))}
              </LineItemsTable>
            </SalesLineColumnVisibility>
          </div>
        )}
      </div>
    )
  }

  const entryProductPicker = (
    <FieldSlot name={lineFieldKey(entryIndex, 'product_id')}>
      <ProductSearchCombobox
        value=""
        onValueChange={(val, product) => pickProduct(entryIndex, val, product)}
        placeholder="Add a line: search or scan SKU / barcode..."
        wrapText
        keyboardEntry
        focusAfterSelect={() => {
          const row = entryPickedRowRef.current
          entryPickedRowRef.current = null
          if (row !== null) focusCellSoon(row, 'quantity')
          else focusCell(entryIndex, 'product_id')
        }}
      />
    </FieldSlot>
  )

  return (
    <div ref={containerRef} className="contents">
      {isNarrow ? (
        <div className="space-y-3" data-testid="sales-line-cards">
          {lines.map((line, index) =>
            line.line_type === 'note' || line.line_type === 'section'
              ? renderTextCard(line, index)
              : renderProductCard(line, index),
          )}
          {showEntryRow && (
            <div data-testid="sales-line-entry" className="rounded-lg border border-dashed p-3">
              <label className="mb-0.5 block text-xs font-medium text-muted-foreground">New line</label>
              {entryProductPicker}
            </div>
          )}
        </div>
      ) : (
        <div className="overflow-x-auto">
          <SalesLineColumnVisibility
            tableClass={tableClass}
            columns={menuColumns}
            hiddenColumns={hiddenLineColumns}
            onHiddenColumnsChange={setHiddenLineColumns}
            showMenu={false}
          >
            <LineItemsTable
              variant="raw"
              tableClassName={cn('doc-lines-table w-full text-sm', tableMinWidth)}
              headRowClassName="border-b bg-muted/30"
              columns={columns}
              footer={showEntryRow ? (
                <tr data-testid="sales-line-entry" className="doc-line-entry-row border-b">
                  <td className={cn(LINE_CELL, 'w-10')}>
                    <div className={cn(LINE_CELL_BOX, 'justify-center text-muted-foreground')}>
                      <Icon icon="solar:add-circle-linear" className="h-4 w-4" />
                    </div>
                  </td>
                  <td className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>{entryProductPicker}</td>
                  <td colSpan={Math.max(visibleColumnCount - 2, 1)} className={cn(LINE_CELL, 'text-xs text-muted-foreground')}>
                    <div className={LINE_CELL_BOX}>Type or scan a SKU / barcode, then Enter.</div>
                  </td>
                </tr>
              ) : undefined}
            >
              {lines.map((line, index) => (
                <tr
                  key={index}
                  className="doc-line-row border-b transition-colors hover:bg-muted/20"
                  draggable={(line.line_type ?? 'product') === 'product'}
                  onDragStart={() => setDraggedLineIndex(index)}
                  onDragOver={(event) => event.preventDefault()}
                  onDrop={() => handleDrop(index)}
                >
                  {line.line_type === 'note' || line.line_type === 'section' ? (
                    <DocumentTextLineRow
                      type={line.line_type}
                      text={line.description}
                      colSpan={visibleColumnCount}
                      total={line.line_type === 'section' ? money(getDocumentSectionTotal(lines, index, getLineTotal)) : undefined}
                      onChange={(text) => updateLine(index, 'description', text)}
                      onDelete={() => restructure(removeSalesDocumentLine(lines, index))}
                      onDragStart={() => setDraggedLineIndex(index)}
                      onDrop={() => {
                        if (draggedLineIndex !== null) {
                          restructure(reorderDocumentLines(lines, draggedLineIndex, index))
                        }
                        setDraggedLineIndex(null)
                      }}
                    />
                  ) : (
                    <>
                      <td data-label="#" className={cn(LINE_CELL, 'w-10')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-center text-xs font-medium text-muted-foreground')}>
                          {productLineNumber(index)}
                        </div>
                      </td>

                      <td data-label="Product" className={cn(LINE_CELL, 'min-w-[17rem]', LINE_PRODUCT_WRAP)}>
                        <FieldSlot name={lineFieldKey(index, 'product_id')}>
                          <div onKeyDown={handleFlowKeyDown(index, 'product_id')}>
                            <ProductSearchCombobox
                              value={line.product_id}
                              onValueChange={(val, product) => pickProduct(index, val, product)}
                              placeholder="Search product..."
                              wrapText
                              keyboardEntry
                              focusAfterSelect={() => focusCell(index, 'quantity')}
                            />
                          </div>
                        </FieldSlot>
                      </td>

                      {showSku && (
                        <td data-label="SKU" className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE_TOKEN}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                        </td>
                      )}

                      <td data-label="Description" className={cn(LINE_CELL, 'min-w-[16rem]')}>
                        <FieldSlot name={lineFieldKey(index, 'description')}>
                          <textarea
                            value={line.description}
                            onChange={(event) => updateLine(index, 'description', event.target.value)}
                            rows={2}
                            className={LINE_TEXTAREA_TOKEN}
                            placeholder="Description"
                            required
                          />
                        </FieldSlot>
                      </td>

                      {showModelNumber && (
                        <td data-label="Model #" className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="text"
                              value={line.model_number}
                              onChange={(event) => updateLine(index, 'model_number', event.target.value)}
                              className={LINE_INPUT_TOKEN}
                              placeholder="Model #"
                            />
                          </div>
                        </td>
                      )}

                      {showProductNotes && (
                        <td data-label="Product notes" className={cn(LINE_CELL, 'min-w-[10rem]')}>
                          <textarea
                            value={line.product_notes}
                            onChange={(event) => updateLine(index, 'product_notes', event.target.value)}
                            rows={2}
                            className={LINE_TEXTAREA_TOKEN}
                            placeholder="Product notes"
                          />
                        </td>
                      )}

                      <td data-label="Qty" className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                        <FieldSlot name={lineFieldKey(index, 'quantity')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              aria-label="Quantity"
                              onKeyDown={handleFlowKeyDown(index, 'quantity')}
                              value={line.quantity}
                              onChange={(event) => {
                                // HR71-08: keep literal intent — empty is allowed
                                // while typing (caught on submit), no silent
                                // substitution of 0.
                                const raw = event.target.value
                                if (raw === '') { updateLine(index, 'quantity', ''); return }
                                const parsed = parseFloat(raw)
                                if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
                              }}
                              onBlur={() => {
                                if (line.quantity !== '') updateLine(index, 'quantity', normalizeQuantity(line.quantity) as number)
                              }}
                              className={LINE_INPUT_NUMERIC_TOKEN}
                            />
                          </div>
                        </FieldSlot>
                      </td>

                      <td data-label="Unit price" className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                        <FieldSlot name={lineFieldKey(index, 'unit_price')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              step="0.01"
                              placeholder="0.00"
                              aria-label="Unit price"
                              onKeyDown={handleFlowKeyDown(index, 'unit_price')}
                              value={line.unit_price === '' ? '' : line.unit_price}
                              onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                              className={LINE_INPUT_NUMERIC_TOKEN}
                            />
                          </div>
                          {/* #342 (D5): how this price was set. */}
                          <EditorLinePriceChip line={line} context={priceContext} money={money} className="mt-0.5" />
                        </FieldSlot>
                      </td>

                      {showTaxRate && (
                        <td data-label="Tax %" className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'tax_rate')}>
                            <div className={LINE_CELL_BOX}>
                              <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="0"
                                aria-label="Tax rate"
                                value={line.tax_rate === '' ? '' : line.tax_rate}
                                onChange={(event) => updateLine(index, 'tax_rate', event.target.value === '' ? '' : parseFloat(event.target.value))}
                                className={LINE_INPUT_NUMERIC_TOKEN}
                              />
                            </div>
                          </FieldSlot>
                        </td>
                      )}

                      {showDiscount && (
                        <td data-label="Disc" className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                          <FieldSlot name={lineFieldKey(index, 'discount_amount')}>
                            <div className={LINE_CELL_BOX}>
                              <input
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                aria-label="Line discount"
                                onKeyDown={handleFlowKeyDown(index, 'discount_amount')}
                                value={line.discount_amount === '' ? '' : line.discount_amount}
                                onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
                                className={LINE_INPUT_NUMERIC_TOKEN}
                              />
                            </div>
                          </FieldSlot>
                        </td>
                      )}

                      <td data-label="Total" className={cn(LINE_CELL, 'w-[7.5rem] min-w-[6.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end text-sm font-semibold tabular-nums')}>
                          {money(getLineTotal(line))}
                        </div>
                      </td>

                      <td data-label="" className={cn(LINE_CELL, 'w-[10.5rem]')}>
                        <div className={cn(LINE_CELL_BOX, 'justify-end')}>
                          {lineActions(index, index !== lines.length - 1)}
                        </div>
                      </td>
                    </>
                  )}
                </tr>
              ))}
            </LineItemsTable>
          </SalesLineColumnVisibility>
        </div>
      )}

      <div className="mt-3 flex flex-wrap gap-2">
        <SalesLineColumnMenu columns={menuColumns} hidden={hiddenLineColumns} setHidden={setHiddenLineColumns} />
        <ProductCatalogDialog onAdd={addCatalogProducts} />
        <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold-duotone" onClick={addLine}>
          Add Line
        </HeaderActionButton>
        <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('section')}>Add Section</HeaderActionButton>
        <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('note')}>Add Note</HeaderActionButton>
        {extraActions}
      </div>
    </div>
  )
})
