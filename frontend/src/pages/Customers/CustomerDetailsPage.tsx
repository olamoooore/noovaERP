import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { format } from 'date-fns'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Separator } from '@/components/ui/separator'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, cn, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import { PageHeader } from '@/components/layout/PageHeader'
import { TagEditor } from '@/components/tags/TagEditor'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

const numericPhoneValue = (value: string) => value.replace(/\D/g, '')

const formatLegacyAddress = (address: string) => {
  if (!address) return null
  try {
    // If it looks like JSON starts with {
    if (address.trim().startsWith('{')) {
      const parsed = JSON.parse(address)
      const parts = []
      
      // Add name if present
      if (parsed.first_name || parsed.last_name) {
        parts.push(`${parsed.first_name || ''} ${parsed.last_name || ''}`.trim())
      }
      
      // Add address lines
      if (parsed.address || parsed.street) parts.push(parsed.address || parsed.street)
      if (parsed.street2) parts.push(parsed.street2)
      if (parsed.city) parts.push(parsed.city)
      if (parsed.state) parts.push(parsed.state)
      if (parsed.postal_code || parsed.zip) parts.push(parsed.postal_code || parsed.zip)
      if (parsed.country) parts.push(parsed.country)
      
      if (parts.length > 0) return parts.join('\n')
      
      // Fallback: join values
      return Object.values(parsed).filter(v => typeof v === 'string').join('\n')
    }
  } catch (e) {
    // Ignore error, treat as string
  }
  return address
}

interface Customer {
  id: number
  code: string
  type: 'individual' | 'company'
  name: string
  display_name: string
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
  average_order_value: number
  loyalty_points: number
  is_active: boolean
  marketing_opt_in: boolean
  preferred_contact: string
  customer_since: string
  last_interaction_at: string
  notes: string
  date_of_birth: string
  gender: string
  categories: { id: number; name: string; color: string }[]
  salesperson?: { id: number; name: string }
  price_list?: { id: number; name: string }
  addresses: CustomerAddress[]
  customer_contacts: CustomerContact[]
  loyalty_balances: LoyaltyBalance[]
  invoices: Invoice[]
  pos_orders: PosOrder[]
  web_orders: WebOrder[]
  webOrders?: WebOrder[]
}

/**
 * Issue #239: the edit form must be able to clear credit_limit to null
 * ("no limit configured", per #144) — Partial<Customer> alone types it number.
 */
type CustomerEditForm = Partial<Omit<Customer, 'credit_limit'>> & { credit_limit?: number | null }

interface CustomerAddress {  id: number
  type: 'billing' | 'shipping' | 'other'
  label: string
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
  is_default: boolean
}

interface CustomerContact {
  id: number
  name: string
  job_title: string
  contact_category: string
  contact_type: string
  email: string
  phone: string
  mobile: string
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
  is_primary: boolean
  notes: string
}

interface LoyaltyBalance {
  id: number
  points_balance: number
  points_earned_total: number
  points_redeemed_total: number
  program: { id: number; name: string }
}

interface Invoice {
  id: number
  invoice_number: string
  status: string
  total: number
  balance: number
  invoice_date: string
  due_date: string
}

interface PosOrderItem {
  id: number
  name: string
  product_name?: string
  product_sku?: string
  sku: string
  quantity: number
  unit_price: number
  tax_rate: number
  tax_amount: number
  line_total: number
  product?: { id: number; name: string }
  variant?: { id: number; name: string }
}

interface PosOrder {
  id: number
  order_number: string
  status: string
  subtotal: number
  discount_amount: number
  tax_amount: number
  total: number
  amount_paid: number
  payment_method: string
  notes: string
  paid_at: string
  created_at: string
  items?: PosOrderItem[]
}

interface WebOrderItem {
    id: number
    product_name: string
    product_sku: string
    quantity: number
    unit_price: number
    line_total: number
}

interface WebOrder {
  id: number
  order_number: string
  status: string
  total: number
  created_at: string
  payment_status: string
  items?: WebOrderItem[]
}

interface LoyaltyTransaction {
  id: number
  type: 'earn' | 'redeem' | 'expire' | 'adjust'
  points: number
  balance_after: number
  reference_type: string
  notes: string
  created_at: string
}

