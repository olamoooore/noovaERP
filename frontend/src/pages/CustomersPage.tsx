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
import { formatCurrency, formatDate, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import { SalesLineColumnMenu, SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { useBulkSelection } from '@/hooks/use-bulk-selection'
import { BulkSelectionNotice } from '@/components/ui/bulk-selection-notice'
import { SalesDocumentExportMenu, type SalesExportColumn } from '@/components/sales/SalesDocumentExportMenu'
import { RetractingActions } from '@/components/layout/RetractingActions'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'

const CUSTOMER_EXPORT_COLUMNS = [
  { key: 'code', label: 'Customer Code', group: 'Customer', default: true }, { key: 'name', label: 'Customer Name', group: 'Customer', default: true },
  { key: 'type', label: 'Type', group: 'Customer', default: true }, { key: 'company', label: 'Company', group: 'Customer' },
  { key: 'email', label: 'Email', group: 'Contact', default: true }, { key: 'phone', label: 'Phone', group: 'Contact', default: true }, { key: 'mobile', label: 'Mobile', group: 'Contact' },
  { key: 'tax_number', label: 'Tax Number', group: 'Customer' }, { key: 'billing_address', label: 'Billing Address', group: 'Address' }, { key: 'shipping_address', label: 'Shipping Address', group: 'Address' },
  { key: 'address_type', label: 'Address Type', group: 'Saved Addresses', default: true }, { key: 'address_label', label: 'Address Label', group: 'Saved Addresses', default: true },
  { key: 'address_street', label: 'Street', group: 'Saved Addresses', default: true }, { key: 'address_street2', label: 'Street 2', group: 'Saved Addresses', default: true },
  { key: 'address_city', label: 'City', group: 'Saved Addresses', default: true }, { key: 'address_state', label: 'State', group: 'Saved Addresses' },
  { key: 'address_zip', label: 'ZIP Code', group: 'Saved Addresses', default: true }, { key: 'address_country', label: 'Country', group: 'Saved Addresses', default: true },
  { key: 'address_is_default', label: 'Default Address', group: 'Saved Addresses' },
  { key: 'contact_name', label: 'Contact Name', group: 'Contacts', default: true }, { key: 'contact_category', label: 'Contact Category', group: 'Contacts', default: true },
  { key: 'contact_type', label: 'Contact Type', group: 'Contacts', default: true }, { key: 'contact_email', label: 'Contact Email', group: 'Contacts', default: true },
  { key: 'contact_phone', label: 'Contact Phone', group: 'Contacts', default: true }, { key: 'contact_mobile', label: 'Contact Mobile', group: 'Contacts', default: true },
  { key: 'contact_street', label: 'Contact Street', group: 'Contact Address' }, { key: 'contact_street2', label: 'Contact Street 2', group: 'Contact Address' },
  { key: 'contact_city', label: 'Contact City', group: 'Contact Address' }, { key: 'contact_state', label: 'Contact State', group: 'Contact Address' },
  { key: 'contact_zip', label: 'Contact ZIP Code', group: 'Contact Address' }, { key: 'contact_country', label: 'Contact Country', group: 'Contact Address' },
  { key: 'credit_limit', label: 'Credit Limit', group: 'Financial' }, { key: 'payment_terms', label: 'Payment Terms', group: 'Financial' },
  { key: 'total_outstanding', label: 'Outstanding', group: 'Financial', default: true }, { key: 'total_purchases', label: 'Total Purchases', group: 'Financial' }, { key: 'total_orders', label: 'Total Orders', group: 'Financial' },
  { key: 'categories', label: 'Categories', group: 'Customer' }, { key: 'salesperson', label: 'Salesperson', group: 'Customer' },
  { key: 'customer_since', label: 'Customer Since', group: 'Customer' }, { key: 'is_active', label: 'Active', group: 'Customer', default: true }, { key: 'notes', label: 'Notes', group: 'Customer' },
] as const satisfies readonly SalesExportColumn[]

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
  created_at?: string
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

const PAGE_SIZE = 20

export function CustomersPage() {
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  const [searchTerm, setSearchTerm] = useState('')
  const [hiddenCustomerColumns, setHiddenCustomerColumns] = useState<number[]>([])
  const [confirmAction, setConfirmAction] = useState<{ type: 'bulk-delete' | 'delete'; id?: number } | null>(null)
  const [filterType, setFilterType] = useState<string>('all')
  const [page, setPage] = useState(1)
  const [editMode, setEditMode] = useState(false)
  const [formData, setFormData] = useState<CustomerFormData>(initialFormData)
  const [customerInvoicesPage, setCustomerInvoicesPage] = useState(1)

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

  const customers = customersData?.data || []
  const {
    selectedIds, clearSelection, toggleSelect, togglePageSelection,
    selectAllMatching, allPageSelected, allMatchingSelected, isSelectingAll,
  } = useBulkSelection({
    pageIds: customers.map((customer: Customer) => customer.id),
    scopeKey: JSON.stringify({ search: searchTerm, type: filterType }),
    fetchAllIds: async () => {
      const response = await api.get('/customers', { params: { search: searchTerm || undefined, type: filterType === 'all' ? undefined : filterType, ids_only: 1 } })
      return response.data.data
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all customers' }),
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

  const { data: customerInvoicesData, isLoading: customerInvoicesLoading } = useQuery({
    queryKey: ['customer-invoices', selectedCustomer?.id, customerInvoicesPage],
    queryFn: async () => {
      const response = await api.get(`/customers/${selectedCustomer!.id}/invoices`, {
        params: { page: customerInvoicesPage, per_page: 10 },
      })
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
      clearSelection()
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
    setConfirmAction({ type: 'bulk-delete' })
    return
    bulkDeleteMutation.mutate(selectedIds)
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
    setCustomerInvoicesPage(1)
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
        <RetractingActions>
          <SalesDocumentExportMenu compact endpoint="/customers/export" filenamePrefix="customers" columns={CUSTOMER_EXPORT_COLUMNS} filters={{ search: searchTerm || undefined, type: filterType === 'all' ? undefined : filterType }} selectedIds={selectedIds} />
          <Button variant="outline" size="sm" className="h-8 rounded-full px-3 text-xs" asChild>
            <Link to="/customers/import">
              <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
              Import
            </Link>
          </Button>
        <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) setFormData(initialFormData) }}>
          <DialogTrigger asChild>
            <Button size="sm" className="h-8 rounded-full px-3 text-xs">
              <Icon icon="solar:add-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
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
        </RetractingActions>
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

      {/* Customers Table */}
      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center gap-2">
            <div className="mr-auto">
              <CardTitle>All Customers</CardTitle>
              <CardDescription>Click on a customer to view details</CardDescription>
            </div>

            <div className="relative w-full sm:w-64">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                placeholder="Search customers..."
                className="h-9 pl-9"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>

            <Select value={filterType} onValueChange={setFilterType}>
              <SelectTrigger className="h-9 w-[160px]">
                <SelectValue placeholder="Filter by type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="individual">Individuals</SelectItem>
                <SelectItem value="company">Companies</SelectItem>
              </SelectContent>
            </Select>

            {categories.length > 0 && (
              <div className="hidden items-center gap-2 xl:flex">
                {categories.slice(0, 3).map((cat: { id: number; name: string; color: string }) => (
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

            <SalesLineColumnMenu
              columns={listingColumns(['Select', 'Code', 'Name', 'Type', 'Email', 'Phone', 'Created Date', 'Outstanding', 'Total Purchases', 'Status', 'Actions'])}
              hidden={hiddenCustomerColumns}
              setHidden={setHiddenCustomerColumns}
            />
          </div>
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
                  <BulkSelectionNotice pageSize={customers.length} allPageSelected={allPageSelected} selectedCount={selectedIds.length} allMatchingSelected={allMatchingSelected} isSelectingAll={isSelectingAll} onSelectAllMatching={selectAllMatching} noun="customers" />
                  <Button size="sm" variant="destructive" onClick={handleBulkDelete} disabled={bulkDeleteMutation.isPending}>
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                    Delete Selected
                  </Button>
                </div>
              )}
                <SalesLineColumnVisibility
                  tableClass="customers-listing-columns"
                  columns={listingColumns(['Select', 'Code', 'Name', 'Type', 'Email', 'Phone', 'Created Date', 'Outstanding', 'Total Purchases', 'Status', 'Actions'])}
                  hiddenColumns={hiddenCustomerColumns}
                  onHiddenColumnsChange={setHiddenCustomerColumns}
                  showMenu={false}
                >
                <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-[40px]">
                    <input
                      type="checkbox"
                      checked={allPageSelected}
                      onChange={togglePageSelection}
                      aria-label="Select customers on this page"
                      className="h-4 w-4 rounded border-gray-300"
                    />
                  </TableHead>
                  <TableHead>Code</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Phone</TableHead>
                  <TableHead>Created Date</TableHead>
                  <TableHead className="text-right">Outstanding</TableHead>
                  <TableHead className="text-right">Total Purchases</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {customers.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={11} className="text-center py-10 text-muted-foreground">
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
                      <TableCell>{formatDate(customer.created_at)}</TableCell>
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
                </SalesLineColumnVisibility>
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
                    onClick={() => setConfirmAction({ type: 'delete', id: customerDetail.id })}
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
                      {customerInvoicesLoading ? (
                        <p className="text-muted-foreground text-center py-4">Loading invoices...</p>
                      ) : customerInvoicesData?.data?.length > 0 ? (
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
                            {customerInvoicesData.data.map((inv: any) => (
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
                      {customerInvoicesData?.last_page > 1 && (
                        <div className="flex items-center justify-between pt-4">
                          <span className="text-sm text-muted-foreground">
                            Page {customerInvoicesData.current_page} of {customerInvoicesData.last_page}
                          </span>
                          <div className="flex gap-2">
                            <Button size="sm" variant="outline" disabled={customerInvoicesPage <= 1} onClick={() => setCustomerInvoicesPage((p) => p - 1)}>
                              Previous
                            </Button>
                            <Button size="sm" variant="outline" disabled={customerInvoicesPage >= customerInvoicesData.last_page} onClick={() => setCustomerInvoicesPage((p) => p + 1)}>
                              Next
                            </Button>
                          </div>
                        </div>
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
      <ConfirmDialog
        open={confirmAction?.type === 'bulk-delete'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title={`Delete ${selectedIds.length} customer(s)?`}
        description="Customers that already have invoices or quotations will be skipped automatically."
        confirmLabel="Delete customers"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => {
          const ids = selectedIds
          setConfirmAction(null)
          bulkDeleteMutation.mutate(ids)
        }}
      />

      <ConfirmDialog
        open={confirmAction?.type === 'delete'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title="Delete this customer?"
        description="The customer record will be permanently removed. This cannot be undone."
        confirmLabel="Delete customer"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          const targetId = confirmAction?.id
          setConfirmAction(null)
          if (targetId) deleteMutation.mutate(targetId)
        }}
      />
    </div>
  )
}
