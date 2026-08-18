import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
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
    if (score >= 0.85) {
      return <Badge className="bg-green-500 text-white text-xs">High</Badge>
    } else if (score >= 0.7) {
      return <Badge className="bg-yellow-500 text-white text-xs">Medium</Badge>
    } else {
      return <Badge className="bg-red-500 text-white text-xs">Low</Badge>
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* Vendor Information */}
      <div className="space-y-4">
        <h3 className="font-semibold flex items-center gap-2">
          <Icon icon="solar:user-bold" className="h-5 w-5" />
          Vendor Information
        </h3>

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
              <p className="mb-1">Suggestions:</p>
              <div className="flex flex-wrap gap-2">
                {suggestions.vendors.map((vendor: any) => (
                  <Button
                    key={vendor.id}
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      handleChange('vendor_name', vendor.name)
                      handleChange('vendor_id', vendor.id)
                    }}
                    disabled={readOnly}
                  >
                    {vendor.name} ({Math.round(vendor.confidence * 100)}%)
                  </Button>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Document Details */}
      <div className="space-y-4">
        <h3 className="font-semibold flex items-center gap-2">
          <Icon icon="solar:document-text-bold" className="h-5 w-5" />
          Document Details
        </h3>

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
        <div className="flex items-center justify-between">
          <h3 className="font-semibold flex items-center gap-2">
            <Icon icon="solar:list-bold" className="h-5 w-5" />
            Line Items
          </h3>
          {!readOnly && (
            <Button type="button" variant="outline" size="sm" onClick={addLineItem}>
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Item
            </Button>
          )}
        </div>

        {formData.line_items.map((item: any, index: number) => (
          <Card key={index} className="p-4">
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label>Item {index + 1}</Label>
                {!readOnly && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => removeLineItem(index)}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                  </Button>
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
        <h3 className="font-semibold flex items-center gap-2">
          <Icon icon="solar:dollar-bold" className="h-5 w-5" />
          Amounts
        </h3>

        <div className="grid grid-cols-2 gap-4">
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
          <Button type="submit" disabled={isSaving}>
            {isSaving ? (
              <>
                <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                Saving...
              </>
            ) : (
              <>
                <Icon icon="solar:diskette-bold" className="mr-2 h-4 w-4" />
                Save Changes
              </>
            )}
          </Button>
        </div>
      )}
    </form>
  )
}
