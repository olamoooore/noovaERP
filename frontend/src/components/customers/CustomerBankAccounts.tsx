import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import api from '@/lib/api'
import { getApiErrorMessage } from '@/lib/utils'
import { useAuthStore } from '@/store/authStore'
import type { CustomerBankAccount } from '@/types/customerBankAccount'

interface Props {
  customerId: number | string
}

const emptyForm = {
  bank_name: '',
  account_name: '',
  account_number: '',
  swift_code: '',
  iban: '',
  currency: '',
  branch_name: '',
}

type FormState = typeof emptyForm

/**
 * #444 (SALES-ODOO): the customer's bank accounts, used as the payee of
 * credit-note refunds (#528). Account numbers arrive masked from the API
 * unless the viewer holds contacts.bank_accounts.view; adding or removing
 * needs contacts.bank_accounts.edit.
 */
export function CustomerBankAccounts({ customerId }: Props) {
  const queryClient = useQueryClient()
  const canEdit = useAuthStore((state) => state.hasPermission('contacts.bank_accounts.edit'))
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<FormState>(emptyForm)

  const queryKey = ['customer-bank-accounts', Number(customerId)]
  const { data: accounts = [], isLoading } = useQuery<CustomerBankAccount[]>({
    queryKey,
    queryFn: async () => (await api.get(`/customers/${customerId}/bank-accounts`)).data.data as CustomerBankAccount[],
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey })

  const createMutation = useMutation({
    mutationFn: async () => {
      const payload = Object.fromEntries(
        Object.entries(form).map(([key, value]) => [key, value.trim() === '' ? null : value.trim()]),
      )
      return (
        await api.post(`/customers/${customerId}/bank-accounts`, {
          ...payload,
          is_primary: accounts.length === 0,
        })
      ).data
    },
    onSuccess: () => {
      invalidate()
      setOpen(false)
    },
  })

  const primaryMutation = useMutation({
    mutationFn: async (id: string) => (await api.put(`/customers/${customerId}/bank-accounts/${id}`, { is_primary: true })).data,
    onSuccess: invalidate,
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => (await api.delete(`/customers/${customerId}/bank-accounts/${id}`)).data,
    onSuccess: invalidate,
  })

  const openDialog = () => {
    createMutation.reset()
    setForm(emptyForm)
    setOpen(true)
  }

  const field = (key: keyof FormState, label: string) => (
    <div className="space-y-2">
      <Label htmlFor={`customer-bank-${key}`}>{label}</Label>
      <Input
        id={`customer-bank-${key}`}
        value={form[key]}
        onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
      />
    </div>
  )

  const mutationError = primaryMutation.error ?? deleteMutation.error

  return (
    <Card className="rounded-xl border-slate-200/80 dark:border-slate-800 lg:col-span-2">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Bank accounts</CardTitle>
          <CardDescription>Used to pay refunds to this customer.</CardDescription>
        </div>
        {canEdit && (
          <Button size="sm" variant="outline" onClick={openDialog}>
            Add bank account
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading bank accounts...</p>
        ) : accounts.length === 0 ? (
          <p className="text-sm text-muted-foreground">No bank accounts recorded for this customer.</p>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Bank</TableHead>
                  <TableHead>Account name</TableHead>
                  <TableHead>Account number</TableHead>
                  <TableHead>SWIFT / BIC</TableHead>
                  <TableHead>Currency</TableHead>
                  <TableHead />
                </TableRow>
              </TableHeader>
              <TableBody>
                {accounts.map((account) => (
                  <TableRow key={account.id}>
                    <TableCell>
                      {account.bank_name ?? '-'}
                      {account.is_primary && (
                        <Badge variant="outline" className="ml-2">
                          Primary
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell>{account.account_name ?? '-'}</TableCell>
                    <TableCell className="font-mono">{account.account_number ?? account.iban ?? '-'}</TableCell>
                    <TableCell>{account.swift_code ?? '-'}</TableCell>
                    <TableCell>{account.currency ?? '-'}</TableCell>
                    <TableCell className="whitespace-nowrap text-right">
                      {canEdit && !account.is_primary && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={primaryMutation.isPending}
                          onClick={() => primaryMutation.mutate(account.id)}
                        >
                          Make primary
                        </Button>
                      )}
                      {canEdit && (
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={deleteMutation.isPending}
                          onClick={() => deleteMutation.mutate(account.id)}
                        >
                          Remove
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
        {mutationError && (
          <p className="mt-2 text-sm text-red-600">{getApiErrorMessage(mutationError, 'Failed to update the bank account.')}</p>
        )}
      </CardContent>

      <Dialog open={open} onOpenChange={(next) => !createMutation.isPending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add bank account</DialogTitle>
          </DialogHeader>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {field('bank_name', 'Bank name')}
            {field('account_name', 'Account name')}
            {field('account_number', 'Account number')}
            {field('swift_code', 'SWIFT / BIC')}
            {field('iban', 'IBAN')}
            {field('currency', 'Currency')}
            {field('branch_name', 'Branch')}
          </div>
          {createMutation.isError && (
            <p className="text-sm text-red-600">{getApiErrorMessage(createMutation.error, 'Failed to add the bank account.')}</p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={createMutation.isPending}>
              Cancel
            </Button>
            <Button
              onClick={() => createMutation.mutate()}
              disabled={form.account_number.trim() === '' || createMutation.isPending}
            >
              {createMutation.isPending ? 'Saving...' : 'Save bank account'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
