import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  pending: 'bg-yellow-100 text-yellow-800',
  in_transit: 'bg-blue-100 text-blue-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

export function WarehouseTransfersPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')

  const { data: transfersData, isLoading } = useQuery({
    queryKey: ['warehouse-transfers', search, status],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      if (status !== 'all') params.status = status
      const res = await api.get('/warehouse-transfers', { params })
      return res.data
    },
  })

  const cancelMutation = useMutation({
    mutationFn: (id: number) => api.post(`/warehouse-transfers/${id}/cancel`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouse-transfers'] })
      toast({ title: 'Transfer cancelled' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to cancel',
        variant: 'destructive',
      })
    },
  })

  const transfers = transfersData?.data || []

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Warehouse Transfers</h1>
        <Button asChild>
          <Link to="/inventory/transfers/new">
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            New Transfer
          </Link>
        </Button>
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center gap-4">
            <Input
              placeholder="Search transfer number..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="max-w-sm"
            />
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="pending">Pending</SelectItem>
                <SelectItem value="in_transit">In Transit</SelectItem>
                <SelectItem value="completed">Completed</SelectItem>
                <SelectItem value="cancelled">Cancelled</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Transfer #</TableHead>
                <TableHead>From</TableHead>
                <TableHead>To</TableHead>
                <TableHead>Date</TableHead>
                <TableHead>Expected</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Created By</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={8} className="text-center py-8">Loading...</TableCell>
                </TableRow>
              ) : transfers.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                    No transfers found
                  </TableCell>
                </TableRow>
              ) : (
                transfers.map((transfer: any) => (
                  <TableRow key={transfer.id}>
                    <TableCell className="font-medium">
                      <Link to={`/inventory/transfers/${transfer.id}`} className="text-blue-600 hover:underline">
                        {transfer.transfer_number}
                      </Link>
                    </TableCell>
                    <TableCell>{transfer.from_warehouse?.name}</TableCell>
                    <TableCell>{transfer.to_warehouse?.name}</TableCell>
                    <TableCell>{new Date(transfer.transfer_date).toLocaleDateString()}</TableCell>
                    <TableCell>
                      {transfer.expected_date ? new Date(transfer.expected_date).toLocaleDateString() : '-'}
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusColors[transfer.status]}`}>
                        {transfer.status.replace('_', ' ')}
                      </span>
                    </TableCell>
                    <TableCell>{transfer.created_by?.name || '-'}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1.5">
                        <Button variant="outline" size="icon" className="h-7 w-7" asChild>
                          <Link to={`/inventory/transfers/${transfer.id}`} title="View">
                            <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                          </Link>
                        </Button>
                        {transfer.status === 'draft' && (
                          <Button variant="outline" size="icon" className="h-7 w-7" asChild>
                            <Link to={`/inventory/transfers/${transfer.id}/edit`} title="Edit">
                              <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                            </Link>
                          </Button>
                        )}
                        {!['completed', 'cancelled'].includes(transfer.status) && (
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => {
                              if (confirm('Cancel this transfer?')) {
                                cancelMutation.mutate(transfer.id)
                              }
                            }}
                            title="Cancel"
                          >
                            <Icon icon="solar:close-circle-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}