export function CustomerDetailsPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [activeTab, setActiveTab] = useState('overview')
  const [addressDialogOpen, setAddressDialogOpen] = useState(false)
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null)
  const [contactDialogOpen, setContactDialogOpen] = useState(false)
  const [editingContactId, setEditingContactId] = useState<number | null>(null)
  const [loyaltyDialogOpen, setLoyaltyDialogOpen] = useState(false)
  const [noteDialogOpen, setNoteDialogOpen] = useState(false)
  const [editDialogOpen, setEditDialogOpen] = useState(false)
  const [emailDialogOpen, setEmailDialogOpen] = useState(false)
  const [orderDialogOpen, setOrderDialogOpen] = useState(false)
  const [selectedOrder, setSelectedOrder] = useState<PosOrder | null>(null)
  
  // Form states
  const [addressForm, setAddressForm] = useState<Partial<CustomerAddress>>({})
  const [contactForm, setContactForm] = useState<Partial<CustomerContact>>({})
  const [loyaltyAdjustment, setLoyaltyAdjustment] = useState({ points: 0, notes: '' })
  const [customerNote, setCustomerNote] = useState('')
  const [editForm, setEditForm] = useState<CustomerEditForm>({})
  const [emailForm, setEmailForm] = useState({ subject: '', body: '' })
  const [statementAsOf, setStatementAsOf] = useState(format(new Date(), 'yyyy-MM-dd'))
  const [statementDialogOpen, setStatementDialogOpen] = useState(false)

  // Fetch customer details
  const { data: customer, isLoading } = useQuery<Customer>({
    queryKey: ['customer', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch loyalty history
  const { data: loyaltyData } = useQuery({
    queryKey: ['customer-loyalty', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/loyalty`)
      return response.data
    },
    enabled: !!id && activeTab === 'loyalty',
  })

  // Fetch activity timeline
  const { data: activityData } = useQuery({
    queryKey: ['customer-activity', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/activity?limit=50`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch customer statistics
  const { data: statsData } = useQuery({
    queryKey: ['customer-statistics', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/statistics`)
      return response.data
    },
    enabled: !!id && activeTab === 'financial',
  })

  // Fetch AR aging data for this customer
  const { data: agingData } = useQuery({
    queryKey: ['customer-aging', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/aging`)
      return response.data
    },
    enabled: !!id && activeTab === 'invoices',
  })

  // Update customer mutation
  const updateMutation = useMutation({
    mutationFn: async (data: CustomerEditForm) => {
      const response = await api.put(`/customers/${id}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
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

  // Add address mutation
  const addAddressMutation = useMutation({
    mutationFn: async (data: Partial<CustomerAddress>) => {
      const response = await api.post(`/customers/${id}/addresses`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setAddressDialogOpen(false)
      setAddressForm({})
      toast({ title: 'Address added successfully' })
    },
  })

  // Delete address mutation
  const deleteAddressMutation = useMutation({
    mutationFn: async (addressId: number) => {
      await api.delete(`/customers/${id}/addresses/${addressId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      toast({ title: 'Address deleted successfully' })
    },
  })

  const updateAddressMutation = useMutation({
    mutationFn: async ({ addressId, data }: { addressId: number; data: Partial<CustomerAddress> }) => {
      const response = await api.put(`/customers/${id}/addresses/${addressId}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setAddressDialogOpen(false)
      setEditingAddressId(null)
      setAddressForm({})
      toast({ title: 'Address updated successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update address',
        description: getApiErrorMessage(error),
      })
    },
  })

  // Add contact mutation
  const addContactMutation = useMutation({
    mutationFn: async (data: Partial<CustomerContact>) => {
      const response = await api.post(`/customers/${id}/contacts`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setContactDialogOpen(false)
      setContactForm({})
      toast({ title: 'Contact added successfully' })
    },
  })

  const updateContactMutation = useMutation({
    mutationFn: async ({ contactId, data }: { contactId: number; data: Partial<CustomerContact> }) => {
      const response = await api.put(`/customers/${id}/contacts/${contactId}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setContactDialogOpen(false)
      setEditingContactId(null)
      setContactForm({})
      toast({ title: 'Contact updated successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update contact',
        description: getApiErrorMessage(error),
      })
    },
  })

  // Delete contact mutation
  const deleteContactMutation = useMutation({
    mutationFn: async (contactId: number) => {
      await api.delete(`/customers/${id}/contacts/${contactId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      toast({ title: 'Contact deleted successfully' })
    },
  })

  // Recalculate stats mutation
  const recalculateStatsMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/customers/${id}/recalculate-stats`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      queryClient.invalidateQueries({ queryKey: ['customer-statistics', id] })
      toast({ title: 'Customer statistics recalculated' })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to recalculate stats' })
    },
  })

  // Toggle customer active status
  const handleToggleActive = () => {
    updateMutation.mutate({ is_active: !customer?.is_active })
  }

  // Adjust loyalty mutation
  const adjustLoyaltyMutation = useMutation({
    mutationFn: async (data: { points: number; notes: string }) => {
      const response = await api.post(`/customers/${id}/loyalty/adjust`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      queryClient.invalidateQueries({ queryKey: ['customer-loyalty', id] })
      setLoyaltyDialogOpen(false)
      setLoyaltyAdjustment({ points: 0, notes: '' })
      toast({ title: 'Loyalty points adjusted successfully' })
    },
  })

  // Update notes mutation
  const updateNotesMutation = useMutation({
    mutationFn: async (notes: string) => {
      const response = await api.put(`/customers/${id}`, { notes })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setNoteDialogOpen(false)
      toast({ title: 'Notes updated successfully' })
    },
  })

  // Send email mutation
  const sendEmailMutation = useMutation({
    mutationFn: async (data: { subject: string; body: string }) => {
      const response = await api.post(`/customers/${id}/send-email`, data)
      return response.data
    },
    onSuccess: () => {
      setEmailDialogOpen(false)
      setEmailForm({ subject: '', body: '' })
      toast({ title: 'Email sent successfully' })
    },
    onError: (error: any) => {
      toast({ 
        variant: 'destructive', 
        title: 'Failed to send email',
        description: error.response?.data?.message || 'Please try again later'
      })
    },
  })

  // Open edit dialog with current values
  const openEditDialog = () => {
    if (customer) {
      setEditForm({
        code: customer.code,
        name: customer.name,
        display_name: customer.display_name,
        email: customer.email,
        phone: numericPhoneValue(customer.phone || ''),
        mobile: numericPhoneValue(customer.mobile || ''),
        company: customer.company,
        tax_number: customer.tax_number,
        website: customer.website,
        type: customer.type,
        credit_limit: customer.credit_limit,
        payment_terms: customer.payment_terms,
        billing_address: customer.billing_address,
        shipping_address: customer.shipping_address,
        date_of_birth: customer.date_of_birth,
        gender: customer.gender,
        marketing_opt_in: customer.marketing_opt_in,
        preferred_contact: customer.preferred_contact,
      })
      setEditDialogOpen(true)
    }
  }

  // Handle edit form submit
  const handleEditSubmit = () => {
    updateMutation.mutate(editForm, {
      onSuccess: () => {
        setEditDialogOpen(false)
      }
    })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  if (!customer) {
    return (
      <div className="flex flex-col items-center justify-center h-screen gap-4">
        <Icon icon="solar:user-cross-bold" className="h-16 w-16 text-muted-foreground" />
        <h2 className="text-xl font-semibold">Customer not found</h2>
        <Button onClick={() => navigate('/customers')}>Back to Customers</Button>
      </div>
    )
  }

  const getInitials = (name: string) => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
  }

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      draft: 'bg-gray-500',
      pending: 'bg-yellow-500',
      paid: 'bg-green-500',
      sent: 'bg-blue-500',
      overdue: 'bg-red-500',
      cancelled: 'bg-gray-400',
    }
    return colors[status] || 'bg-gray-500'
  }

  const creditUsedPercent = customer.credit_limit > 0 
    ? (customer.total_outstanding / customer.credit_limit) * 100 
    : 0

  return (
    <div className="space-y-6 pb-10">
      {/* Header */}
      <PageHeader
        title={customer.display_name || customer.name}
        onBack={() => navigate('/customers')}
        actions={<>
          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold-duotone"
            title="Recalculate statistics"
            loading={recalculateStatsMutation.isPending}
            onClick={() => recalculateStatsMutation.mutate()}
            disabled={recalculateStatsMutation.isPending}
          >
            Recalculate
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon={customer.is_active ? 'solar:pause-circle-bold-duotone' : 'solar:play-circle-bold-duotone'}
            loading={updateMutation.isPending}
            onClick={handleToggleActive}
            disabled={updateMutation.isPending}
          >
            {customer.is_active ? 'Deactivate' : 'Activate'}
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon="solar:notes-bold-duotone"
            onClick={() => setNoteDialogOpen(true)}
          >
            Notes
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon="solar:letter-bold-duotone"
            onClick={() => setEmailDialogOpen(true)}
            disabled={!customer.email}
            title={!customer.email ? 'No email address' : 'Send email'}
          >
            Email
          </HeaderActionButton>
          <HeaderActionButton icon="solar:pen-bold-duotone" onClick={openEditDialog}>
            Edit
          </HeaderActionButton>
        </>}
      >
        <div className="mt-3 flex items-start gap-4">
          <Avatar className="h-14 w-14 shrink-0">
            <AvatarFallback className="bg-primary text-lg text-primary-foreground">
              {getInitials(customer.display_name || customer.name)}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0 flex-1 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className="gap-1 font-mono text-[11px]">
                <Icon icon="solar:tag-horizontal-bold-duotone" className="h-3 w-3" />
                {customer.code}
              </Badge>
              <Badge variant="outline" className="gap-1 text-[11px]">
                <Icon
                  icon={customer.type === 'company' ? 'solar:buildings-2-bold-duotone' : 'solar:user-bold-duotone'}
                  className="h-3 w-3"
                />
                {customer.type === 'company' ? 'Company' : 'Individual'}
              </Badge>
              {customer.is_active ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  Active
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                  <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
                  Inactive
                </span>
              )}
              {customer.customer_since && (
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Icon icon="solar:calendar-bold-duotone" className="h-3.5 w-3.5" />
                  Customer since {format(new Date(customer.customer_since), 'MMM yyyy')}
                </span>
              )}
            </div>

            {customer.categories?.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5">
                {customer.categories.map((cat) => (
                  <span
                    key={cat.id}
                    className="rounded-full border px-2 py-0.5 text-[10px] font-medium"
                    style={{ borderColor: cat.color, color: cat.color }}
                  >
                    {cat.name}
                  </span>
                ))}
              </div>
            )}
            <TagEditor subjectType="customer" subjectId={id!} />
          </div>
        </div>
      </PageHeader>

      {/* Quick Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Purchases</p>
                <p className="text-2xl font-bold">{formatCurrency(customer.total_purchases || 0)}</p>
              </div>
              <div className="p-3 rounded-full bg-green-100">
                <Icon icon="solar:wallet-money-bold" className="h-6 w-6 text-green-600" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Total Orders</p>
                <p className="text-2xl font-bold">{customer.total_orders || 0}</p>
              </div>
              <div className="p-3 rounded-full bg-blue-100">
                <Icon icon="solar:cart-large-2-bold" className="h-6 w-6 text-blue-600" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Avg. Order Value</p>
                <p className="text-2xl font-bold">{formatCurrency(customer.average_order_value || 0)}</p>
              </div>
              <div className="p-3 rounded-full bg-purple-100">
                <Icon icon="solar:chart-2-bold" className="h-6 w-6 text-purple-600" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Outstanding</p>
                <p className="text-2xl font-bold text-orange-600">{formatCurrency(customer.total_outstanding || 0)}</p>
              </div>
              <div className="p-3 rounded-full bg-orange-100">
                <Icon icon="solar:bill-list-bold" className="h-6 w-6 text-orange-600" />
              </div>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-muted-foreground">Loyalty Points</p>
                <p className="text-2xl font-bold">{customer.loyalty_points || 0}</p>
              </div>
              <div className="p-3 rounded-full bg-yellow-100">
                <Icon icon="solar:star-bold" className="h-6 w-6 text-yellow-600" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Main Content with Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="grid w-full grid-cols-5">
          <TabsTrigger value="overview">
            <Icon icon="solar:home-2-bold" className="h-4 w-4 mr-2" />
            Overview
          </TabsTrigger>
          <TabsTrigger value="activity">
            <Icon icon="solar:history-bold" className="h-4 w-4 mr-2" />
            Activity
          </TabsTrigger>
          <TabsTrigger value="invoices">
            <Icon icon="solar:document-text-bold" className="h-4 w-4 mr-2" />
            Invoices
          </TabsTrigger>
          <TabsTrigger value="contacts">
            <Icon icon="solar:users-group-rounded-bold" className="h-4 w-4 mr-2" />
            Contacts
          </TabsTrigger>
          <TabsTrigger value="financial">
            <Icon icon="solar:wallet-bold" className="h-4 w-4 mr-2" />
            Financial
          </TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="space-y-6 mt-6">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Contact Information */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:user-id-bold" className="h-5 w-5" />
                  Contact Information
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Email</Label>
                    <p className="font-medium">{customer.email || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Phone</Label>
                    <p className="font-medium">{customer.phone || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Mobile</Label>
                    <p className="font-medium">{customer.mobile || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Website</Label>
                    <p className="font-medium">
                      {customer.website ? (
                        <a href={customer.website} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                          {customer.website}
                        </a>
                      ) : '-'}
                    </p>
                  </div>
                  {customer.type === 'company' && (
                    <>
                      <div>
                        <Label className="text-muted-foreground">Company</Label>
                        <p className="font-medium">{customer.company || '-'}</p>
                      </div>
                      <div>
                        <Label className="text-muted-foreground">Tax Number</Label>
                        <p className="font-medium">{customer.tax_number || '-'}</p>
                      </div>
                    </>
                  )}
                  <div>
                    <Label className="text-muted-foreground">Preferred Contact</Label>
                    <p className="font-medium capitalize">{customer.preferred_contact || 'Email'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Marketing Opt-in</Label>
                    <p className="font-medium">{customer.marketing_opt_in ? 'Yes' : 'No'}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Credit & Payment */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:wallet-bold" className="h-5 w-5" />
                  Credit Status
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <div className="flex justify-between text-sm mb-2">
                    <span>Credit Used</span>
                    <span>{formatCurrency(customer.total_outstanding)} / {formatCurrency(customer.credit_limit)}</span>
                  </div>
                  <Progress 
                    value={Math.min(creditUsedPercent, 100)} 
                    className={cn(
                      "h-3",
                      creditUsedPercent > 90 ? "[&>div]:bg-red-500" : 
                      creditUsedPercent > 70 ? "[&>div]:bg-yellow-500" : 
                      "[&>div]:bg-green-500"
                    )}
                  />
                  {creditUsedPercent > 90 && (
                    <p className="text-xs text-red-500 mt-1">⚠️ Credit limit almost reached</p>
                  )}
                </div>
                <Separator />
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Payment Terms</Label>
                    <p className="font-medium">{customer.payment_terms} days</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Price List</Label>
                    <p className="font-medium">{customer.price_list?.name || 'Default'}</p>
                  </div>
                </div>
                {customer.salesperson && (
                  <div>
                    <Label className="text-muted-foreground">Assigned Salesperson</Label>
                    <p className="font-medium">{customer.salesperson.name}</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Addresses */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:map-point-bold" className="h-5 w-5" />
                Addresses
              </CardTitle>
              <Button
                size="sm"
                onClick={() => {
                  setEditingAddressId(null)
                  setAddressForm({})
                  setAddressDialogOpen(true)
                }}
              >
                <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                Add Address
              </Button>
            </CardHeader>
            <CardContent>
              {customer.addresses?.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {customer.addresses.map(address => (
                    <div key={address.id} className="p-4 border rounded-lg relative group">
                      <div className="absolute top-2 right-2 flex opacity-0 group-hover:opacity-100 transition-opacity">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => {
                            setEditingAddressId(address.id)
                            setAddressForm({ ...address })
                            setAddressDialogOpen(true)
                          }}
                          title="Edit address"
                        >
                          <Icon icon="solar:pen-bold" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8"
                          onClick={() => deleteAddressMutation.mutate(address.id)}
                          title="Delete address"
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                        </Button>
                      </div>
                      <div className="flex items-center gap-2 mb-2">
                        <Badge variant="outline" className="capitalize">{address.type}</Badge>
                        {address.is_default && <Badge variant="secondary">Default</Badge>}
                        {address.label && <span className="text-sm text-muted-foreground">{address.label}</span>}
                      </div>
                      <p className="text-sm">
                        {[address.street, address.street2, address.city, address.state, address.zip, address.country]
                          .filter(Boolean)
                          .join(', ')}
                      </p>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-8 text-muted-foreground">
                  <Icon icon="solar:map-point-wave-bold" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                  <p>No addresses added yet</p>
                </div>
              )}
              
              {/* Legacy addresses */}
              {(customer.billing_address || customer.shipping_address) && (
                <>
                  <Separator className="my-4" />
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {customer.billing_address && (
                      <div className="p-4 border rounded-lg">
                        <Badge variant="outline" className="mb-2">Billing (Legacy)</Badge>
                        <p className="text-sm whitespace-pre-line">{formatLegacyAddress(customer.billing_address)}</p>
                      </div>
                    )}
                    {customer.shipping_address && (
                      <div className="p-4 border rounded-lg">
                        <Badge variant="outline" className="mb-2">Shipping (Legacy)</Badge>
                        <p className="text-sm whitespace-pre-line">{formatLegacyAddress(customer.shipping_address)}</p>
                      </div>
                    )}
                  </div>
                </>
              )}
            </CardContent>
          </Card>

          {/* Notes */}
          {customer.notes && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:notes-bold" className="h-5 w-5" />
                  Notes
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="whitespace-pre-line">{customer.notes}</p>
              </CardContent>
            </Card>
          )}

          {/* Recent Activity */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:history-bold" className="h-5 w-5" />
                Recent Activity
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {customer.pos_orders?.slice(0, 3).map(order => (
                  <div key={order.id} className="flex items-center justify-between p-3 border rounded-lg">
                    <div className="flex items-center gap-3">
                      <div className="p-2 rounded-full bg-green-100">
                        <Icon icon="solar:cart-check-bold" className="h-4 w-4 text-green-600" />
                      </div>
                      <div>
                        <p className="font-medium">POS Order #{order.order_number}</p>
                        <p className="text-sm text-muted-foreground">
                          {order.paid_at ? format(new Date(order.paid_at), 'MMM d, yyyy h:mm a') : format(new Date(order.created_at), 'MMM d, yyyy h:mm a')}
                        </p>
                      </div>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">{formatCurrency(order.total)}</p>
                      <Badge className={getStatusColor(order.status)}>{order.status}</Badge>
                    </div>
                  </div>
                ))}
                {customer.invoices?.slice(0, 3).map(invoice => (
                  <Link 
                    key={invoice.id} 
                    to={`/invoices/${invoice.id}`}
                    state={{ from: `/customers/${customer.id}` }}
                    className="flex items-center justify-between p-3 border rounded-lg hover:bg-slate-50 transition-colors cursor-pointer"
                  >
                    <div className="flex items-center gap-3">
                      <div className="p-2 rounded-full bg-blue-100">
                        <Icon icon="solar:document-text-bold" className="h-4 w-4 text-blue-600" />
                      </div>
                      <div>
                        <p className="font-medium text-blue-600">Invoice #{invoice.invoice_number}</p>
                        <p className="text-sm text-muted-foreground">
                          {format(new Date(invoice.invoice_date), 'MMM d, yyyy')}
                        </p>
                      </div>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">{formatCurrency(invoice.total)}</p>
                      <Badge className={getStatusColor(invoice.status)}>{invoice.status}</Badge>
                    </div>
                  </Link>
                ))}
                {(!customer.pos_orders?.length && !customer.invoices?.length) && (
                  <div className="text-center py-8 text-muted-foreground">
                    <Icon icon="solar:history-bold" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                    <p>No recent activity</p>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Activity Tab */}
        <TabsContent value="activity" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:history-bold" className="h-5 w-5" />
                Activity Timeline
              </CardTitle>
              <CardDescription>Complete history of customer interactions</CardDescription>
            </CardHeader>
            <CardContent>
              {activityData?.activities?.length > 0 ? (
                <div className="relative">
                  {/* Timeline line */}
                  <div className="absolute left-6 top-0 bottom-0 w-0.5 bg-border" />
                  
                  <div className="space-y-6">
                    {activityData.activities.map((activity: any, index: number) => {
                      const iconMap: Record<string, string> = {
                        cart: 'solar:cart-check-bold',
                        document: 'solar:document-text-bold',
                        money: 'solar:wallet-money-bold',
                        star: 'solar:star-bold',
                      }
                      const colorMap: Record<string, string> = {
                        pos_order: 'bg-green-100 text-green-600',
                        invoice: 'bg-blue-100 text-blue-600',
                        payment: 'bg-purple-100 text-purple-600',
                        loyalty: 'bg-yellow-100 text-yellow-600',
                      }
                      
                      return (
                        <div key={`${activity.type}-${activity.id}-${index}`} className="relative flex gap-4">
                          <div className={cn(
                            "relative z-10 flex h-12 w-12 items-center justify-center rounded-full",
                            colorMap[activity.type] || 'bg-gray-100 text-gray-600'
                          )}>
                            <Icon icon={iconMap[activity.icon] || 'solar:info-circle-bold'} className="h-6 w-6" />
                          </div>
                          <div className="flex-1 pt-1">
                            <div className="flex items-center justify-between">
                              <div>
                                <p className="font-medium">{activity.title}</p>
                                <p className="text-sm text-muted-foreground">{activity.description}</p>
                              </div>
                              <div className="text-right">
                                {activity.amount && (
                                  <p className="font-semibold">{formatCurrency(activity.amount)}</p>
                                )}
                                {activity.points && (
                                  <p className={activity.points > 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                    {activity.points > 0 ? '+' : ''}{activity.points} pts
                                  </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                  {format(new Date(activity.date), 'MMM d, yyyy h:mm a')}
                                </p>
                              </div>
                            </div>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                </div>
              ) : (
                <div className="text-center py-12 text-muted-foreground">
                  <Icon icon="solar:history-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                  <p>No activity recorded yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Orders Tab */}
        <TabsContent value="orders" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle>Orders</CardTitle>
              <CardDescription>All orders for this customer (POS & Web)</CardDescription>
            </CardHeader>
            <CardContent>
              {(() => {
                const webOrdersList = customer.web_orders || customer.webOrders || [];
                const allOrders = [
                    ...(customer.pos_orders || []).map(o => ({ ...o, type: 'POS', payment_method: o.payment_method || '-' })),
                    ...(webOrdersList).map(o => ({ 
                      ...o, 
                      type: 'Web', 
                      payment_method: o.payment_status,
                      paid_at: o.created_at // Fallback for sort/display
                    }))
                ].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
                
                if (allOrders.length === 0) {
                     return (
                        <div className="text-center py-12 text-muted-foreground">
                          <Icon icon="solar:cart-large-2-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                          <p>No orders yet</p>
                        </div>
                     )
                }

                return (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Order #</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Payment</TableHead>
                      <TableHead className="text-right">Total</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {allOrders.map((order: any, idx) => (
                      <TableRow key={`${order.type}-${order.id}-${idx}`}>
                        <TableCell className="font-medium">{order.order_number}</TableCell>
                        <TableCell>
                           <Badge variant="outline">{order.type}</Badge>
                        </TableCell>
                        <TableCell>
                          {order.paid_at ? format(new Date(order.paid_at), 'MMM d, yyyy h:mm a') : format(new Date(order.created_at), 'MMM d, yyyy')}
                        </TableCell>
                        <TableCell>
                          <Badge className={getStatusColor(order.status)}>{order.status}</Badge>
                        </TableCell>
                        <TableCell>
                          <span className="text-sm capitalize">{order.payment_method || '-'}</span>
                        </TableCell>
                        <TableCell className="text-right font-medium">{formatCurrency(order.total)}</TableCell>
                        <TableCell className="text-right">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => {
                              setSelectedOrder(order)
                              setOrderDialogOpen(true)
                            }}
                            title="View Order Details"
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                )
              })()}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Invoices Tab */}
        <TabsContent value="invoices" className="mt-6">
          <div className="space-y-6">
            {/* AR Aging Panel */}
            {agingData && (
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">AR Aging Summary</CardTitle>
                  <CardDescription>Outstanding receivables by aging period (as of today)</CardDescription>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div className="p-4 border rounded-lg bg-green-50">
                      <p className="text-xs font-medium text-green-700 uppercase">Not Due</p>
                      <p className="text-xl font-bold text-green-800 mt-1">
                        {formatCurrency(agingData.buckets?.not_due?.amount || 0)}
                      </p>
                    </div>
                    <div className="p-4 border rounded-lg bg-blue-50">
                      <p className="text-xs font-medium text-blue-700 uppercase">1-30 Days</p>
                      <p className="text-xl font-bold text-blue-800 mt-1">
                        {formatCurrency(agingData.buckets?.['1_30']?.amount || 0)}
                      </p>
                    </div>
                    <div className="p-4 border rounded-lg bg-yellow-50">
                      <p className="text-xs font-medium text-yellow-700 uppercase">31-60 Days</p>
                      <p className="text-xl font-bold text-yellow-800 mt-1">
                        {formatCurrency(agingData.buckets?.['31_60']?.amount || 0)}
                      </p>
                    </div>
                    <div className="p-4 border rounded-lg bg-orange-50">
                      <p className="text-xs font-medium text-orange-700 uppercase">61-90 Days</p>
                      <p className="text-xl font-bold text-orange-800 mt-1">
                        {formatCurrency(agingData.buckets?.['61_90']?.amount || 0)}
                      </p>
                    </div>
                    <div className="p-4 border rounded-lg bg-red-50">
                      <p className="text-xs font-medium text-red-700 uppercase">91-180 Days</p>
                      <p className="text-xl font-bold text-red-800 mt-1">
                        {formatCurrency(agingData.buckets?.['91_180']?.amount || 0)}
                      </p>
                    </div>
                    <div className="p-4 border rounded-lg bg-red-100">
                      <p className="text-xs font-medium text-red-700 uppercase">180+ Days</p>
                      <p className="text-xl font-bold text-red-900 mt-1">
                        {formatCurrency(agingData.buckets?.['180_plus']?.amount || 0)}
                      </p>
                    </div>
                  </div>
                  <div className="mt-4 pt-4 border-t flex justify-between items-center">
                    <span className="font-medium text-muted-foreground">Total Outstanding</span>
                    <span className="text-2xl font-bold">
                      {formatCurrency(agingData.total_outstanding || 0)}
                    </span>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Invoices Table */}
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <div>
                  <CardTitle>Invoices</CardTitle>
                  <CardDescription>All invoices for this customer</CardDescription>
                </div>
                <div className="flex gap-2">
                  <Button variant="outline" onClick={() => setStatementDialogOpen(true)}>
                    <Icon icon="solar:document-text-bold" className="h-4 w-4 mr-2" />
                    Generate Statement
                  </Button>
                  <Button onClick={() => navigate('/invoices/new?customer=' + customer.id)}>
                    <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                    Create Invoice
                  </Button>
                </div>
              </CardHeader>
              <CardContent>
                {customer.invoices?.length > 0 ? (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Invoice #</TableHead>
                        <TableHead>Issue Date</TableHead>
                        <TableHead>Due Date</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                        <TableHead className="text-right">Balance</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {customer.invoices.map(invoice => (
                        <TableRow key={invoice.id}>
                          <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
                          <TableCell>{format(new Date(invoice.invoice_date), 'MMM d, yyyy')}</TableCell>
                          <TableCell>{format(new Date(invoice.due_date), 'MMM d, yyyy')}</TableCell>
                          <TableCell>
                            <Badge className={getStatusColor(invoice.status)}>{invoice.status}</Badge>
                          </TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(invoice.total)}</TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(invoice.balance)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                ) : (
                  <div className="text-center py-12 text-muted-foreground">
                    <Icon icon="solar:document-text-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                    <p>No invoices yet</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Contacts Tab */}
        <TabsContent value="contacts" className="mt-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle>Contacts</CardTitle>
                <CardDescription>People associated with this customer</CardDescription>
              </div>
              <Button
                onClick={() => {
                  setEditingContactId(null)
                  setContactForm({})
                  setContactDialogOpen(true)
                }}
              >
                <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                Add Contact
              </Button>
            </CardHeader>
            <CardContent>
              {customer.customer_contacts?.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {customer.customer_contacts.map(contact => (
                    <div key={contact.id} className="p-4 border rounded-lg relative group">
                      <div className="flex items-start gap-3">
                        <Avatar>
                          <AvatarFallback>{getInitials(contact.name)}</AvatarFallback>
                        </Avatar>
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <p className="font-medium truncate">{contact.name}</p>
                            {contact.is_primary && <Badge variant="secondary">Primary</Badge>}
                          </div>
                          {contact.job_title && (
                            <p className="text-sm text-muted-foreground">{contact.job_title}</p>
                          )}
                          {(contact.contact_category || contact.contact_type) && (
                            <div className="flex flex-wrap gap-1 mt-1">
                              {contact.contact_category && <Badge variant="outline">{contact.contact_category}</Badge>}
                              {contact.contact_type && <Badge variant="secondary">{contact.contact_type}</Badge>}
                            </div>
                          )}
                          <div className="mt-2 space-y-1 text-sm">
                            {contact.email && (
                              <p className="flex items-center gap-2">
                                <Icon icon="solar:letter-bold" className="h-3 w-3" />
                                {contact.email}
                              </p>
                            )}
                            {contact.phone && (
                              <p className="flex items-center gap-2">
                                <Icon icon="solar:phone-bold" className="h-3 w-3" />
                                {contact.phone}
                              </p>
                            )}
                            {contact.mobile && (
                              <p className="flex items-center gap-2">
                                <Icon icon="solar:smartphone-bold" className="h-3 w-3" />
                                {contact.mobile}
                              </p>
                            )}
                            {(contact.street || contact.street2 || contact.city || contact.state || contact.zip || contact.country) && (
                              <div className="flex items-start gap-2 pt-1 text-muted-foreground">
                                <Icon icon="solar:map-point-bold" className="h-3 w-3 mt-1 shrink-0" />
                                <p>
                                  {[contact.street, contact.street2, contact.city, contact.state, contact.zip, contact.country]
                                    .filter(Boolean)
                                    .join(', ')}
                                </p>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                      <div className="absolute top-2 right-2 flex opacity-0 group-hover:opacity-100 transition-opacity">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            setEditingContactId(contact.id)
                            setContactForm({ ...contact })
                            setContactDialogOpen(true)
                          }}
                        >
                          <Icon icon="solar:pen-bold" className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => deleteContactMutation.mutate(contact.id)}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-12 text-muted-foreground">
                  <Icon icon="solar:users-group-rounded-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                  <p>No contacts added yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Loyalty Tab */}
        <TabsContent value="loyalty" className="mt-6">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card className="lg:col-span-1">
              <CardHeader>
                <CardTitle>Loyalty Balance</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="text-center py-6">
                  <div className="inline-flex items-center justify-center w-20 h-20 rounded-full bg-yellow-100 mb-4">
                    <Icon icon="solar:star-bold" className="h-10 w-10 text-yellow-600" />
                  </div>
                  <p className="text-4xl font-bold">{customer.loyalty_points || 0}</p>
                  <p className="text-muted-foreground">Available Points</p>
                </div>
                {customer.loyalty_balances?.[0] && (
                  <div className="grid grid-cols-2 gap-4 text-center">
                    <div>
                      <p className="text-2xl font-semibold text-green-600">
                        {customer.loyalty_balances[0].points_earned_total || 0}
                      </p>
                      <p className="text-sm text-muted-foreground">Total Earned</p>
                    </div>
                    <div>
                      <p className="text-2xl font-semibold text-blue-600">
                        {customer.loyalty_balances[0].points_redeemed_total || 0}
                      </p>
                      <p className="text-sm text-muted-foreground">Total Redeemed</p>
                    </div>
                  </div>
                )}
                <Button className="w-full" onClick={() => setLoyaltyDialogOpen(true)}>
                  <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                  Adjust Points
                </Button>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Transaction History</CardTitle>
              </CardHeader>
              <CardContent>
                {loyaltyData?.transactions?.data?.length > 0 ? (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Points</TableHead>
                        <TableHead>Balance After</TableHead>
                        <TableHead>Notes</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {loyaltyData.transactions.data.map((tx: LoyaltyTransaction) => (
                        <TableRow key={tx.id}>
                          <TableCell>{format(new Date(tx.created_at), 'MMM d, yyyy h:mm a')}</TableCell>
                          <TableCell>
                            <Badge variant={tx.type === 'earn' ? 'default' : 'secondary'} className="capitalize">
                              {tx.type}
                            </Badge>
                          </TableCell>
                          <TableCell className={tx.points > 0 ? 'text-green-600' : 'text-red-600'}>
                            {tx.points > 0 ? '+' : ''}{tx.points}
                          </TableCell>
                          <TableCell>{tx.balance_after}</TableCell>
                          <TableCell className="text-muted-foreground">{tx.notes || '-'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                ) : (
                  <div className="text-center py-12 text-muted-foreground">
                    <Icon icon="solar:history-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                    <p>No loyalty transactions yet</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Financial Tab */}
        <TabsContent value="financial" className="mt-6">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <CardHeader>
                <CardTitle>Credit Information</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Credit Limit</Label>
                    <p className="text-2xl font-bold">{formatCurrency(customer.credit_limit || 0)}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Outstanding Balance</Label>
                    <p className="text-2xl font-bold text-orange-600">{formatCurrency(customer.total_outstanding || 0)}</p>
                  </div>
                </div>
                <div>
                  <Label className="text-muted-foreground">Available Credit</Label>
                  <p className="text-2xl font-bold text-green-600">
                    {formatCurrency(Math.max(0, (customer.credit_limit || 0) - (customer.total_outstanding || 0)))}
                  </p>
                </div>
                <div>
                  <div className="flex justify-between text-sm mb-2">
                    <span>Credit Utilization</span>
                    <span>{creditUsedPercent.toFixed(1)}%</span>
                  </div>
                  <Progress 
                    value={Math.min(creditUsedPercent, 100)} 
                    className={cn(
                      "h-4",
                      creditUsedPercent > 90 ? "[&>div]:bg-red-500" : 
                      creditUsedPercent > 70 ? "[&>div]:bg-yellow-500" : 
                      "[&>div]:bg-green-500"
                    )}
                  />
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Payment Terms</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Payment Terms</Label>
                    <p className="text-2xl font-bold">{customer.payment_terms || 30} days</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Price List</Label>
                    <p className="text-lg font-medium">{customer.price_list?.name || 'Default'}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Purchase Summary</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-4 gap-6">
                  <div className="text-center p-4 bg-muted rounded-lg">
                    <p className="text-3xl font-bold">{customer.total_orders || 0}</p>
                    <p className="text-sm text-muted-foreground">Total Orders</p>
                  </div>
                  <div className="text-center p-4 bg-muted rounded-lg">
                    <p className="text-3xl font-bold">{formatCurrency(customer.total_purchases || 0)}</p>
                    <p className="text-sm text-muted-foreground">Lifetime Value</p>
                  </div>
                  <div className="text-center p-4 bg-muted rounded-lg">
                    <p className="text-3xl font-bold">{formatCurrency(customer.average_order_value || 0)}</p>
                    <p className="text-sm text-muted-foreground">Avg. Order Value</p>
                  </div>
                  <div className="text-center p-4 bg-muted rounded-lg">
                    <p className="text-3xl font-bold">
                      {customer.last_interaction_at 
                        ? format(new Date(customer.last_interaction_at), 'MMM d') 
                        : '-'}
                    </p>
                    <p className="text-sm text-muted-foreground">Last Activity</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Favorite Products - using statsData */}
            {statsData?.favorite_products?.length > 0 && (
              <Card className="lg:col-span-2">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:heart-bold" className="h-5 w-5" />
                    Frequently Purchased Products
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Product</TableHead>
                        <TableHead className="text-right">Quantity Purchased</TableHead>
                        <TableHead className="text-right">Total Spent</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {statsData.favorite_products.map((product: any) => (
                        <TableRow key={product.id}>
                          <TableCell className="font-medium">{product.name}</TableCell>
                          <TableCell className="text-right">{product.total_qty}</TableCell>
                          <TableCell className="text-right">{formatCurrency(product.total_spent)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </CardContent>
              </Card>
            )}
          </div>
        </TabsContent>
      </Tabs>

      {/* Add/Edit Address Dialog */}
      <Dialog
        open={addressDialogOpen}
        onOpenChange={(open) => {
          setAddressDialogOpen(open)
          if (!open) {
            setEditingAddressId(null)
            setAddressForm({})
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingAddressId ? 'Edit Address' : 'Add Address'}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Type</Label>
                <Select 
                  value={addressForm.type} 
                  onValueChange={(v) => setAddressForm({ ...addressForm, type: v as 'billing' | 'shipping' | 'other' })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="billing">Billing</SelectItem>
                    <SelectItem value="shipping">Shipping</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Label</Label>
                <Input 
                  placeholder="e.g., Main Office" 
                  value={addressForm.label || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, label: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Street</Label>
              <Input
                value={addressForm.street || ''}
                onChange={(e) => setAddressForm({ ...addressForm, street: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>Street 2</Label>
              <Input
                value={addressForm.street2 || ''}
                onChange={(e) => setAddressForm({ ...addressForm, street2: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>City</Label>
                <Input
                  value={addressForm.city || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, city: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>State/Province</Label>
                <Input
                  value={addressForm.state || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, state: e.target.value })}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>ZIP/Postal Code</Label>
                <Input
                  value={addressForm.zip || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, zip: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Country</Label>
                <Input
                  value={addressForm.country || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, country: e.target.value })}
                />
              </div>
            </div>
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_default"
                checked={addressForm.is_default || false}
                onChange={(e) => setAddressForm({ ...addressForm, is_default: e.target.checked })}
                className="h-4 w-4 rounded border-gray-300"
              />
              <Label htmlFor="is_default" className="font-normal">Set as default address</Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAddressDialogOpen(false)}>Cancel</Button>
            <Button
              onClick={() => {
                if (editingAddressId) {
                  updateAddressMutation.mutate({ addressId: editingAddressId, data: addressForm })
                } else {
                  addAddressMutation.mutate(addressForm)
                }
              }}
              disabled={!addressForm.type || addAddressMutation.isPending || updateAddressMutation.isPending}
            >
              {editingAddressId
                ? (updateAddressMutation.isPending ? 'Saving...' : 'Save Changes')
                : (addAddressMutation.isPending ? 'Adding...' : 'Add Address')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Add/Edit Contact Dialog */}
      <Dialog
        open={contactDialogOpen}
        onOpenChange={(open) => {
          setContactDialogOpen(open)
          if (!open) {
            setEditingContactId(null)
            setContactForm({})
          }
        }}
      >
        <DialogContent className="max-h-[90dvh] w-[calc(100%-2rem)] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden p-0 sm:max-w-2xl">
          <DialogHeader className="px-6 pt-6">
            <DialogTitle>{editingContactId ? 'Edit Contact' : 'Add Contact'}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 overflow-y-auto px-6 py-1">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Name *</Label>
                <Input 
                  value={contactForm.name || ''}
                  onChange={(e) => setContactForm({ ...contactForm, name: e.target.value })}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>Job Title</Label>
                <Input 
                  value={contactForm.job_title || ''}
                  onChange={(e) => setContactForm({ ...contactForm, job_title: e.target.value })}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Contact Category</Label>
                <Select
                  value={contactForm.contact_category || ''}
                  onValueChange={(value) => setContactForm({ ...contactForm, contact_category: value || null })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select category" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Clinical">Clinical</SelectItem>
                    <SelectItem value="Finance">Finance</SelectItem>
                    <SelectItem value="Procurement">Procurement</SelectItem>
                    <SelectItem value="Operations">Operations</SelectItem>
                    <SelectItem value="Administration">Administration</SelectItem>
                    <SelectItem value="Other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Contact Type</Label>
                <Select
                  value={contactForm.contact_type || ''}
                  onValueChange={(value) => setContactForm({ ...contactForm, contact_type: value || null })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Primary">Primary</SelectItem>
                    <SelectItem value="Doctor">Doctor</SelectItem>
                    <SelectItem value="Nurse">Nurse</SelectItem>
                    <SelectItem value="Billing">Billing</SelectItem>
                    <SelectItem value="Delivery">Delivery</SelectItem>
                    <SelectItem value="Clinical">Clinical</SelectItem>
                    <SelectItem value="OT">OT</SelectItem>
                    <SelectItem value="Admin">Admin</SelectItem>
                    <SelectItem value="Procurement">Procurement</SelectItem>
                    <SelectItem value="Other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label>Email</Label>
              <Input
                type="email"
                value={contactForm.email || ''}
                onChange={(e) => setContactForm({ ...contactForm, email: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Phone</Label>
                <Input
                  value={contactForm.phone || ''}
                  onChange={(e) => setContactForm({ ...contactForm, phone: numericPhoneValue(e.target.value) })}
                  inputMode="numeric"
                  pattern="[0-9]*"
                />
              </div>
              <div className="space-y-2">
                <Label>Mobile</Label>
                <Input
                  value={contactForm.mobile || ''}
                  onChange={(e) => setContactForm({ ...contactForm, mobile: numericPhoneValue(e.target.value) })}
                  inputMode="numeric"
                  pattern="[0-9]*"
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Street</Label>
              <Input
                value={contactForm.street || ''}
                onChange={(e) => setContactForm({ ...contactForm, street: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>Street 2</Label>
              <Input
                value={contactForm.street2 || ''}
                onChange={(e) => setContactForm({ ...contactForm, street2: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>City</Label>
                <Input
                  value={contactForm.city || ''}
                  onChange={(e) => setContactForm({ ...contactForm, city: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>State</Label>
                <Input
                  value={contactForm.state || ''}
                  onChange={(e) => setContactForm({ ...contactForm, state: e.target.value })}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>ZIP / Postal Code</Label>
                <Input
                  value={contactForm.zip || ''}
                  onChange={(e) => setContactForm({ ...contactForm, zip: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Country</Label>
                <Input
                  value={contactForm.country || ''}
                  onChange={(e) => setContactForm({ ...contactForm, country: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Notes</Label>
              <Textarea 
                value={contactForm.notes || ''}
                onChange={(e) => setContactForm({ ...contactForm, notes: e.target.value })}
              />
            </div>
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_primary"
                checked={contactForm.is_primary || false}
                onChange={(e) => setContactForm({ ...contactForm, is_primary: e.target.checked })}
                className="h-4 w-4 rounded border-gray-300"
              />
              <Label htmlFor="is_primary" className="font-normal">Primary contact</Label>
            </div>
          </div>
          <DialogFooter className="border-t bg-background px-6 py-4">
            <Button variant="outline" onClick={() => setContactDialogOpen(false)}>Cancel</Button>
            <Button
              onClick={() => {
                if (editingContactId) {
                  updateContactMutation.mutate({ contactId: editingContactId, data: contactForm })
                } else {
                  addContactMutation.mutate(contactForm)
                }
              }}
              disabled={!contactForm.name || addContactMutation.isPending || updateContactMutation.isPending}
            >
              {editingContactId
                ? (updateContactMutation.isPending ? 'Saving...' : 'Save Changes')
                : (addContactMutation.isPending ? 'Adding...' : 'Add Contact')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Adjust Loyalty Dialog */}
      <Dialog open={loyaltyDialogOpen} onOpenChange={setLoyaltyDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Adjust Loyalty Points</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="text-center py-4">
              <p className="text-sm text-muted-foreground">Current Balance</p>
              <p className="text-3xl font-bold">{customer.loyalty_points || 0} pts</p>
            </div>
            <div className="space-y-2">
              <Label>Points Adjustment</Label>
              <Input 
                type="number"
                value={loyaltyAdjustment.points}
                onChange={(e) => setLoyaltyAdjustment({ ...loyaltyAdjustment, points: parseInt(e.target.value) || 0 })}
                placeholder="Enter positive to add, negative to deduct"
              />
              <p className="text-xs text-muted-foreground">
                Enter a positive number to add points, negative to deduct
              </p>
            </div>
            <div className="space-y-2">
              <Label>Reason/Notes</Label>
              <Textarea 
                value={loyaltyAdjustment.notes}
                onChange={(e) => setLoyaltyAdjustment({ ...loyaltyAdjustment, notes: e.target.value })}
                placeholder="Reason for adjustment..."
              />
            </div>
            {loyaltyAdjustment.points !== 0 && (
              <div className="p-3 bg-muted rounded-lg text-center">
                <p className="text-sm text-muted-foreground">New Balance After Adjustment</p>
                <p className="text-2xl font-bold">
                  {(customer.loyalty_points || 0) + loyaltyAdjustment.points} pts
                </p>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLoyaltyDialogOpen(false)}>Cancel</Button>
            <Button 
              onClick={() => adjustLoyaltyMutation.mutate(loyaltyAdjustment)}
              disabled={loyaltyAdjustment.points === 0 || adjustLoyaltyMutation.isPending}
            >
              Adjust Points
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Notes Dialog */}
      <Dialog open={noteDialogOpen} onOpenChange={(open) => {
        setNoteDialogOpen(open)
        if (open) setCustomerNote(customer.notes || '')
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Customer Notes</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <Textarea 
              rows={6}
              value={customerNote}
              onChange={(e) => setCustomerNote(e.target.value)}
              placeholder="Add notes about this customer..."
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setNoteDialogOpen(false)}>Cancel</Button>
            <Button 
              onClick={() => updateNotesMutation.mutate(customerNote)}
              disabled={updateNotesMutation.isPending}
            >
              Save Notes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Customer Dialog */}
      <Dialog open={editDialogOpen} onOpenChange={setEditDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Customer</DialogTitle>
          </DialogHeader>
          <div className="grid gap-4 py-4">
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-code">Customer Code</Label>
                <Input
                  id="edit-code"
                  value={editForm.code || ''}
                  onChange={(e) => setEditForm({ ...editForm, code: e.target.value })}
                  placeholder="e.g., CUST-001"
                  className="font-mono"
                />
                <p className="text-xs text-muted-foreground">Your own ID format — leave empty to keep auto-generated</p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-name">Name *</Label>
                <Input
                  id="edit-name"
                  value={editForm.name || ''}
                  onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                  placeholder="Customer name"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-display-name">Display Name</Label>
                <Input
                  id="edit-display-name"
                  value={editForm.display_name || ''}
                  onChange={(e) => setEditForm({ ...editForm, display_name: e.target.value })}
                  placeholder="Display name"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-type">Customer Type</Label>
                <Select
                  value={editForm.type || 'individual'}
                  onValueChange={(value: 'individual' | 'company') => setEditForm({ ...editForm, type: value })}
                >
                  <SelectTrigger id="edit-type">
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="individual">Individual</SelectItem>
                    <SelectItem value="company">Company</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-company">Company</Label>
                <Input
                  id="edit-company"
                  value={editForm.company || ''}
                  onChange={(e) => setEditForm({ ...editForm, company: e.target.value })}
                  placeholder="Company name"
                />
              </div>
            </div>

            <Separator />
            
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-email">Email</Label>
                <Input
                  id="edit-email"
                  type="email"
                  value={editForm.email || ''}
                  onChange={(e) => setEditForm({ ...editForm, email: e.target.value })}
                  placeholder="email@example.com"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-phone">Phone</Label>
                <Input
                  id="edit-phone"
                  value={editForm.phone || ''}
                  onChange={(e) => setEditForm({ ...editForm, phone: numericPhoneValue(e.target.value) })}
                  placeholder="Phone number"
                  inputMode="numeric"
                  pattern="[0-9]*"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-mobile">Mobile</Label>
                <Input
                  id="edit-mobile"
                  value={editForm.mobile || ''}
                  onChange={(e) => setEditForm({ ...editForm, mobile: numericPhoneValue(e.target.value) })}
                  placeholder="Mobile number"
                  inputMode="numeric"
                  pattern="[0-9]*"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-website">Website</Label>
                <Input
                  id="edit-website"
                  value={editForm.website || ''}
                  onChange={(e) => setEditForm({ ...editForm, website: e.target.value })}
                  placeholder="https://example.com"
                />
              </div>
            </div>

            <Separator />

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-tax-number">Tax Number</Label>
                <Input
                  id="edit-tax-number"
                  value={editForm.tax_number || ''}
                  onChange={(e) => setEditForm({ ...editForm, tax_number: e.target.value })}
                  placeholder="Tax/VAT number"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-credit-limit">Credit Limit</Label>
                <Input
                  id="edit-credit-limit"
                  type="number"
                  value={editForm.credit_limit ?? ''}
                  onChange={(e) => setEditForm({
                    ...editForm,
                    // Issue #239: an emptied field means "no limit configured"
                    // (null), never 0 — after #144, 0 blocks all credit sales.
                    credit_limit: e.target.value === '' ? null : parseFloat(e.target.value),
                  })}
                  placeholder="0.00"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-payment-terms">Payment Terms (days)</Label>
                <Input
                  id="edit-payment-terms"
                  type="number"
                  value={editForm.payment_terms || ''}
                  onChange={(e) => setEditForm({ ...editForm, payment_terms: parseInt(e.target.value) || 0 })}
                  placeholder="30"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-preferred-contact">Preferred Contact</Label>
                <Select
                  value={editForm.preferred_contact || 'email'}
                  onValueChange={(value) => setEditForm({ ...editForm, preferred_contact: value })}
                >
                  <SelectTrigger id="edit-preferred-contact">
                    <SelectValue placeholder="Select preferred contact" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="email">Email</SelectItem>
                    <SelectItem value="phone">Phone</SelectItem>
                    <SelectItem value="mobile">Mobile</SelectItem>
                    <SelectItem value="whatsapp">WhatsApp</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <Separator />

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-dob">Date of Birth</Label>
                <Input
                  id="edit-dob"
                  type="date"
                  value={editForm.date_of_birth || ''}
                  onChange={(e) => setEditForm({ ...editForm, date_of_birth: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-gender">Gender</Label>
                <Select
                  value={editForm.gender || ''}
                  onValueChange={(value) => setEditForm({ ...editForm, gender: value })}
                >
                  <SelectTrigger id="edit-gender">
                    <SelectValue placeholder="Select gender" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="male">Male</SelectItem>
                    <SelectItem value="female">Female</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                    <SelectItem value="prefer_not_to_say">Prefer not to say</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-billing-address">Billing Address</Label>
              <Textarea
                id="edit-billing-address"
                value={editForm.billing_address || ''}
                onChange={(e) => setEditForm({ ...editForm, billing_address: e.target.value })}
                placeholder="Enter billing address"
                rows={2}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-shipping-address">Shipping Address</Label>
              <Textarea
                id="edit-shipping-address"
                value={editForm.shipping_address || ''}
                onChange={(e) => setEditForm({ ...editForm, shipping_address: e.target.value })}
                placeholder="Enter shipping address"
                rows={2}
              />
            </div>

            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="edit-marketing"
                checked={editForm.marketing_opt_in || false}
                onChange={(e) => setEditForm({ ...editForm, marketing_opt_in: e.target.checked })}
                className="h-4 w-4 rounded border-gray-300"
              />
              <Label htmlFor="edit-marketing" className="font-normal">
                Opted in for marketing communications
              </Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={handleEditSubmit}
              disabled={!editForm.name || updateMutation.isPending}
            >
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Email Customer Dialog */}
      <Dialog open={emailDialogOpen} onOpenChange={(open) => {
        setEmailDialogOpen(open)
        if (!open) setEmailForm({ subject: '', body: '' })
      }}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Send Email to {customer.name}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label>To</Label>
              <Input value={customer.email} disabled className="bg-muted" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="email-subject">Subject *</Label>
              <Input
                id="email-subject"
                value={emailForm.subject}
                onChange={(e) => setEmailForm({ ...emailForm, subject: e.target.value })}
                placeholder="Email subject"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="email-body">Message *</Label>
              <Textarea
                id="email-body"
                value={emailForm.body}
                onChange={(e) => setEmailForm({ ...emailForm, body: e.target.value })}
                placeholder="Write your message here..."
                rows={8}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEmailDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={() => sendEmailMutation.mutate(emailForm)}
              disabled={!emailForm.subject || !emailForm.body || sendEmailMutation.isPending}
            >
              <Icon icon="solar:letter-bold" className="h-4 w-4 mr-2" />
              {sendEmailMutation.isPending ? 'Sending...' : 'Send Email'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Order Details Dialog */}
      <Dialog open={orderDialogOpen} onOpenChange={(open) => {
        setOrderDialogOpen(open)
        if (!open) setSelectedOrder(null)
      }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:cart-large-2-bold" className="h-5 w-5" />
              Order {selectedOrder?.order_number}
            </DialogTitle>
          </DialogHeader>
          {selectedOrder && (
            <div className="space-y-6">
              {/* Order Header Info */}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <p className="text-sm text-muted-foreground">Status</p>
                  <Badge className={getStatusColor(selectedOrder.status)}>{selectedOrder.status}</Badge>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Date</p>
                  <p className="font-medium">
                    {selectedOrder.paid_at 
                      ? format(new Date(selectedOrder.paid_at), 'MMM d, yyyy h:mm a')
                      : format(new Date(selectedOrder.created_at), 'MMM d, yyyy h:mm a')}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Payment Method</p>
                  <p className="font-medium capitalize">{selectedOrder.payment_method || '-'}</p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Amount Paid</p>
                  <p className="font-medium">{formatCurrency(selectedOrder.amount_paid || selectedOrder.total)}</p>
                </div>
              </div>

              <Separator />

              {/* Order Items */}
              {selectedOrder.items && selectedOrder.items.length > 0 ? (
                <div>
                  <h4 className="font-medium mb-3">Order Items</h4>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Product</TableHead>
                        <TableHead className="text-center">Qty</TableHead>
                        <TableHead className="text-right">Unit Price</TableHead>
                        <TableHead className="text-right">Tax</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectedOrder.items.map(item => (
                        <TableRow key={item.id}>
                          <TableCell>
                            <div>
                              <p className="font-medium">
                                {item.product_name || item.name || item.product?.name}
                              </p>
                              {item.variant && (
                                <p className="text-sm text-muted-foreground">{item.variant.name}</p>
                              )}
                              {(item.sku || item.product_sku) && (
                                <p className="text-xs text-muted-foreground">SKU: {item.sku || item.product_sku}</p>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="text-center">{item.quantity}</TableCell>
                          <TableCell className="text-right">{formatCurrency(item.unit_price)}</TableCell>
                          <TableCell className="text-right">
                            {item.tax_amount > 0 ? formatCurrency(item.tax_amount) : '-'}
                          </TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(item.line_total)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <div className="text-center py-6 text-muted-foreground">
                  <p>No item details available</p>
                </div>
              )}

              <Separator />

              {/* Order Totals */}
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{formatCurrency(selectedOrder.subtotal || selectedOrder.total)}</span>
                </div>
                {selectedOrder.discount_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount</span>
                    <span className="text-red-600">-{formatCurrency(selectedOrder.discount_amount)}</span>
                  </div>
                )}
                {selectedOrder.tax_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{formatCurrency(selectedOrder.tax_amount)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between font-medium text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(selectedOrder.total)}</span>
                </div>
              </div>

              {/* Notes */}
              {selectedOrder.notes && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-2">Notes</h4>
                    <p className="text-sm text-muted-foreground">{selectedOrder.notes}</p>
                  </div>
                </>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOrderDialogOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Statement of Account Dialog */}
      <Dialog open={statementDialogOpen} onOpenChange={setStatementDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Generate Statement of Account</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <p className="text-sm text-muted-foreground">
              Generate a Statement of Account (SOA) PDF for <strong>{customer?.name}</strong>.
              This will include all outstanding invoices as of the selected date.
            </p>
            <div className="space-y-2">
              <Label htmlFor="as-of-date">As of Date</Label>
              <Input
                id="as-of-date"
                type="date"
                value={statementAsOf}
                onChange={(e) => setStatementAsOf(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setStatementDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={async () => {
                try {
                  const response = await api.get(`/customers/${id}/statement`, {
                    params: { as_of: statementAsOf },
                    responseType: 'blob',
                  })
                  const blob = new Blob([response.data], { type: 'application/pdf' })
                  const url = window.URL.createObjectURL(blob)
                  const link = document.createElement('a')
                  link.href = url
                  const customerCode = customer?.code || 'UNKNOWN'
                  const dateStr = statementAsOf.replace(/-/g, '')
                  link.download = `SOA_${customerCode}_${dateStr}.pdf`
                  document.body.appendChild(link)
                  link.click()
                  document.body.removeChild(link)
                  window.URL.revokeObjectURL(url)
                  setStatementDialogOpen(false)
                  toast({ title: 'Statement of Account generated successfully' })
                } catch (error) {
                  toast({
                    variant: 'destructive',
                    title: 'Failed to generate statement',
                    description: getApiErrorMessage(error),
                  })
                }
              }}
            >
              <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
              Download PDF
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
