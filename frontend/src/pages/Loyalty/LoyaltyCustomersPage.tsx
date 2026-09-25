import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { toast } from '@/hooks/use-toast'
import loyaltyApi from '@/lib/loyaltyApi'
import { Pagination } from '@/components/ui/Pagination'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

export function LoyaltyCustomersPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const queryClient = useQueryClient()

  const { data: customers, isLoading } = useQuery({
    queryKey: ['loyalty-customers', search, page, pageSize],
    queryFn: async () => {
      const response = await loyaltyApi.getCustomers(search, page, pageSize)
      return response
    },
  })

  const customerRows = customers?.data || []
  const { sorted: sortedCustomers, sortKey, sortDir, toggle: toggleSort } = useSort<any>(customerRows)

  const enrollMutation = useMutation({
    mutationFn: async (customerId: number) => {
      return loyaltyApi.enrollCustomer(customerId)
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Customer enrolled in loyalty program',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customers'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to enroll customer',
        variant: 'destructive',
      })
    },
  })

  const getTierBadge = (tierName: string, isEnrolled: boolean) => {
    if (!isEnrolled) {
      return 'bg-gray-100 text-gray-600 border-gray-200'
    }
    const colors: Record<string, string> = {
      Platinum: 'bg-purple-100 text-purple-800 border-purple-200',
      Gold: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      Silver: 'bg-gray-100 text-gray-800 border-gray-200',
      Bronze: 'bg-orange-100 text-orange-800 border-orange-200',
    }
    return colors[tierName] || colors.Bronze
  }

  const getTierIcon = (tierName: string, isEnrolled: boolean) => {
    if (!isEnrolled) {
      return 'solar:user-cross-bold'
    }
    const icons: Record<string, string> = {
      Platinum: 'solar:crown-bold',
      Gold: 'solar:medal-star-bold',
      Silver: 'solar:medal-ribbons-star-bold',
      Bronze: 'solar:shield-user-bold',
    }
    return icons[tierName] || icons.Bronze
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Loyalty Customers</h1>
          <p className="text-muted-foreground">View and manage customer loyalty accounts</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle>Customer Loyalty Accounts</CardTitle>
            <div className="flex items-center gap-2">
              <Input
                placeholder="Search customers..."
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                className="w-64"
              />
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <SortableHead label="Customer" sortKey="customer_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                  <SortableHead label="Status" sortKey="tier_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                  <SortableHead label="Current Points" sortKey="current_points" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                  <SortableHead label="Lifetime Points" sortKey="lifetime_points" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                  <SortableHead label="Points Redeemed" sortKey="points_redeemed" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                  <TableHead className="text-center">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sortedCustomers.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                      No customers found
                    </TableCell>
                  </TableRow>
                )}
                {sortedCustomers.map((customer: any) => (
                  <TableRow key={customer.customer_id}>
                    <TableCell>
                      <div>
                        <p className="font-medium">{customer.customer_name}</p>
                        <p className="text-sm text-muted-foreground">{customer.customer_email}</p>
                        {customer.customer_phone && (
                          <p className="text-xs text-muted-foreground">{customer.customer_phone}</p>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge 
                        variant="outline" 
                        className={getTierBadge(customer.tier_name, customer.is_enrolled)}
                      >
                        <Icon 
                          icon={getTierIcon(customer.tier_name, customer.is_enrolled)} 
                          className="mr-1 h-3 w-3" 
                        />
                        {customer.is_enrolled ? customer.tier_name : 'Not Enrolled'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <span className={customer.is_enrolled ? 'font-semibold' : 'text-muted-foreground'}>
                        {customer.current_points.toLocaleString()}
                      </span>
                    </TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {customer.lifetime_points.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-right text-muted-foreground">
                      {customer.points_redeemed.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-center">
                      {customer.is_enrolled ? (
                        <Button variant="ghost" size="sm" asChild>
                          <Link to={`/loyalty/customers/${customer.customer_id}`}>
                            <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                          </Link>
                        </Button>
                      ) : (
                        <Button 
                          variant="outline" 
                          size="sm"
                          onClick={() => enrollMutation.mutate(customer.customer_id)}
                          disabled={enrollMutation.isPending}
                        >
                          {enrollMutation.isPending ? (
                            <Icon icon="solar:loading-bold" className="h-4 w-4 animate-spin mr-1" />
                          ) : (
                            <Icon icon="solar:user-plus-bold" className="h-4 w-4 mr-1" />
                          )}
                          Enroll
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
          {customers?.meta && (
            <Pagination
              page={customers.meta.current_page}
              totalPages={customers.meta.last_page}
              onPageChange={setPage}
              from={(customers.meta.current_page - 1) * customers.meta.per_page + 1}
              to={Math.min(customers.meta.current_page * customers.meta.per_page, customers.meta.total)}
              total={customers.meta.total}
              label="customers"
              pageSize={pageSize}
              onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
            />
          )}
        </CardContent>
      </Card>
    </div>
  )
}
