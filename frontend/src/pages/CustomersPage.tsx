import { useState, useEffect } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger, DialogFooter } from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'

interface Customer {
  id: number
  code: string
  type: 'individual' | 'company'
  name: string
  email: string
  phone: string
  mobile: string
  company: string
  tax_number: string
  website: string
  billing_address: string
  shipping_address: string
  credit_limit: number
  payment_terms: number
  total_outstanding: number
  total_purchases: number
  total_orders: number
  loyalty_points: number
  is_active: boolean
  customer_since: string
  categories: { id: number; name: string; color: string }[]
  salesperson?: { id: number; name: string }
  invoices_count?: number
  pos_orders_count?: number
}

interface CustomerFormData {
  code: string
  type: 'individual' | 'company'
  name: string
  email: string
  phone: string
  mobile: string
  company: string
  tax_number: string
  website: string
  billing_address: string
  shipping_address: string
  road_number: string
  road_name: string
  level_unit: string
  building_name: string
  state: string
  zip_code: string
  country: string
  credit_limit: string
  payment_terms: string
  preferred_contact: string
  marketing_opt_in: boolean
  notes: string
  category_ids: number[]
}

const initialFormData: CustomerFormData = {
  code: '',
  type: 'individual',
  name: '',
  email: '',
  phone: '',
  mobile: '',
  company: '',
  tax_number: '',
  website: '',
  billing_address: '',
  shipping_address: '',
  road_number: '',
  road_name: '',
  level_unit: '',
  building_name: '',
  state: '',
  zip_code: '',
  country: '',
  credit_limit: '',
  payment_terms: '30',
  preferred_contact: 'email',
  marketing_opt_in: false,
  notes: '',
  category_ids: [],
}

// Helper function to format address (handles both string and JSON formats)
function formatAddress(address: string | null | undefined): string {
  if (!address) return '';
  
  try {
    // Try to parse as JSON
    const parsed = JSON.parse(address);
    const parts = [];
    
    if (parsed.first_name || parsed.last_name) {
      parts.push(`${parsed.first_name || ''} ${parsed.last_name || ''}`.trim());
    }
    if (parsed.address) parts.push(parsed.address);
    if (parsed.city) parts.push(parsed.city);
    if (parsed.state) parts.push(parsed.state);
    if (parsed.postal_code) parts.push(parsed.postal_code);
    if (parsed.country) parts.push(parsed.country);
    
    return parts.filter(Boolean).join('\n');
  } catch {
    // If not JSON, return as-is (plain text address)
    return address;
  }
}

function formatStructuredAddress(customer: Partial<CustomerFormData>): string {
  const roadLine = [customer.road_number, customer.road_name].filter(Boolean).join(' ').trim()
  const unitLine = [customer.level_unit, customer.building_name].filter(Boolean).join(' ').trim()
  const localityLine = [customer.state, customer.zip_code, customer.country].filter(Boolean).join(', ').trim()

  return [unitLine, roadLine, localityLine].filter(Boolean).join('\n')
}

const PAGE_SIZE = 20

