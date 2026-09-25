import { useState, useEffect, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import chartOfAccountsApi from '@/lib/chartOfAccountsApi'

interface DocumentFormProps {
  document: any
  suggestions?: any
  onSave: (data: any) => void
  isSaving: boolean
  readOnly: boolean
}

function toDateInputValue(val: string | null | undefined): string {
  if (!val) return ''
  // Handle ISO datetime ("2019-02-11T00:00:00.000000Z") → "2019-02-11"
  return val.substring(0, 10)
}

export function DocumentForm({ document, suggestions, onSave, isSaving, readOnly }: DocumentFormProps) {
  const [formData, setFormData] = useState({
    vendor_name: document.vendor_name || '',
    vendor_id: document.vendor_id || null,
    document_date: toDateInputValue(document.document_date),
    document_reference: document.document_reference || '',
    subtotal: document.subtotal || 0,
    tax_amount: document.tax_amount || 0,
    tax_rate: document.tax_rate || 0,
    total_amount: document.total_amount || 0,
    currency_code: document.currency_code || 'USD',
    line_items: document.line_items || [],
    dimensions: document.dimensions || {},
  })

  const [hasChanges, setHasChanges] = useState(false)

  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  })

  useEffect(() => {
    setFormData({
      vendor_name: document.vendor_name || '',
      vendor_id: document.vendor_id || null,
      document_date: toDateInputValue(document.document_date),
      document_reference: document.document_reference || '',
      subtotal: document.subtotal || 0,
      tax_amount: document.tax_amount || 0,
      tax_rate: document.tax_rate || 0,
      total_amount: document.total_amount || 0,
      currency_code: document.currency_code || 'USD',
      line_items: document.line_items || [],
      dimensions: document.dimensions || {},
    })
  }, [document])

  const handleChange = (field: string, value: any) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    setHasChanges(true)
  }

  const handleLineItemChange = (index: number, field: string, value: any) => {
    const newLineItems = [...formData.line_items]
    newLineItems[index] = { ...newLineItems[index], [field]: value }
    setFormData((prev) => ({ ...prev, line_items: newLineItems }))
    setHasChanges(true)
  }

  const addLineItem = () => {
    setFormData((prev) => ({
      ...prev,
      line_items: [
        ...prev.line_items,
        {
          description: '',
          amount: 0,
          account_id: null,
          sku: '',
          quantity: 1,
        },
      ],
    }))
    setHasChanges(true)
  }

  const removeLineItem = (index: number) => {
    setFormData((prev) => ({
      ...prev,
      line_items: prev.line_items.filter((_: any, i: number) => i !== index),
    }))
    setHasChanges(true)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onSave(formData)
    setHasChanges(false)
  }

  const getConfidenceBadge = (score: number) => {
    const style =
      score >= 0.85
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : score >= 0.7
          ? 'border-amber-200 bg-amber-50 text-amber-700'
          : 'border-red-200 bg-red-50 text-red-700'

    return (
      <Badge variant="outline" className={`text-xs font-medium ${style}`}>
        {score >= 0.85 ? 'High' : score >= 0.7 ? 'Medium' : 'Low'} · {Math.round(score * 100)}%
      </Badge>
    )
  }

  const SectionHeader = ({ icon, title, action }: { icon: string; title: string; action?: ReactNode }) => (
    <div className="flex items-center justify-between border-b pb-3">
      <div className="flex items-center gap-2.5">
        <div className="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 text-slate-600">
          <Icon icon={icon} className="h-4 w-4" />
        </div>
        <h3 className="text-sm font-semibold">{title}</h3>
      </div>
      {action}
    </div>
  )

  return (
    <form onSubmit={handleSubmit} className="space-y-8">
      {/* Vendor Information */}
      <div className="space-y-4">
        <SectionHeader icon="solar:user-bold-duotone" title="Vendor Information" />

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label htmlFor="vendor_name">Vendor Name</Label>
            {document.ocr_confidence_scores?.vendor_name && (
              getConfidenceBadge(document.ocr_confidence_scores.vendor_name)
            )}
          </div>
          <Input
            id="vendor_name"
            value={formData.vendor_name}
            onChange={(e) => handleChange('vendor_name', e.target.value)}
            disabled={readOnly}
            required
          />
          {suggestions?.vendors && suggestions.vendors.length > 0 && (
            <div className="text-sm text-muted-foreground">
              <p className="mb-1.5">Suggestions:</p>
              <div className="flex flex-wrap gap-2">
                {suggestions.vendors.map((vendor: any) => (
                  <HeaderActionButton
                    key={vendor.id}
                    type="button"
                    variant="outline"
                    icon="solar:magic-stick-3-bold-duotone"
                    onClick={() => {
                      handleChange('vendor_name', vendor.name)
                      handleChange('vendor_id', vendor.id)
                    }}
                    disabled={readOnly}
                  >
                    {vendor.name} ({Math.round(vendor.confidence * 100)}%)
                  </HeaderActionButton>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Document Details */}
      <div className="space-y-4">
        <SectionHeader icon="solar:document-text-bold-duotone" title="Document Details" />

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor="document_date">Date</Label>
            <Input
              id="document_date"
              type="date"
              value={formData.document_date}
              onChange={(e) => handleChange('document_date', e.target.value)}
              disabled={readOnly}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="document_reference">Reference/Invoice #</Label>
            <Input
              id="document_reference"
              value={formData.document_reference}
              onChange={(e) => handleChange('document_reference', e.target.value)}
              disabled={readOnly}
            />
          </div>
        </div>
      </div>

      {/* Line Items */}
      <div className="space-y-4">
        <SectionHeader
          icon="solar:list-bold-duotone"
          title="Line Items"
          action={
            !readOnly ? (
              <HeaderActionButton
                type="button"
                variant="outline"
                icon="solar:add-circle-linear"
                onClick={addLineItem}
              >
                Add Item
              </HeaderActionButton>
            ) : undefined
          }
        />

        {formData.line_items.length === 0 && (
          <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            No line items extracted. Click "Add Item" to add one.
          </p>
        )}

        {formData.line_items.map((item: any, index: number) => (
          <Card key={index} className="rounded-xl border-dashed p-4">
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <div className="grid h-6 w-6 place-items-center rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600">
                    {index + 1}
                  </div>
                  <span className="text-sm font-medium">Line item</span>
                </div>
                {!readOnly && (
                  <HeaderActionButton
                    type="button"
                    variant="ghost"
                    icon="solar:trash-bin-trash-bold"
                    className="h-7 px-2 text-destructive hover:text-destructive"
                    aria-label="Remove line item"
                    onClick={() => removeLineItem(index)}
                  />
                )}
              </div>

              <div className="space-y-2">
                <Label>Description</Label>
                <Input
                  value={item.description || ''}
                  onChange={(e) => handleLineItemChange(index, 'description', e.target.value)}
                  disabled={readOnly}
                />
              </div>

              <div className="grid grid-cols-4 gap-2">
                <div className="space-y-2">
                  <Label>Quantity</Label>
                  <Input
                    type="number"
                    value={item.quantity || 1}
                    onChange={(e) => handleLineItemChange(index, 'quantity', parseFloat(e.target.value))}
                    disabled={readOnly}
                  />
                </div>

                <div className="space-y-2">
                  <Label>Unit Price</Label>
                  <Input
                    type="number"
                    step="0.01"
                    value={item.unit_price || 0}
                    onChange={(e) => handleLineItemChange(index, 'unit_price', parseFloat(e.target.value))}
                    disabled={readOnly}
                  />
                </div>

                <div className="space-y-2">
                  <Label>Amount</Label>
                  <Input
                    type="number"
                    step="0.01"
                    value={item.amount || 0}
                    onChange={(e) => handleLineItemChange(index, 'amount', parseFloat(e.target.value))}
                    disabled={readOnly}
                  />
                </div>

                <div className="space-y-2">
                  <Label>SKU</Label>
                  <Input
                    value={item.sku || ''}
                    onChange={(e) => handleLineItemChange(index, 'sku', e.target.value)}
                    disabled={readOnly}
                    placeholder="Optional"
                  />
                </div>
              </div>

              <div className="space-y-2">
                <Label>Account</Label>
                <Select
                  value={item.account_id?.toString() || ''}
                  onValueChange={(value) => handleLineItemChange(index, 'account_id', parseInt(value))}
                  disabled={readOnly}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select account" />
                  </SelectTrigger>
                  <SelectContent>
                    {accounts
                      .filter((acc: any) => acc.is_active)
                      .map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {/* Amounts */}
      <div className="space-y-4">
        <SectionHeader icon="solar:dollar-bold-duotone" title="Amounts" />

        <div className="grid grid-cols-2 gap-4 rounded-xl border bg-muted/20 p-4">
          <div className="space-y-2">
            <Label htmlFor="subtotal">Subtotal</Label>
            <Input
              id="subtotal"
              type="number"
              step="0.01"
              value={formData.subtotal}
              onChange={(e) => handleChange('subtotal', parseFloat(e.target.value))}
              disabled={readOnly}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="tax_amount">Tax Amount</Label>
            <Input
              id="tax_amount"
              type="number"
              step="0.01"
              value={formData.tax_amount}
              onChange={(e) => handleChange('tax_amount', parseFloat(e.target.value))}
              disabled={readOnly}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="tax_rate">Tax Rate (%)</Label>
            <Input
              id="tax_rate"
              type="number"
              step="0.01"
              value={formData.tax_rate}
              onChange={(e) => handleChange('tax_rate', parseFloat(e.target.value))}
              disabled={readOnly}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="total_amount">Total Amount</Label>
            <Input
              id="total_amount"
              type="number"
              step="0.01"
              value={formData.total_amount}
              onChange={(e) => handleChange('total_amount', parseFloat(e.target.value))}
              disabled={readOnly}
              required
            />
          </div>
        </div>
      </div>

      {/* Save Button */}
      {!readOnly && hasChanges && (
        <div className="flex justify-end">
          <HeaderActionButton
            type="submit"
            icon="solar:diskette-bold"
            loading={isSaving}
            disabled={isSaving}
          >
            {isSaving ? 'Saving...' : 'Save Changes'}
          </HeaderActionButton>
        </div>
      )}
    </form>
  )
}
