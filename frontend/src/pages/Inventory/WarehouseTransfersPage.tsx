import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

const statusStyles: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
  in_transit: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
  completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
  cancelled: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
}

export function WarehouseTransfersPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
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

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/warehouse-transfers/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouse-transfers'] })
      toast({ title: 'Transfer deleted' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete',
        variant: 'destructive',
      })
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
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Warehouse Transfers</h1>
          <p className="text-muted-foreground">Move stock between warehouses and track transfers in transit.</p>
        </div>

        <div className="flex flex-wrap items-center gap-2 md:justify-end">
          <div className="relative w-full sm:w-56">
            <Icon icon="solar:magnifer-bold-duotone" className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search transfer number..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>
          <Select value={status} onValueChange={setStatus}>
            <SelectTrigger className="h-8 w-40 rounded-full text-xs">
              <SelectValue placeholder="All Status" />
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
          <HeaderActionButton icon="solar:add-circle-bold-duotone" asChild>
            <Link to="/inventory/transfers/new">
              New Transfer
            </Link>
          </HeaderActionButton>
        </div>
      </div>

      <Card>
        <CardContent className="pt-6">
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
                  <TableCell colSpan={8} className="py-12 text-center">
                    <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
                  </TableCell>
                </TableRow>
              ) : transfers.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                    <Icon icon="solar:transfer-horizontal-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
                    <p className="mt-4">No transfers found</p>
                  </TableCell>
                </TableRow>
              ) : (
                transfers.map((transfer: any) => (
                  <TableRow
                    key={transfer.id}
                    className="cursor-pointer"
                    onClick={() => navigate(`/inventory/transfers/${transfer.id}`)}
                  >
                    <TableCell className="font-medium">{transfer.transfer_number}</TableCell>
                    <TableCell>{transfer.from_warehouse?.name || '-'}</TableCell>
                    <TableCell>{transfer.to_warehouse?.name || '-'}</TableCell>
                    <TableCell>{new Date(transfer.transfer_date).toLocaleDateString()}</TableCell>
                    <TableCell>
                      {transfer.expected_date ? new Date(transfer.expected_date).toLocaleDateString() : '-'}
                    </TableCell>
                    <TableCell>
                      <Badge variant="secondary" className={`capitalize ${statusStyles[transfer.status] || ''}`}>
                        {transfer.status.replace('_', ' ')}
                      </Badge>
                    </TableCell>
                    <TableCell>{transfer.created_by?.name || '-'}</TableCell>
                    <TableCell className="text-right" onClick={(event) => event.stopPropagation()}>
                      <div className="flex justify-end gap-1.5">
                        <Button variant="outline" size="sm" className="h-7 w-7 p-0" asChild>
                          <Link to={`/inventory/transfers/${transfer.id}`} title="View" aria-label="View transfer">
                            <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                          </Link>
                        </Button>
                        {transfer.status === 'draft' && (
                          <Button variant="outline" size="sm" className="h-7 w-7 p-0" asChild>
                            <Link to={`/inventory/transfers/${transfer.id}/edit`} title="Edit" aria-label="Edit transfer">
                              <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                            </Link>
                          </Button>
                        )}
                        {['draft', 'cancelled'].includes(transfer.status) && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 w-7 p-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => {
                              if (confirm('Delete this transfer? This cannot be undone.')) {
                                deleteMutation.mutate(transfer.id)
                              }
                            }}
                            title="Delete"
                            aria-label="Delete transfer"
                          >
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                        )}
                        {!['completed', 'cancelled'].includes(transfer.status) && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 w-7 p-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => {
                              if (confirm('Cancel this transfer?')) {
                                cancelMutation.mutate(transfer.id)
                              }
                            }}
                            title="Cancel"
                            aria-label="Cancel transfer"
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
