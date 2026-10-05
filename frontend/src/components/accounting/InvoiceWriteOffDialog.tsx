import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import { cn, formatCurrency } from '@/lib/utils'
import api from '@/lib/api'
import type { InvoiceJournalLine, InvoiceJournalPayload } from '@/components/accounting/InvoiceJournalTab'

interface ExpenseAccount {
  id: number
  code: string
  name: string
  is_active?: boolean
}

interface InvoiceWriteOffDialogProps {
  invoiceId: string | number
  invoiceNumber: string
  balance: number
  currencyCode?: string | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Issue #886 (UX4): the write-off action on an open invoice. Full or partial
 * amount, required reason, explicit bad-debt expense account and a journal
 * preview served by the write-off builder itself — the entry shown is the
 * entry Confirm posts (#879 core API).
 */
export function InvoiceWriteOffDialog({
  invoiceId,
  invoiceNumber,
  balance,
  currencyCode,
  open,
  onOpenChange,
}: InvoiceWriteOffDialogProps) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [amount, setAmount] = useState('')
  const [accountId, setAccountId] = useState('')
  const [reason, setReason] = useState('')

  useEffect(() => {
    if (open) {
      setAmount(balance > 0 ? String(balance) : '')
      setAccountId('')
      setReason('')
    }
  }, [open, balance])

  const { data: accounts = [] } = useQuery<ExpenseAccount[]>({
    queryKey: ['coa-expense-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/chart-of-accounts', { params: { type: 'expense' } })
      return (response.data?.data ?? response.data ?? []) as ExpenseAccount[]
    },
    enabled: open,
  })

  const numericAmount = Number(amount)
  const amountValid = Number.isFinite(numericAmount) && numericAmount > 0 && numericAmount <= balance + 0.01
  const reasonValid = reason.trim().length > 0 && reason.trim().length <= 500

  const previewReady = open && accountId !== '' && amountValid

  const { data: preview } = useQuery<InvoiceJournalPayload>({
    queryKey: ['invoice-write-off-preview', String(invoiceId), amount, accountId],
    queryFn: async () =>
      (
        await api.post(`/invoices/${invoiceId}/write-off/preview`, {
          amount: numericAmount,
          account_id: Number(accountId),
        })
      ).data,
    enabled: previewReady,
  })

  const writeOffMutation = useMutation({
    mutationFn: async () =>
      (
        await api.post(`/invoices/${invoiceId}/write-off`, {
          amount: numericAmount,
          reason: reason.trim(),
          account_id: Number(accountId),
        })
      ).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoice', String(invoiceId)] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['invoice-journal', String(invoiceId)] })
      onOpenChange(false)
      toast({
        title: 'Invoice written off',
        description: 'The bad-debt journal entry has been posted.',
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Could not write off the invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const canConfirm = previewReady && reasonValid && !writeOffMutation.isPending
  const baseCurrency = preview?.currency.base || currencyCode || undefined
  const money = (value: number) => formatCurrency(value, baseCurrency)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-100 dark:bg-rose-950/60">
              <Icon icon="solar:danger-triangle-bold-duotone" className="h-5 w-5 text-rose-600 dark:text-rose-400" />
            </div>
            <div className="space-y-1">
              <DialogTitle>Write off invoice</DialogTitle>
              <DialogDescription>
                Write off part or all of invoice{' '}
                <span className="font-medium text-foreground">{invoiceNumber}</span> as bad debt. A
                journal entry (DR bad-debt expense / CR accounts receivable) is posted immediately.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="write-off-amount">Amount{currencyCode ? ` (${currencyCode})` : ''}</Label>
            <Input
              id="write-off-amount"
              type="number"
              min="0.01"
              step="0.01"
              max={balance}
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            <p className={cn('text-xs', amountValid ? 'text-muted-foreground' : 'text-rose-600')}>
              Outstanding balance {formatCurrency(balance, currencyCode || undefined)}. Leave as is for a full
              write-off.
            </p>
          </div>
          <div className="space-y-2">
            <Label htmlFor="write-off-account">Bad-debt expense account</Label>
            <select
              id="write-off-account"
              value={accountId}
              onChange={(e) => setAccountId(e.target.value)}
              className="h-10 w-full rounded-md border bg-background px-3 text-sm"
            >
              <option value="" disabled>
                Select an expense account
              </option>
              {accounts
                .filter((account) => account.is_active !== false)
                .map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.code} — {account.name}
                  </option>
                ))}
            </select>
            <p className="text-xs text-muted-foreground">
              No default bad-debt account is configured, so the account is chosen explicitly.
            </p>
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor="write-off-reason">Reason</Label>
          <Textarea
            id="write-off-reason"
            value={reason}
            maxLength={500}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why is this receivable uncollectible?"
            className="min-h-[70px]"
          />
        </div>

        <div className="rounded-lg border bg-muted/30 p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Journal preview
            </span>
            {preview && !preview.error && (
              <Badge variant={preview.is_balanced ? 'success' : 'destructive'}>
                {preview.is_balanced ? 'Balanced' : 'Out of balance'}
              </Badge>
            )}
          </div>

          {!previewReady && (
            <p className="mt-2 text-sm text-muted-foreground">
              Enter an amount and choose an account to preview the entry.
            </p>
          )}

          {previewReady && !preview && (
            <p className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
              <Icon icon="solar:refresh-circle-bold-duotone" className="h-4 w-4 animate-spin" />
              Building the preview…
            </p>
          )}

          {preview?.error && (
            <div className="mt-2 flex items-start gap-2 text-sm text-rose-700 dark:text-rose-300">
              <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{preview.error}</span>
            </div>
          )}

          {preview && !preview.error && (
            <>
              <div className="mt-2 overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="py-1 font-medium">Account</th>
                      <th className="py-1 text-right font-medium">Debit</th>
                      <th className="py-1 text-right font-medium">Credit</th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.lines.map((line: InvoiceJournalLine, index: number) => (
                      <tr key={`${line.account_id ?? 'line'}-${index}`} className="border-t">
                        <td className="py-1.5">
                          {line.account_code ? `${line.account_code} — ` : ''}
                          {line.account_name ?? (line.account_id ? `#${line.account_id}` : 'Unresolved account')}
                        </td>
                        <td className="py-1.5 text-right tabular-nums">
                          {Number(line.debit) ? money(Number(line.debit)) : '—'}
                        </td>
                        <td className="py-1.5 text-right tabular-nums">
                          {Number(line.credit) ? money(Number(line.credit)) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t text-xs text-muted-foreground">
                      <td className="py-1.5 font-medium">Total</td>
                      <td className="py-1.5 text-right font-semibold tabular-nums">{money(preview.total_debit)}</td>
                      <td className="py-1.5 text-right font-semibold tabular-nums">{money(preview.total_credit)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <p className="mt-2 text-xs text-muted-foreground">
                Nothing is written until you confirm.
              </p>
            </>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={writeOffMutation.isPending}>
            Cancel
          </Button>
          <Button
            className="bg-rose-600 hover:bg-rose-700"
            onClick={() => writeOffMutation.mutate()}
            disabled={!canConfirm}
          >
            {writeOffMutation.isPending ? (
              <div className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-white" />
            ) : (
              <Icon icon="solar:danger-triangle-bold-duotone" className="mr-2 h-4 w-4" />
            )}
            Confirm write-off
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
