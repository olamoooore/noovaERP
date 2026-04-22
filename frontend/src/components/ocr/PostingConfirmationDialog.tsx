import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { formatCurrency } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'

interface PostingConfirmationDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  document: any
  onConfirm: () => void
  isPosting: boolean
}

export function PostingConfirmationDialog({
  open,
  onOpenChange,
  document,
  onConfirm,
  isPosting,
}: PostingConfirmationDialogProps) {
  if (!document) return null

  const systemCurrencyCode = useSettingsStore((state) => state.currencyCode)
  const postingCurrencyCode = systemCurrencyCode || 'USD'

  // Validate all line items have accounts assigned
  const hasUnassignedAccounts = document.line_items?.some((item: any) => !item.account_id)

  // Detect if line items are tax-inclusive
  const lineItemsTotal = document.line_items?.reduce((sum: number, item: any) => sum + (parseFloat(item.amount) || 0), 0) || 0
  const totalAmount = parseFloat(document.total_amount) || 0
  const taxAmount = parseFloat(document.tax_amount) || 0
  let taxRate = parseFloat(document.tax_rate) || 0
  
  // If line items sum equals total amount, they are tax-inclusive
  const isTaxInclusive = Math.abs(lineItemsTotal - totalAmount) < 0.01 && taxAmount > 0
  
  // Calculate tax rate if not provided (derive from amounts)
  if (isTaxInclusive && taxRate === 0 && taxAmount > 0) {
    // Tax rate = tax / (total - tax) * 100
    const taxExclusiveTotal = totalAmount - taxAmount
    if (taxExclusiveTotal > 0) {
      taxRate = (taxAmount / taxExclusiveTotal) * 100
    }
  }

  // Calculate journal entry preview
  const journalEntryLines = []

  // Debit lines (expenses)
  if (document.line_items && document.line_items.length > 0) {
    document.line_items.forEach((item: any) => {
      let itemAmount = parseFloat(item.amount) || 0
      
      // If tax-inclusive, calculate tax-exclusive amount
      if (isTaxInclusive && taxRate > 0) {
        itemAmount = itemAmount / (1 + taxRate / 100)
      }
      
      journalEntryLines.push({
        type: 'debit',
        account: item.account_name || (item.account_id ? `Account ${item.account_id}` : 'Account undefined'),
        description: item.description,
        amount: itemAmount,
        hasAccount: !!item.account_id,
      })
    })
  } else {
    // Fallback single expense line
    journalEntryLines.push({
      type: 'debit',
      account: '5000 - Operating Expenses',
      description: `Expense - ${document.vendor_name}`,
      amount: parseFloat(document.subtotal || document.total_amount) || 0,
      hasAccount: true,
    })
  }

  // Debit: Tax
  if (document.tax_amount && document.tax_amount > 0) {
    journalEntryLines.push({
      type: 'debit',
      account: '2200 - Tax Payable',
      description: 'Tax',
      amount: parseFloat(document.tax_amount) || 0,
      hasAccount: true,
    })
  }

  // Credit: Accounts Payable
  journalEntryLines.push({
    type: 'credit',
    account: '2100 - Accounts Payable',
    description: `AP - ${document.vendor_name}`,
    amount: parseFloat(document.total_amount) || 0,
    hasAccount: true,
  })

  const totalDebits = journalEntryLines
    .filter((line) => line.type === 'debit')
    .reduce((sum, line) => sum + (Number(line.amount) || 0), 0)

  const totalCredits = journalEntryLines
    .filter((line) => line.type === 'credit')
    .reduce((sum, line) => sum + (Number(line.amount) || 0), 0)

  const isBalanced = Math.abs(totalDebits - totalCredits) < 0.01

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:check-circle-bold" className="h-6 w-6 text-primary" />
            Confirm Posting
          </DialogTitle>
          <DialogDescription>
            Review the journal entry that will be created. This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          {/* Document Summary */}
          <Card>
            <CardContent className="pt-6">
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p className="text-muted-foreground">Vendor</p>
                  <p className="font-medium">{document.vendor_name}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Date</p>
                  <p className="font-medium">
                    {document.document_date
                      ? new Date(document.document_date).toLocaleDateString()
                      : 'N/A'}
                  </p>
                </div>
                <div>
                  <p className="text-muted-foreground">Reference</p>
                  <p className="font-medium">{document.document_reference || 'N/A'}</p>
                </div>
                <div>
                  <p className="text-muted-foreground">Total Amount</p>
                  <p className="font-medium">
                    {formatCurrency(document.total_amount, postingCurrencyCode)}
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Journal Entry Preview */}
          <div className="space-y-2">
            <h3 className="font-semibold flex items-center gap-2">
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              Journal Entry Preview
            </h3>

            <Card>
              <CardContent className="p-0">
                <div className="divide-y">
                  {/* Header */}
                  <div className="grid grid-cols-12 gap-2 p-3 bg-muted/50 text-sm font-medium">
                    <div className="col-span-4">Account</div>
                    <div className="col-span-3">Description</div>
                    <div className="col-span-2 text-right">Debit</div>
                    <div className="col-span-3 text-right">Credit</div>
                  </div>

                  {/* Lines */}
                  {journalEntryLines.map((line, index) => (
                    <div key={index} className="grid grid-cols-12 gap-2 p-3 text-sm">
                      <div className="col-span-4 font-medium min-w-0 break-words">{line.account}</div>
                      <div className="col-span-3 text-muted-foreground min-w-0 truncate">
                        {line.description}
                      </div>
                      <div className="col-span-2 text-right whitespace-nowrap tabular-nums">
                        {line.type === 'debit'
                          ? formatCurrency(line.amount, postingCurrencyCode)
                          : '-'}
                      </div>
                      <div className="col-span-3 text-right whitespace-nowrap tabular-nums">
                        {line.type === 'credit'
                          ? formatCurrency(line.amount, postingCurrencyCode)
                          : '-'}
                      </div>
                    </div>
                  ))}

                  {/* Totals */}
                  <div className="grid grid-cols-12 gap-2 p-3 bg-muted/50 text-sm font-semibold">
                    <div className="col-span-7 text-right">Totals:</div>
                    <div className="col-span-2 text-right whitespace-nowrap tabular-nums">
                      {formatCurrency(totalDebits, postingCurrencyCode)}
                    </div>
                    <div className="col-span-3 text-right whitespace-nowrap tabular-nums">
                      {formatCurrency(totalCredits, postingCurrencyCode)}
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Balance Check */}
            {isBalanced ? (
              <div className="flex items-center gap-2 text-sm text-green-600">
                <Icon icon="solar:check-circle-bold" className="h-4 w-4" />
                <span>Entry is balanced (Debits = Credits)</span>
              </div>
            ) : (
              <div className="flex items-center gap-2 text-sm text-red-600">
                <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                <span>
                  Entry is unbalanced! Difference:{' '}
                  {formatCurrency(
                    Math.abs(totalDebits - totalCredits),
                    postingCurrencyCode
                  )}
                </span>
              </div>
            )}
          </div>

          {/* Tax-inclusive detection info */}
          {isTaxInclusive && (
            <Card className="border-blue-200 bg-blue-50">
              <CardContent className="pt-6">
                <div className="flex gap-3">
                  <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-blue-600 flex-shrink-0" />
                  <div className="text-sm">
                    <p className="font-medium text-blue-900">Tax-Inclusive Amounts Detected</p>
                    <p className="text-blue-700 mt-1">
                      The line item amounts appear to include tax. They have been automatically adjusted to tax-exclusive amounts for proper accounting. 
                      Tax will be recorded separately in the Tax Payable account.
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Warnings */}
          {hasUnassignedAccounts && (
            <Card className="border-red-200 bg-red-50">
              <CardContent className="pt-6">
                <div className="flex gap-3">
                  <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-red-600 flex-shrink-0" />
                  <div className="text-sm">
                    <p className="font-medium text-red-900">Missing Account Assignments</p>
                    <p className="text-red-700 mt-1">
                      Please assign accounts to all line items before posting. Go back and select an account for each item.
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}
          
          {!isBalanced && !hasUnassignedAccounts && (
            <Card className="border-red-200 bg-red-50">
              <CardContent className="pt-6">
                <div className="flex gap-3">
                  <Icon icon="solar:danger-triangle-bold" className="h-5 w-5 text-red-600 flex-shrink-0" />
                  <div className="text-sm">
                    <p className="font-medium text-red-900">Cannot Post Unbalanced Entry</p>
                    <p className="text-red-700 mt-1">
                      Please review the amounts and ensure debits equal credits before posting.
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Info */}
          <Card className="border-blue-200 bg-blue-50">
            <CardContent className="pt-6">
              <div className="flex gap-3">
                <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-blue-600 flex-shrink-0" />
                <div className="text-sm text-blue-900">
                  <p className="font-medium">What happens when you post?</p>
                  <ul className="list-disc list-inside mt-2 space-y-1 text-blue-800">
                    <li>A journal entry will be created and posted to the ledger</li>
                    <li>Account balances will be updated</li>
                    <li>The document status will change to "Posted"</li>
                    <li>This action cannot be undone (only reversed)</li>
                    {document.line_items?.some((item: any) => item.sku) && (
                      <li>Inventory will be updated for items with SKUs</li>
                    )}
                  </ul>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isPosting}>
            Cancel
          </Button>
          <Button onClick={onConfirm} disabled={!isBalanced || hasUnassignedAccounts || isPosting}>
            {isPosting ? (
              <>
                <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                Posting...
              </>
            ) : (
              <>
                <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                Confirm & Post
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