export function CustomersPage() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [filterType, setFilterType] = useState<string>('all')
  const [page, setPage] = useState(1)
  const [editMode, setEditMode] = useState(false)
  const [formData, setFormData] = useState<CustomerFormData>(initialFormData)
  const [selectedIds, setSelectedIds] = useState<number[]>([])

  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch customers — newest first, paginated
  const { data: customersData, isLoading } = useQuery({
    queryKey: ['customers', searchTerm, filterType, page],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchTerm) params.append('search', searchTerm)
      if (filterType && filterType !== 'all') params.append('type', filterType)
      params.append('sort_by', 'created_at')
      params.append('sort_dir', 'desc')
      params.append('per_page', String(PAGE_SIZE))
      params.append('page', String(page))
      const response = await api.get(`/customers?${params.toString()}`)
      return response.data
    },
    placeholderData: (prev) => prev,
  })

  // Reset to first page whenever the search/filter changes
  useEffect(() => { setPage(1) }, [searchTerm, filterType])

  // Overall customer statistics for the summary cards (independent of pagination)
  const { data: stats } = useQuery({
    queryKey: ['customer-statistics'],
    queryFn: async () => {
      const response = await api.get('/customers/statistics')
      return response.data
    },
  })

  // Fetch categories
  const { data: categoriesData } = useQuery({
    queryKey: ['customer-categories'],
    queryFn: async () => {
      const response = await api.get('/customer-categories')
      return response.data.categories || []
    },
  })

  // Fetch customer detail
  const { data: customerDetail } = useQuery({
    queryKey: ['customer', selectedCustomer?.id],
    queryFn: async () => {
      if (!selectedCustomer?.id) return null
      const response = await api.get(`/customers/${selectedCustomer.id}`)
      return response.data
    },
    enabled: !!selectedCustomer?.id && detailOpen,
  })

  const createMutation = useMutation({
    mutationFn: async (data: Partial<CustomerFormData>) => {
      const response = await api.post('/customers', {
        ...data,
        credit_limit: data.credit_limit ? parseFloat(data.credit_limit as string) : null,
        payment_terms: data.payment_terms ? parseInt(data.payment_terms as string) : 30,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      setOpen(false)
      setFormData(initialFormData)
      toast({ title: 'Customer created successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create customer',
        description: getApiErrorMessage(error),
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<CustomerFormData> }) => {
      const response = await api.put(`/customers/${id}`, {
        ...data,
        credit_limit: data.credit_limit ? parseFloat(data.credit_limit as string) : null,
        payment_terms: data.payment_terms ? parseInt(data.payment_terms as string) : 30,
      })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      queryClient.invalidateQueries({ queryKey: ['customer', selectedCustomer?.id] })
      setEditMode(false)
      toast({ title: 'Customer updated successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update customer',
        description: getApiErrorMessage(error),
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/customers/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      setDetailOpen(false)
      setSelectedCustomer(null)
      toast({ title: 'Customer deleted successfully' })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to delete customer' })
    },
  })

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post('/customers/bulk-delete', { ids })
      return response.data
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      setSelectedIds([])
      toast({
        title: `${data.deleted?.length || 0} customer(s) deleted`,
        description: data.skipped?.length ? `${data.skipped.length} skipped (has invoices/quotations)` : undefined,
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk delete failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleBulkDelete = () => {
    if (selectedIds.length === 0) return
    if (!window.confirm(`Delete ${selectedIds.length} customer(s)? Customers with invoices or quotations will be skipped.`)) return
    bulkDeleteMutation.mutate(selectedIds)
  }

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => prev.includes(id) ? prev.filter((sid) => sid !== id) : [...prev, id])
  }

  const toggleSelectAll = () => {
    setSelectedIds((prev) => prev.length === customers.length ? [] : customers.map((c: Customer) => c.id))
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (editMode && selectedCustomer) {
      updateMutation.mutate({ id: selectedCustomer.id, data: formData })
    } else {
      createMutation.mutate(formData)
    }
  }

  const openCustomerDetail = (customer: Customer) => {
    setSelectedCustomer(customer)
    setDetailOpen(true)
    setEditMode(false)
  }

  const startEdit = () => {
    if (customerDetail) {
      setFormData({
        code: customerDetail.code || '',
        type: customerDetail.type || 'individual',
        name: customerDetail.name || '',
        email: customerDetail.email || '',
        phone: customerDetail.phone || '',
        mobile: customerDetail.mobile || '',
        company: customerDetail.company || '',
        tax_number: customerDetail.tax_number || '',
        website: customerDetail.website || '',
        billing_address: formatAddress(customerDetail.billing_address),
        shipping_address: formatAddress(customerDetail.shipping_address),
        road_number: customerDetail.road_number || '',
        road_name: customerDetail.road_name || '',
        level_unit: customerDetail.level_unit || '',
        building_name: customerDetail.building_name || '',
        state: customerDetail.state || '',
        zip_code: customerDetail.zip_code || '',
        country: customerDetail.country || '',
        credit_limit: customerDetail.credit_limit?.toString() || '',
        payment_terms: customerDetail.payment_terms?.toString() || '30',
        preferred_contact: customerDetail.preferred_contact || 'email',
        marketing_opt_in: customerDetail.marketing_opt_in || false,
        notes: customerDetail.notes || '',
        category_ids: customerDetail.categories?.map((c: any) => c.id) || [],
      })
      setEditMode(true)
    }
  }

  const customers = customersData?.data || []
  const categories = categoriesData || []
  const totalPages = customersData?.last_page || 1
  const totalCustomers = customersData?.total || 0

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customers</h1>
          <p className="text-muted-foreground">Manage your customers, contacts and relationships</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="secondary" asChild>
            <Link to="/customers/import">
              <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
              Import
            </Link>
          </Button>
        <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setFormData(initialFormData) }}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Customer
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
            <DialogHeader>
              <DialogTitle>Add New Customer</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-6">
              <Tabs defaultValue="basic" className="w-full">
                <TabsList className="grid w-full grid-cols-3">
                  <TabsTrigger value="basic">Basic Info</TabsTrigger>
                  <TabsTrigger value="addresses">Addresses</TabsTrigger>
                  <TabsTrigger value="financial">Financial</TabsTrigger>
                </TabsList>
                
                <TabsContent value="basic" className="space-y-4 mt-4">
                  <div className="space-y-2">
                    <Label htmlFor="code">Customer Code</Label>
                    <Input
                      id="code"
                      value={formData.code}
                      onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                      placeholder="Auto-generated if left empty"
                      className="font-mono"
                    />
                    <p className="text-xs text-muted-foreground">Your own ID format — leave empty to auto-generate</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label>Customer Type</Label>
                      <Select 
                        value={formData.type} 
                        onValueChange={(v) => setFormData({ ...formData, type: v as 'individual' | 'company' })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="individual">Individual</SelectItem>
                          <SelectItem value="company">Company</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="name">Name *</Label>
                      <Input
                        id="name"
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        required
                      />
                    </div>
                  </div>

                  {formData.type === 'company' && (
                    <div className="grid grid-cols-2 gap-4">
                      <div className="space-y-2">
                        <Label htmlFor="company">Company Name</Label>
                        <Input
                          id="company"
                          value={formData.company}
                          onChange={(e) => setFormData({ ...formData, company: e.target.value })}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="tax_number">Tax Number</Label>
                        <Input
                          id="tax_number"
                          value={formData.tax_number}
                          onChange={(e) => setFormData({ ...formData, tax_number: e.target.value })}
                        />
                      </div>
                    </div>
                  )}

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="email">Email</Label>
                      <Input
                        id="email"
                        type="email"
                        value={formData.email}
                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="phone">Phone</Label>
                      <Input
                        id="phone"
                        value={formData.phone}
                        onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="mobile">Mobile</Label>
                      <Input
                        id="mobile"
                        value={formData.mobile}
                        onChange={(e) => setFormData({ ...formData, mobile: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="website">Website</Label>
                      <Input
                        id="website"
                        type="url"
                        placeholder="https://"
                        value={formData.website}
                        onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label>Preferred Contact Method</Label>
                    <Select 
                      value={formData.preferred_contact} 
                      onValueChange={(v) => setFormData({ ...formData, preferred_contact: v })}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="email">Email</SelectItem>
                        <SelectItem value="phone">Phone</SelectItem>
                        <SelectItem value="sms">SMS</SelectItem>
                        <SelectItem value="whatsapp">WhatsApp</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </TabsContent>

                <TabsContent value="addresses" className="space-y-4 mt-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="road_number">Road Name/Number</Label>
                      <Input
                        id="road_number"
                        value={formData.road_number}
                        onChange={(e) => setFormData({ ...formData, road_number: e.target.value })}
                        placeholder="e.g. 123 Orchard Road"
                      />
                    </div>
            
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="level_unit">Level & Unit</Label>
                      <Input
                        id="level_unit"
                        value={formData.level_unit}
                        onChange={(e) => setFormData({ ...formData, level_unit: e.target.value })}
                        placeholder="e.g. #12-34"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="building_name">Building Name</Label>
                      <Input
                        id="building_name"
                        value={formData.building_name}
                        onChange={(e) => setFormData({ ...formData, building_name: e.target.value })}
                        placeholder="e.g. Marina Bay Sands"
                      />
                    </div>
                  </div>
                  <div className="grid grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="state">State</Label>
                      <Input
                        id="state"
                        value={formData.state}
                        onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                        placeholder="e.g. Singapore"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="zip_code">ZIP / Postal Code</Label>
                      <Input
                        id="zip_code"
                        value={formData.zip_code}
                        onChange={(e) => setFormData({ ...formData, zip_code: e.target.value })}
                        placeholder="e.g. 018956"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="country">Country</Label>
                      <Input
                        id="country"
                        value={formData.country}
                        onChange={(e) => setFormData({ ...formData, country: e.target.value })}
                        placeholder="e.g. Singapore"
                      />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="billing_address">Billing Address (full text)</Label>
                    <Textarea
                      id="billing_address"
                      rows={2}
                      value={formData.billing_address}
                      onChange={(e) => setFormData({ ...formData, billing_address: e.target.value })}
                      placeholder="Auto-populated from structured fields, or enter manually"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="shipping_address">Shipping Address (full text)</Label>
                    <Textarea
                      id="shipping_address"
                      rows={2}
                      value={formData.shipping_address}
                      onChange={(e) => setFormData({ ...formData, shipping_address: e.target.value })}
                      placeholder="Auto-populated from structured fields, or enter manually"
                    />
                  </div>
                </TabsContent>

                <TabsContent value="financial" className="space-y-4 mt-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="credit_limit">Credit Limit</Label>
                      <Input
                        id="credit_limit"
                        type="number"
                        step="0.01"
                        value={formData.credit_limit}
                        onChange={(e) => setFormData({ ...formData, credit_limit: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="payment_terms">Payment Terms (days)</Label>
                      <Input
                        id="payment_terms"
                        type="number"
                        value={formData.payment_terms}
                        onChange={(e) => setFormData({ ...formData, payment_terms: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="notes">Notes</Label>
                    <Textarea
                      id="notes"
                      rows={3}
                      value={formData.notes}
                      onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                    />
                  </div>

                  <div className="flex items-center space-x-2">
                    <input
                      type="checkbox"
                      id="marketing_opt_in"
                      checked={formData.marketing_opt_in}
                      onChange={(e) => setFormData({ ...formData, marketing_opt_in: e.target.checked })}
                      className="h-4 w-4 rounded border-gray-300"
                    />
                    <Label htmlFor="marketing_opt_in" className="font-normal">
                      Opt-in for marketing communications
                    </Label>
                  </div>
                </TabsContent>
              </Tabs>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={createMutation.isPending}>
                  {createMutation.isPending ? 'Creating...' : 'Create Customer'}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Customers</p>
                <p className="text-2xl font-bold">{stats?.total_customers ?? totalCustomers}</p>
              </div>
              <Icon icon="solar:users-group-rounded-bold" className="h-8 w-8 text-primary" />
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Companies</p>
                <p className="text-2xl font-bold">{stats?.companies ?? 0}</p>
              </div>
              <Icon icon="solar:buildings-bold" className="h-8 w-8 text-blue-500" />
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Individuals</p>
                <p className="text-2xl font-bold">{stats?.individuals ?? 0}</p>
              </div>
              <Icon icon="solar:user-bold" className="h-8 w-8 text-green-500" />
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Outstanding</p>
                <p className="text-2xl font-bold">
                  {formatCurrency(stats?.total_outstanding ?? 0)}
                </p>
              </div>
              <Icon icon="solar:wallet-money-bold" className="h-8 w-8 text-orange-500" />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Filters & Search */}
      <div className="flex items-center gap-4">
        <div className="relative flex-1 max-w-sm">
          <Icon icon="solar:magnifer-linear" className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search customers..."
            className="pl-10"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <Select value={filterType} onValueChange={setFilterType}>
          <SelectTrigger className="w-[180px]">
            <SelectValue placeholder="Filter by type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Types</SelectItem>
            <SelectItem value="individual">Individuals</SelectItem>
            <SelectItem value="company">Companies</SelectItem>
          </SelectContent>
        </Select>
        {categories.length > 0 && (
          <div className="flex items-center gap-2">
            {categories.slice(0, 5).map((cat: { id: number; name: string; color: string }) => (
              <Badge 
                key={cat.id} 
                variant="outline" 
                className="cursor-pointer hover:bg-muted"
                style={{ borderColor: cat.color, color: cat.color }}
              >
                {cat.name}
              </Badge>
            ))}
          </div>
        )}
      </div>

      {/* Customers Table */}
      <Card>
        <CardHeader>
          <CardTitle>All Customers</CardTitle>
          <CardDescription>Click on a customer to view details</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              {selectedIds.length > 0 && (
                <div className="flex items-center justify-between rounded-lg border border-primary/20 bg-primary/5 px-4 py-2">
                  <span className="text-sm font-medium">{selectedIds.length} customer(s) selected</span>
                  <Button size="sm" variant="destructive" onClick={handleBulkDelete} disabled={bulkDeleteMutation.isPending}>
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                    Delete Selected
                  </Button>
                </div>
              )}
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[40px]">
                    <input
                      type="checkbox"
                      checked={customers.length > 0 && selectedIds.length === customers.length}
                      onChange={toggleSelectAll}
                      className="h-4 w-4 rounded border-gray-300"
                    />
                  </TableHead>
                  <TableHead>Code</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Phone</TableHead>
                  <TableHead className="text-right">Outstanding</TableHead>
                  <TableHead className="text-right">Total Purchases</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {customers.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={10} className="text-center py-10 text-muted-foreground">
                      No customers found
                    </TableCell>
                  </TableRow>
                ) : (
                  customers.map((customer: Customer) => (
                    <TableRow 
                      key={customer.id} 
                      className="cursor-pointer hover:bg-muted/50"
                      onClick={() => navigate(`/customers/${customer.id}`)}
                    >
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <input
                          type="checkbox"
                          checked={selectedIds.includes(customer.id)}
                          onChange={() => toggleSelect(customer.id)}
                          className="h-4 w-4 rounded border-gray-300"
                        />
                      </TableCell>
                      <TableCell className="font-mono text-sm">{customer.code || '-'}</TableCell>
                      <TableCell>
                        <div>
                          <div className="font-medium">{customer.name}</div>
                          {customer.company && (
                            <div className="text-sm text-muted-foreground">{customer.company}</div>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant={customer.type === 'company' ? 'default' : 'secondary'}>
                          {customer.type === 'company' ? 'Company' : 'Individual'}
                        </Badge>
                      </TableCell>
                      <TableCell>{customer.email || '-'}</TableCell>
                      <TableCell>{customer.phone || customer.mobile || '-'}</TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(customer.total_outstanding || 0)}
                      </TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(customer.total_purchases || 0)}
                      </TableCell>
                      <TableCell>
                        <Badge variant={customer.is_active ? 'default' : 'secondary'}>
                          {customer.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={(e) => {
                              e.stopPropagation()
                              openCustomerDetail(customer)
                            }}
                            title="Quick View"
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={(e) => {
                              e.stopPropagation()
                              navigate(`/customers/${customer.id}`)
                            }}
                            title="Full Details"
                          >
                            <Icon icon="solar:arrow-right-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
            </>
          )}

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between pt-4 border-t mt-4">
              <div className="text-sm text-muted-foreground">
                Showing {customersData?.from ?? 0}–{customersData?.to ?? 0} of {totalCustomers} customers
              </div>
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  <Icon icon="solar:arrow-left-linear" className="h-4 w-4 mr-1" />
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {page} of {totalPages}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                >
                  Next
                  <Icon icon="solar:arrow-right-linear" className="h-4 w-4 ml-1" />
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Customer Detail Dialog */}
      <Dialog open={detailOpen} onOpenChange={(o) => { setDetailOpen(o); if (!o) { setEditMode(false); setSelectedCustomer(null); } }}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center justify-between">
              <span>{editMode ? 'Edit Customer' : 'Customer Details'}</span>
              {!editMode && customerDetail && (
                <div className="flex items-center gap-2">
                  <Button variant="outline" size="sm" onClick={startEdit}>
                    <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                    Edit
                  </Button>
                  <Button 
                    variant="destructive" 
                    size="sm"
                    onClick={() => {
                      if (confirm('Are you sure you want to delete this customer?')) {
                        deleteMutation.mutate(customerDetail.id)
                      }
                    }}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                    Delete
                  </Button>
                </div>
              )}
            </DialogTitle>
          </DialogHeader>

          {customerDetail && !editMode ? (
            <Tabs defaultValue="overview" className="w-full">
              <TabsList>
                <TabsTrigger value="overview">Overview</TabsTrigger>
                <TabsTrigger value="transactions">Transactions</TabsTrigger>
              </TabsList>

              <TabsContent value="overview" className="space-y-6 mt-4">
                <div className="grid grid-cols-2 gap-6">
                  {/* Basic Info */}
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Basic Information</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Code</span>
                        <span className="font-mono">{customerDetail.code}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Type</span>
                        <Badge variant={customerDetail.type === 'company' ? 'default' : 'secondary'}>
                          {customerDetail.type === 'company' ? 'Company' : 'Individual'}
                        </Badge>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Name</span>
                        <span>{customerDetail.name}</span>
                      </div>
                      {customerDetail.company && (
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Company</span>
                          <span>{customerDetail.company}</span>
                        </div>
                      )}
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Email</span>
                        <span>{customerDetail.email || '-'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Phone</span>
                        <span>{customerDetail.phone || '-'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Mobile</span>
                        <span>{customerDetail.mobile || '-'}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Customer Since</span>
                        <span>{customerDetail.customer_since || '-'}</span>
                      </div>
                    </CardContent>
                  </Card>

                  {/* Financial Info */}
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Financial Summary</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Credit Limit</span>
                        <span>{formatCurrency(customerDetail.credit_limit || 0)}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Outstanding</span>
                        <span className={customerDetail.total_outstanding > 0 ? 'text-orange-600 font-medium' : ''}>
                          {formatCurrency(customerDetail.total_outstanding || 0)}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Total Purchases</span>
                        <span className="font-medium">{formatCurrency(customerDetail.total_purchases || 0)}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Total Orders</span>
                        <span>{customerDetail.total_orders || 0}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Avg. Order Value</span>
                        <span>{formatCurrency(customerDetail.average_order_value || 0)}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Payment Terms</span>
                        <span>{customerDetail.payment_terms || 30} days</span>
                      </div>
                    </CardContent>
                  </Card>
                </div>

                {/* Addresses */}
                <div className="grid grid-cols-2 gap-6">
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Billing Address</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <p className="text-sm whitespace-pre-line">{formatAddress(customerDetail.billing_address) || 'No billing address set'}</p>
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Shipping Address</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <p className="text-sm whitespace-pre-line">{formatAddress(customerDetail.shipping_address) || 'No shipping address set'}</p>
                    </CardContent>
                  </Card>
                </div>

                {formatStructuredAddress(customerDetail) && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Structured Address</CardTitle>
                      <CardDescription>Road/Unit details from existing customer profile</CardDescription>
                    </CardHeader>
                    <CardContent>
                      <p className="text-sm whitespace-pre-line">{formatStructuredAddress(customerDetail)}</p>
                    </CardContent>
                  </Card>
                )}

                {/* Notes */}
                {customerDetail.notes && (
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Notes</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <p className="text-sm">{customerDetail.notes}</p>
                    </CardContent>
                  </Card>
                )}
              </TabsContent>

              <TabsContent value="transactions" className="mt-4">
                <div className="space-y-4">
                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Recent Invoices</CardTitle>
                    </CardHeader>
                    <CardContent>
                      {customerDetail.invoices?.length > 0 ? (
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Invoice #</TableHead>
                              <TableHead>Date</TableHead>
                              <TableHead>Status</TableHead>
                              <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {customerDetail.invoices.map((inv: any) => (
                              <TableRow key={inv.id}>
                                <TableCell>{inv.invoice_number}</TableCell>
                                <TableCell>{inv.issue_date}</TableCell>
                                <TableCell>
                                  <Badge>{inv.status}</Badge>
                                </TableCell>
                                <TableCell className="text-right">{formatCurrency(inv.total)}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      ) : (
                        <p className="text-muted-foreground text-center py-4">No invoices yet</p>
                      )}
                    </CardContent>
                  </Card>

                  <Card>
                    <CardHeader>
                      <CardTitle className="text-lg">Recent POS Orders</CardTitle>
                    </CardHeader>
                    <CardContent>
                      {customerDetail.pos_orders?.length > 0 ? (
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Order #</TableHead>
                              <TableHead>Date</TableHead>
                              <TableHead>Status</TableHead>
                              <TableHead className="text-right">Amount</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {customerDetail.pos_orders.map((order: any) => (
                              <TableRow key={order.id}>
                                <TableCell>{order.order_number}</TableCell>
                                <TableCell>{new Date(order.created_at).toLocaleDateString()}</TableCell>
                                <TableCell>
                                  <Badge>{order.status}</Badge>
                                </TableCell>
                                <TableCell className="text-right">{formatCurrency(order.total)}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      ) : (
                        <p className="text-muted-foreground text-center py-4">No POS orders yet</p>
                      )}
                    </CardContent>
                  </Card>
                </div>
              </TabsContent>
            </Tabs>
          ) : editMode ? (
            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Same form as create but for editing */}
              <Tabs defaultValue="basic" className="w-full">
                <TabsList className="grid w-full grid-cols-3">
                  <TabsTrigger value="basic">Basic Info</TabsTrigger>
                  <TabsTrigger value="addresses">Addresses</TabsTrigger>
                  <TabsTrigger value="financial">Financial</TabsTrigger>
                </TabsList>
                
                <TabsContent value="basic" className="space-y-4 mt-4">
                  <div className="space-y-2">
                    <Label htmlFor="edit-code">Customer Code</Label>
                    <Input
                      id="edit-code"
                      value={formData.code}
                      onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                      placeholder="e.g., CUST-001"
                      className="font-mono"
                    />
                    <p className="text-xs text-muted-foreground">Your own ID format — leave empty to keep auto-generated</p>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label>Customer Type</Label>
                      <Select 
                        value={formData.type} 
                        onValueChange={(v) => setFormData({ ...formData, type: v as 'individual' | 'company' })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="individual">Individual</SelectItem>
                          <SelectItem value="company">Company</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-name">Name *</Label>
                      <Input
                        id="edit-name"
                        value={formData.name}
                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                        required
                      />
                    </div>
                  </div>

                  {formData.type === 'company' && (
                    <div className="grid grid-cols-2 gap-4">
                      <div className="space-y-2">
                        <Label htmlFor="edit-company">Company Name</Label>
                        <Input
                          id="edit-company"
                          value={formData.company}
                          onChange={(e) => setFormData({ ...formData, company: e.target.value })}
                        />
                      </div>
                      <div className="space-y-2">
                        <Label htmlFor="edit-tax">Tax Number</Label>
                        <Input
                          id="edit-tax"
                          value={formData.tax_number}
                          onChange={(e) => setFormData({ ...formData, tax_number: e.target.value })}
                        />
                      </div>
                    </div>
                  )}

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-email">Email</Label>
                      <Input
                        id="edit-email"
                        type="email"
                        value={formData.email}
                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-phone">Phone</Label>
                      <Input
                        id="edit-phone"
                        value={formData.phone}
                        onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-mobile">Mobile</Label>
                      <Input
                        id="edit-mobile"
                        value={formData.mobile}
                        onChange={(e) => setFormData({ ...formData, mobile: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-website">Website</Label>
                      <Input
                        id="edit-website"
                        type="url"
                        value={formData.website}
                        onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                      />
                    </div>
                  </div>
                </TabsContent>

                <TabsContent value="addresses" className="space-y-4 mt-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-road-number">Road Name/Number</Label>
                      <Input
                        id="edit-road-number"
                        value={formData.road_number}
                        onChange={(e) => setFormData({ ...formData, road_number: e.target.value })}
                        placeholder="e.g. 123 Orchard Road"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-level-unit">Level & Unit</Label>
                      <Input
                        id="edit-level-unit"
                        value={formData.level_unit}
                        onChange={(e) => setFormData({ ...formData, level_unit: e.target.value })}
                        placeholder="e.g. #12-34"
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-3 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-building-name">Building Name</Label>
                      <Input
                        id="edit-building-name"
                        value={formData.building_name}
                        onChange={(e) => setFormData({ ...formData, building_name: e.target.value })}
                        placeholder="e.g. Marina Bay Sands"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-state">State</Label>
                      <Input
                        id="edit-state"
                        value={formData.state}
                        onChange={(e) => setFormData({ ...formData, state: e.target.value })}
                        placeholder="e.g. Singapore"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-zip-code">ZIP / Postal Code</Label>
                      <Input
                        id="edit-zip-code"
                        value={formData.zip_code}
                        onChange={(e) => setFormData({ ...formData, zip_code: e.target.value })}
                        placeholder="e.g. 018956"
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-country">Country</Label>
                    <Input
                      id="edit-country"
                      value={formData.country}
                      onChange={(e) => setFormData({ ...formData, country: e.target.value })}
                      placeholder="e.g. Singapore"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-billing">Billing Address</Label>
                    <Textarea
                      id="edit-billing"
                      rows={3}
                      value={formData.billing_address}
                      onChange={(e) => setFormData({ ...formData, billing_address: e.target.value })}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="edit-shipping">Shipping Address</Label>
                    <Textarea
                      id="edit-shipping"
                      rows={3}
                      value={formData.shipping_address}
                      onChange={(e) => setFormData({ ...formData, shipping_address: e.target.value })}
                    />
                  </div>
                </TabsContent>

                <TabsContent value="financial" className="space-y-4 mt-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-credit">Credit Limit</Label>
                      <Input
                        id="edit-credit"
                        type="number"
                        step="0.01"
                        value={formData.credit_limit}
                        onChange={(e) => setFormData({ ...formData, credit_limit: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="edit-terms">Payment Terms (days)</Label>
                      <Input
                        id="edit-terms"
                        type="number"
                        value={formData.payment_terms}
                        onChange={(e) => setFormData({ ...formData, payment_terms: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-notes">Notes</Label>
                    <Textarea
                      id="edit-notes"
                      rows={3}
                      value={formData.notes}
                      onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                    />
                  </div>
                </TabsContent>
              </Tabs>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setEditMode(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={updateMutation.isPending}>
                  {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
                </Button>
              </DialogFooter>
            </form>
          ) : (
            <div className="flex items-center justify-center py-10">
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
