import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { RefundDialog } from '@/components/payment/RefundDialog'
import { Icon } from '@iconify/react'

export function PaymentsPage() {
  const [refundingTransaction, setRefundingTransaction] = useState<any>(null)

  const { data: transactions, isLoading, refetch } = useQuery({
    queryKey: ['payment-transactions'],
    queryFn: async () => {
      const response = await api.get('/payment-transactions')
      return response.data.data
    },
  })

  const handleRefundSuccess = () => {
    refetch()
    setRefundingTransaction(null)
  }

  const getStatusBadge = (status: string) => {
    const variants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
      pending: 'outline',
      processing: 'secondary',
      completed: 'default',
      failed: 'destructive',
      refunded: 'secondary',
      cancelled: 'outline',
    }
    return (
      <Badge variant={variants[status] || 'default'}>
        {status.charAt(0).toUpperCase() + status.slice(1)}
      </Badge>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Payment Transactions</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Transaction History</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-8 text-muted-foreground">Loading transactions...</div>
          ) : transactions?.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">No transactions found</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Transaction ID</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                  <TableHead>Gateway</TableHead>
                  <TableHead>Invoice</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Payment Method</TableHead>
                  <TableHead>Amount</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions?.map((transaction: any) => (
                  <TableRow key={transaction.id}>
                    <TableCell className="font-mono text-xs">
                      {transaction.transaction_id?.substring(0, 8)}...
                    </TableCell>
                    <TableCell>
                      <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800">
                        {transaction.gateway?.gateway_name || 'N/A'}
                      </span>
                    </TableCell>
                    <TableCell>{transaction.invoice?.invoice_number || '-'}</TableCell>
                    <TableCell>{transaction.customer?.name || transaction.invoice?.customer?.name || '-'}</TableCell>
                    <TableCell>{formatDate(transaction.created_at)}</TableCell>
                    <TableCell>
                      {transaction.payment_method ? (
                        <span className="capitalize">{transaction.payment_method.replace('_', ' ')}</span>
                      ) : (
                        '-'
                      )}
                    </TableCell>
                    <TableCell className="font-semibold">
                      {formatCurrency(transaction.amount)} {transaction.currency}
                    </TableCell>
                    <TableCell>{getStatusBadge(transaction.status)}</TableCell>
                    <TableCell className="text-right">
                      {transaction.status === 'completed' && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => setRefundingTransaction(transaction)}
                          disabled={transaction.refunded_amount >= transaction.amount}
                        >
                          <Icon icon="solar:undo-left-bold-duotone" className="mr-1 h-4 w-4" />
                          {transaction.refunded_amount > 0 ? 'Refund More' : 'Refund'}
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {refundingTransaction && (
        <RefundDialog
          transaction={refundingTransaction}
          open={!!refundingTransaction}
          onOpenChange={(open) => !open && setRefundingTransaction(null)}
          onSuccess={handleRefundSuccess}
        />
      )}
    </div>
  )
}
