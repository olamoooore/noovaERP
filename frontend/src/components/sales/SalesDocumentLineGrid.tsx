import React, { useState } from 'react'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
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
  LINE_INPUT,
  LINE_INPUT_NUMERIC,
  LINE_PRODUCT_WRAP,
  LINE_TEXTAREA,
  LINE_VALUE,
  ProductLineActions,
  productLineColumns,
} from '@/components/sales/ProductLineTable'
import {
  duplicateSalesDocumentLine,
  emptySalesDocumentLine,
  getSalesDocumentLineTotal,
  isBlankSalesDocumentLine,
  moveSalesDocumentLine,
  removeSalesDocumentLine,
  resetSalesDocumentLine,
  type SalesDocumentLine,
} from '@/lib/salesDocumentLines'

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
 */

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
  products: any[]
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
}

export function SalesDocumentLineGrid({
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
}: SalesDocumentLineGridProps) {
  const [draggedLineIndex, setDraggedLineIndex] = useState<number | null>(null)
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(defaultHiddenColumns)
  const isNarrow = useMediaQuery(SALES_LINE_CARDS_MEDIA_QUERY)
  const emptyLine = (): SalesDocumentLine => emptySalesDocumentLine()
  const getLineTotal = (line: SalesDocumentLine) => getSalesDocumentLineTotal(line)

  const columns = productLineColumns({
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

    setLines(updated)
    // Qty changes re-evaluate auto lines against quantity tiers (FR-6.2).
    if (field === 'quantity' && updated[index].price_mode === 'auto') {
      resolvePricing?.(updated)
    }
  }

  const pickProduct = (index: number, value: string, product?: any) => {
    if (!product) {
      updateLine(index, 'product_id', value)
      return
    }
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

  const addLine = () => setLines([...lines, emptyLine()])
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
    setLines(next)
    // #288(a): catalogue additions must price through the engine too.
    resolvePricing?.(next)
  }

  const handleDrop = (index: number) => {
    if (draggedLineIndex !== null && draggedLineIndex !== index) {
      setLines(reorderDocumentLines(lines, draggedLineIndex, index))
    }
    setDraggedLineIndex(null)
  }

  const lineActions = (index: number, canMoveDown: boolean) => (
    <ProductLineActions
      onMoveUp={() => setLines(moveSalesDocumentLine(lines, index, 'up'))}
      onMoveDown={() => setLines(moveSalesDocumentLine(lines, index, 'down'))}
      canMoveUp={index !== 0}
      canMoveDown={canMoveDown}
      onDuplicate={() => setLines(duplicateSalesDocumentLine(lines, index))}
      onReset={() => setLines(resetSalesDocumentLine(lines, index, emptyLine()))}
      onDelete={() => setLines(removeSalesDocumentLine(lines, index))}
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
        onClick={() => setLines(removeSalesDocumentLine(lines, index))}
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

      <div>
        <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Product</label>
        <ProductSearchCombobox
          value={line.product_id}
          onValueChange={(val, product) => pickProduct(index, val, product)}
          placeholder="Search product..."
          wrapText
        />
      </div>

      {columnVisible('sku') && (
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">SKU</label>
          <div className={LINE_VALUE_TOKEN}>{line.sku || <span className="opacity-60">SKU</span>}</div>
        </div>
      )}

      <div>
        <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Description</label>
        <textarea
          value={line.description}
          onChange={(event) => updateLine(index, 'description', event.target.value)}
          rows={2}
          className={LINE_TEXTAREA_TOKEN}
          placeholder="Description"
          required
        />
      </div>

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
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Qty</label>
          <input
            type="number"
            min="0"
            aria-label="Quantity"
            value={line.quantity}
            onChange={(event) => {
              // HR71-08: keep literal intent — empty is allowed while typing
              // (caught on submit), no silent substitution of 0.
              const raw = event.target.value
              if (raw === '') { updateLine(index, 'quantity', ''); return }
              const parsed = parseFloat(raw)
              if (!Number.isNaN(parsed) && parsed >= 0) updateLine(index, 'quantity', parsed)
            }}
            className={LINE_INPUT_NUMERIC_TOKEN}
          />
        </div>
        <div>
          <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Unit price</label>
          <input
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            aria-label="Unit price"
            value={line.unit_price === '' ? '' : line.unit_price}
            onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
            className={LINE_INPUT_NUMERIC_TOKEN}
          />
        </div>
        {columnVisible('tax_rate') && (
          <div>
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
          </div>
        )}
        {columnVisible('discount') && (
          <div>
            <label className="mb-0.5 block text-xs font-medium text-muted-foreground">Disc</label>
            <input
              type="number"
              min="0"
              step="0.01"
              placeholder="0.00"
              aria-label="Line discount"
              value={line.discount_amount === '' ? '' : line.discount_amount}
              onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
              className={LINE_INPUT_NUMERIC_TOKEN}
            />
          </div>
        )}
      </div>

      <div className="flex items-center justify-between gap-2">
        <span className="text-xs text-muted-foreground">Line total</span>
        <span className="text-sm font-semibold tabular-nums">{money(getLineTotal(line))}</span>
      </div>

      <div className="flex justify-end">{lineActions(index, index !== lines.length - 1)}</div>
    </div>
  )

  return (
    <>
      {isNarrow ? (
        <div className="space-y-3" data-testid="sales-line-cards">
          {lines.map((line, index) =>
            line.line_type === 'note' || line.line_type === 'section'
              ? renderTextCard(line, index)
              : renderProductCard(line, index),
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
                      onDelete={() => setLines(removeSalesDocumentLine(lines, index))}
                      onDragStart={() => setDraggedLineIndex(index)}
                      onDrop={() => {
                        if (draggedLineIndex !== null) {
                          setLines(reorderDocumentLines(lines, draggedLineIndex, index))
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
                        <ProductSearchCombobox
                          value={line.product_id}
                          onValueChange={(val, product) => pickProduct(index, val, product)}
                          placeholder="Search product..."
                          wrapText
                        />
                      </td>

                      {showSku && (
                        <td data-label="SKU" className={cn(LINE_CELL, 'min-w-[7rem]')}>
                          <div className={LINE_VALUE_TOKEN}>{line.sku || <span className="opacity-60">SKU</span>}</div>
                        </td>
                      )}

                      <td data-label="Description" className={cn(LINE_CELL, 'min-w-[16rem]')}>
                        <textarea
                          value={line.description}
                          onChange={(event) => updateLine(index, 'description', event.target.value)}
                          rows={2}
                          className={LINE_TEXTAREA_TOKEN}
                          placeholder="Description"
                          required
                        />
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
                        <div className={LINE_CELL_BOX}>
                          <input
                            type="number"
                            min="0"
                            aria-label="Quantity"
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
                            className={LINE_INPUT_NUMERIC_TOKEN}
                          />
                        </div>
                      </td>

                      <td data-label="Unit price" className={cn(LINE_CELL, 'w-[7rem] min-w-[6rem]')}>
                        <div className={LINE_CELL_BOX}>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            aria-label="Unit price"
                            value={line.unit_price === '' ? '' : line.unit_price}
                            onChange={(event) => updateLine(index, 'unit_price', event.target.value === '' ? '' : parseFloat(event.target.value))}
                            className={LINE_INPUT_NUMERIC_TOKEN}
                          />
                        </div>
                      </td>

                      {showTaxRate && (
                        <td data-label="Tax %" className={cn(LINE_CELL, 'w-[5rem] min-w-[4rem]')}>
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
                        </td>
                      )}

                      {showDiscount && (
                        <td data-label="Disc" className={cn(LINE_CELL, 'w-[5.5rem] min-w-[4.5rem]')}>
                          <div className={LINE_CELL_BOX}>
                            <input
                              type="number"
                              min="0"
                              step="0.01"
                              placeholder="0.00"
                              aria-label="Line discount"
                              value={line.discount_amount === '' ? '' : line.discount_amount}
                              onChange={(event) => updateLine(index, 'discount_amount', event.target.value === '' ? '' : parseFloat(event.target.value))}
                              className={LINE_INPUT_NUMERIC_TOKEN}
                            />
                          </div>
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
        <ProductCatalogDialog products={products || []} onAdd={addCatalogProducts} />
        <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold-duotone" onClick={addLine}>
          Add Line
        </HeaderActionButton>
        <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('section')}>Add Section</HeaderActionButton>
        <HeaderActionButton type="button" variant="outline" onClick={() => addTextLine('note')}>Add Note</HeaderActionButton>
        {extraActions}
      </div>
    </>
  )
}
