import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

export function AccountingPage() {
  const { data: trialBalance } = useQuery({
    queryKey: ['trial-balance'],
    queryFn: async () => {
      const response = await api.get('/accounting/trial-balance')
      return response.data
    },
  })

  const { data: chartOfAccounts } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/chart-of-accounts')
      return response.data
    },
  })

  const { data: journalEntries } = useQuery({
    queryKey: ['journal-entries'],
    queryFn: async () => {
      const response = await api.get('/accounting/journal-entries')
      return response.data.data
    },
  })

  return (
    <div className="space-y-6">
      <h1 className="text-3xl font-bold">Accounting</h1>

      <Tabs defaultValue="trial-balance" className="w-full">
        <TabsList>
          <TabsTrigger value="trial-balance">Trial Balance</TabsTrigger>
          <TabsTrigger value="chart-of-accounts">Chart of Accounts</TabsTrigger>
          <TabsTrigger value="journal-entries">Journal Entries</TabsTrigger>
        </TabsList>

        <TabsContent value="trial-balance">
          <Card>
            <CardHeader>
              <CardTitle>Trial Balance</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Account Code</TableHead>
                    <TableHead>Account Name</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Debit</TableHead>
                    <TableHead className="text-right">Credit</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {trialBalance?.accounts?.map((account: any) => (
                    <TableRow key={account.account_code}>
                      <TableCell className="font-medium">{account.account_code}</TableCell>
                      <TableCell>{account.account_name}</TableCell>
                      <TableCell className="capitalize">{account.account_type}</TableCell>
                      <TableCell className="text-right">{formatCurrency(account.debit)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(account.credit)}</TableCell>
                    </TableRow>
                  ))}
                  <TableRow className="border-t-2 font-bold">
                    <TableCell colSpan={3}>Total</TableCell>
                    <TableCell className="text-right">{formatCurrency(trialBalance?.total_debit || 0)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(trialBalance?.total_credit || 0)}</TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              {trialBalance?.is_balanced && (
                <p className="mt-4 text-sm text-green-600 font-medium">✓ Trial Balance is balanced</p>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="chart-of-accounts">
          <Card>
            <CardHeader>
              <CardTitle>Chart of Accounts</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Subtype</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {chartOfAccounts?.map((account: any) => (
                    <TableRow key={account.id}>
                      <TableCell className="font-medium">{account.code}</TableCell>
                      <TableCell>{account.name}</TableCell>
                      <TableCell className="capitalize">{account.type}</TableCell>
                      <TableCell className="capitalize">{account.subtype.replace('_', ' ')}</TableCell>
                      <TableCell className="text-right">{formatCurrency(account.balance)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="journal-entries">
          <Card>
            <CardHeader>
              <CardTitle>Journal Entries</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Entry #</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {journalEntries?.map((entry: any) => {
                    // For POS/sales entries, show the first debit amount (cash/payment received)
                    // This represents the actual transaction value, not the total of all debits including COGS
                    const firstDebitLine = entry.lines?.find((line: any) => parseFloat(line.debit) > 0);
                    const displayAmount = firstDebitLine ? parseFloat(firstDebitLine.debit) : 0;
                    
                    return (
                      <TableRow key={entry.id}>
                        <TableCell className="font-medium">{entry.entry_number}</TableCell>
                        <TableCell>{new Date(entry.entry_date).toLocaleDateString()}</TableCell>
                        <TableCell className="capitalize">{entry.type}</TableCell>
                        <TableCell>{entry.reference || '-'}</TableCell>
                        <TableCell>{entry.description}</TableCell>
                        <TableCell className="text-right">
                          {formatCurrency(displayAmount)}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}
