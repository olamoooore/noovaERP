import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { formatDate } from '@/lib/utils'

export function QuotesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [searchQuery, setSearchQuery] = useState('')

  const { data: quotes, isLoading } = useQuery({
    queryKey: ['quotations'],
    queryFn: async () => {
      const response = await api.get('/quotations')
      return response.data.data || []
    },
  })

  const confirmMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/quotations/${id}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast({ title: 'Quotation confirmed and Sales Order created' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const filteredQuotes = useMemo(() => {
    const items = Array.isArray(quotes) ? quotes : []
    if (!searchQuery.trim()) {
      return items
    }

    const term = searchQuery.toLowerCase()
    return items.filter((quote: any) =>
      String(quote.quotation_number || '').toLowerCase().includes(term)
      || String(quote.customer?.name || '').toLowerCase().includes(term)
      || String(quote.customer_reference || '').toLowerCase().includes(term)
    )
  }, [quotes, searchQuery])

  const formatCurrency = (amount: number | string) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(amount || 0))

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      sent: 'bg-blue-100 text-blue-800',
      confirmed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Quotations</h1>
          <p className="text-muted-foreground">Create and manage quotations. Confirm to create Sales Orders, then invoice from orders.</p>
        </div>

        <div className="flex gap-3">
          <Button variant="outline" onClick={() => navigate('/quotations/import')}>
            <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
            Import
          </Button>
          <Button onClick={() => navigate('/quotations/create')}>
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            Create Quotation
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>All Quotations</span>
            <span className="text-sm font-normal text-muted-foreground">{filteredQuotes.length} quotation(s)</span>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <input
              type="text"
              value={searchQuery}
              onChange={(event) => setSearchQuery(event.target.value)}
              placeholder="Search quotation number, customer, or reference"
              className="w-full max-w-md rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
            />
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading quotations...</p>
            </div>
          ) : filteredQuotes.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:file-text-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
              <p className="mb-2 text-lg font-medium text-foreground">No quotations yet</p>
              <p className="mb-4 text-sm">Create a quotation to start a sales proposal for a customer.</p>
              <Button onClick={() => navigate('/quotations/create')}>
                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                Create Quotation
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Quote #</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Quote Date</TableHead>
                    <TableHead>Valid Until</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredQuotes.map((quote: any) => (
                    <TableRow key={quote.id} className="hover:bg-accent/50">
                      <TableCell className="font-medium">{quote.quotation_number}</TableCell>
                      <TableCell>
                        <div>
                          {quote.customer?.code && (
                            <span className="text-xs font-mono text-muted-foreground">{quote.customer.code}</span>
                          )}
                          <div>{quote.customer?.name || 'Unknown'}</div>
                        </div>
                      </TableCell>
                      <TableCell>{formatDate(quote.quotation_date || quote.created_at)}</TableCell>
                      <TableCell>{quote.valid_until ? formatDate(quote.valid_until) : '-'}</TableCell>
                      <TableCell>{quote.customer_reference || quote.internal_reference || '-'}</TableCell>
                      <TableCell className="text-right">{formatCurrency(quote.total)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary" className={getStatusBadge(quote.status)}>
                          {quote.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex justify-end gap-2">
                          <Button size="sm" variant="outline" onClick={() => navigate(`/quotations/${quote.id}`)}>
                            <Icon icon="solar:eye-bold" className="h-3 w-3" />
                          </Button>
                          {(quote.status === 'draft' || quote.status === 'sent') && (
                            <Button
                              size="sm"
                              onClick={() => confirmMutation.mutate(quote.id)}
                              disabled={confirmMutation.isPending}
                            >
                              <Icon icon="solar:check-circle-bold" className="mr-1 h-3 w-3" />
                              Confirm
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

export default QuotesPage
