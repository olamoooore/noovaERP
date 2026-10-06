import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { getCurrencySymbol } from '@/lib/utils'

interface Tier {
  id: number
  name: string
  min_points: number
  max_points?: number | null
  earning_multiplier: string | number
  discount_percent: string | number
  benefits?: string[]
  color?: string
  sort_order: number
}

interface Program {
  id: number
  name: string
  points_per_currency: string | number
  currency_per_point: string | number
  min_redemption_points: number
  max_redemption_percent: string | number
  points_expiry_months: number
  is_active: boolean
  created_at: string
}

const DEFAULT_PROGRAM_PAYLOAD = {
  name: 'Default Loyalty Program',
  description: 'Earn points with every purchase and unlock exclusive rewards',
  points_per_currency: 1,
  currency_per_point: 0.01,
  min_redemption_points: 100,
  max_redemption_percent: 50,
  points_expiry_months: 12,
  is_active: true,
}

const TIER_ICONS = [
  { value: 'solar:shield-user-bold', label: 'Shield', color: '#CD7F32' },
  { value: 'solar:medal-ribbons-star-bold', label: 'Medal Star', color: '#C0C0C0' },
  { value: 'solar:medal-star-bold', label: 'Gold Medal', color: '#FFD700' },
  { value: 'solar:crown-bold', label: 'Crown', color: '#E5E4E2' },
  { value: 'solar:star-bold', label: 'Star', color: '#FFA500' },
  { value: 'solar:diamond-bold', label: 'Diamond', color: '#B9F2FF' },
  { value: 'solar:cup-star-bold', label: 'Trophy', color: '#D4AF37' },
  { value: 'solar:flag-bold', label: 'Flag', color: '#FF6B6B' },
]

const TIER_COLORS = [
  { value: '#CD7F32', label: 'Bronze' },
  { value: '#C0C0C0', label: 'Silver' },
  { value: '#FFD700', label: 'Gold' },
  { value: '#E5E4E2', label: 'Platinum' },
  { value: '#FF6B6B', label: 'Red' },
  { value: '#4ECDC4', label: 'Teal' },
  { value: '#45B7D1', label: 'Blue' },
  { value: '#96CEB4', label: 'Green' },
  { value: '#DDA0DD', label: 'Plum' },
  { value: '#F4A460', label: 'Sandy' },
]

export function LoyaltySettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Dialog states
  const [isCreateTierOpen, setIsCreateTierOpen] = useState(false)
  const [isEditTierOpen, setIsEditTierOpen] = useState(false)
  const [isDeleteTierOpen, setIsDeleteTierOpen] = useState(false)
  const [selectedTier, setSelectedTier] = useState<Tier | null>(null)
  const [benefitsInput, setBenefitsInput] = useState('')

  // Fetch active program
  const { data: program, isLoading } = useQuery<Program | null>({
    queryKey: ['loyalty-program'],
    queryFn: async () => {
      try {
        const response = await api.get('/loyalty/programs')
        return response.data
      } catch (error: any) {
        if (error.response?.status === 404) {
          return null
        }

        throw error
      }
    },
  })

  // Fetch tiers
  const { data: tiers } = useQuery<Tier[]>({
    queryKey: ['loyalty-tiers'],
    queryFn: async () => {
      if (!program?.id) return []
      const response = await api.get(`/loyalty/programs/${program.id}/tiers`)
      return response.data
    },
    enabled: !!program?.id,
  })

  const createProgramMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/loyalty/programs', DEFAULT_PROGRAM_PAYLOAD)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Default loyalty program created successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-program'] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-tiers'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create loyalty program',
        variant: 'destructive',
      })
    },
  })

  // Update program mutation
  const updateProgramMutation = useMutation({
    mutationFn: async (data: Partial<Program>) => {
      if (!program?.id) throw new Error('No program found')
      const response = await api.put(`/loyalty/programs/${program.id}`, data)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Program settings updated successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-program'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update settings',
        variant: 'destructive',
      })
    },
  })

  // Create tier mutation
  const createTierMutation = useMutation({
    mutationFn: async (data: any) => {
      if (!program?.id) throw new Error('No program found')
      const response = await api.post(`/loyalty/programs/${program.id}/tiers`, data)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Tier created successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-tiers'] })
      setIsCreateTierOpen(false)
      setBenefitsInput('')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create tier',
        variant: 'destructive',
      })
    },
  })

  // Update tier mutation
  const updateTierMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: any }) => {
      const response = await api.put(`/loyalty/tiers/${id}`, data)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Tier updated successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-tiers'] })
      setIsEditTierOpen(false)
      setSelectedTier(null)
      setBenefitsInput('')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update tier',
        variant: 'destructive',
      })
    },
  })

  // Delete tier mutation
  const deleteTierMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/loyalty/tiers/${id}`)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Tier deleted successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-tiers'] })
      setIsDeleteTierOpen(false)
      setSelectedTier(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete tier',
        variant: 'destructive',
      })
    },
  })

  const [formData, setFormData] = useState({
    points_per_currency: '',
    currency_per_point: '',
    min_points_redemption: '',
    max_discount_percentage: '',
    points_expiry_months: '',
  })

  const handleInputChange = (field: string, value: string) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
  }

  const handleSaveSettings = () => {
    if (!program) return
    
    const data: Partial<Program> = {
      points_per_currency: parseFloat(formData.points_per_currency) || program.points_per_currency,
      currency_per_point: parseFloat(formData.currency_per_point) || program.currency_per_point,
      min_redemption_points: parseInt(formData.min_points_redemption) || program.min_redemption_points,
      max_redemption_percent: parseFloat(formData.max_discount_percentage) || program.max_redemption_percent,
      points_expiry_months: parseInt(formData.points_expiry_months) || program.points_expiry_months,
    }
    updateProgramMutation.mutate(data)
  }

  const handleCreateTier = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const form = e.currentTarget
    const formData = new FormData(form)
    
    const benefits = benefitsInput.split('\n').filter(b => b.trim())
    
    createTierMutation.mutate({
      name: formData.get('name'),
      min_points: parseInt(formData.get('min_points') as string) || 0,
      max_points: formData.get('max_points') ? parseInt(formData.get('max_points') as string) : null,
      earning_multiplier: parseFloat(formData.get('earning_multiplier') as string) || 1,
      discount_percent: parseFloat(formData.get('discount_percent') as string) || 0,
      benefits: benefits,
      color: formData.get('color'),
      sort_order: parseInt(formData.get('sort_order') as string) || 0,
    })
  }

  const handleUpdateTier = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!selectedTier) return
    
    const form = e.currentTarget
    const formData = new FormData(form)
    
    const benefits = benefitsInput.split('\n').filter(b => b.trim())
    
    updateTierMutation.mutate({
      id: selectedTier.id,
      data: {
        name: formData.get('name'),
        min_points: parseInt(formData.get('min_points') as string) || 0,
        max_points: formData.get('max_points') ? parseInt(formData.get('max_points') as string) : null,
        earning_multiplier: parseFloat(formData.get('earning_multiplier') as string) || 1,
        discount_percent: parseFloat(formData.get('discount_percent') as string) || 0,
        benefits: benefits,
        color: formData.get('color'),
        sort_order: parseInt(formData.get('sort_order') as string) || 0,
      },
    })
  }

  const openEditTier = (tier: Tier) => {
    setSelectedTier(tier)
    setBenefitsInput(tier.benefits?.join('\n') || '')
    setIsEditTierOpen(true)
  }

  const openDeleteTier = (tier: Tier) => {
    setSelectedTier(tier)
    setIsDeleteTierOpen(true)
  }

  const getTierIcon = (tierName: string, color?: string) => {
    const iconMap: Record<string, string> = {
      'Bronze': 'solar:shield-user-bold',
      'Silver': 'solar:medal-ribbons-star-bold',
      'Gold': 'solar:medal-star-bold',
      'Platinum': 'solar:crown-bold',
    }
    return iconMap[tierName] || 'solar:star-bold'
  }

  if (isLoading) {
    return (
      <div className="flex h-[50vh] items-center justify-center">
        <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!program) {
    return (
      <div className="flex h-[50vh] flex-col items-center justify-center gap-4">
        <Icon icon="solar:settings-bold-duotone" className="h-16 w-16 text-muted-foreground" />
        <div className="text-center">
          <h2 className="text-2xl font-bold">No Loyalty Program Found</h2>
          <p className="text-muted-foreground">Create the default loyalty program to enable settings, tiers, and rewards.</p>
        </div>
        <Button onClick={() => createProgramMutation.mutate()} disabled={createProgramMutation.isPending}>
          {createProgramMutation.isPending && (
            <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
          )}
          Create Default Program
        </Button>
      </div>
    )
  }

  const pointsPerCurrency = parseFloat(program.points_per_currency as string) || 1
  const currencyPerPoint = parseFloat(program.currency_per_point as string) || 0.01
  const minPointsRedemption = program.min_redemption_points || 100
  const maxDiscountPercentage = parseFloat(program.max_redemption_percent as string) || 50
  const pointsExpiryMonths = program.points_expiry_months || 12

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Loyalty Settings</h1>
        <p className="text-muted-foreground">Configure your loyalty program rules and settings</p>
      </div>

      <Tabs defaultValue="program" className="space-y-4">
        <TabsList>
          <TabsTrigger value="program">Program Settings</TabsTrigger>
          <TabsTrigger value="tiers">Tier Management</TabsTrigger>
        </TabsList>

        {/* Program Settings Tab */}
        <TabsContent value="program" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Points Configuration</CardTitle>
              <CardDescription>Configure how customers earn and redeem points</CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-4">
                <h3 className="text-lg font-semibold">Points Earning</h3>
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="points_per_currency">Points per Currency Unit</Label>
                    <Input
                      id="points_per_currency"
                      type="number"
                      step="0.01"
                      placeholder={pointsPerCurrency.toString()}
                      value={formData.points_per_currency}
                      onChange={(e) => handleInputChange('points_per_currency', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Current: {pointsPerCurrency} points per {getCurrencySymbol()}1
                    </p>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="points_expiry_months">Points Expiry (Months)</Label>
                    <Input
                      id="points_expiry_months"
                      type="number"
                      placeholder={pointsExpiryMonths.toString()}
                      value={formData.points_expiry_months}
                      onChange={(e) => handleInputChange('points_expiry_months', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Current: {pointsExpiryMonths} months
                    </p>
                  </div>
                </div>
              </div>

              <div className="space-y-4">
                <h3 className="text-lg font-semibold">Points Redemption</h3>
                <div className="grid gap-4 md:grid-cols-3">
                  <div className="space-y-2">
                    <Label htmlFor="currency_per_point">Currency per Point</Label>
                    <Input
                      id="currency_per_point"
                      type="number"
                      step="0.01"
                      placeholder={currencyPerPoint.toString()}
                      value={formData.currency_per_point}
                      onChange={(e) => handleInputChange('currency_per_point', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Current: {getCurrencySymbol()}{currencyPerPoint} per point
                    </p>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="min_points_redemption">Minimum Points</Label>
                    <Input
                      id="min_points_redemption"
                      type="number"
                      placeholder={minPointsRedemption.toString()}
                      value={formData.min_points_redemption}
                      onChange={(e) => handleInputChange('min_points_redemption', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Current: {minPointsRedemption} points
                    </p>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="max_discount_percentage">Max Discount %</Label>
                    <Input
                      id="max_discount_percentage"
                      type="number"
                      step="0.01"
                      placeholder={maxDiscountPercentage.toString()}
                      value={formData.max_discount_percentage}
                      onChange={(e) => handleInputChange('max_discount_percentage', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                      Current: {maxDiscountPercentage}%
                    </p>
                  </div>
                </div>
              </div>

              <div className="rounded-lg bg-muted p-4">
                <h4 className="font-semibold mb-2">Example Calculation</h4>
                <div className="space-y-1 text-sm">
                  <p>• Customer spends {getCurrencySymbol()}100 → Earns {(pointsPerCurrency * 100).toFixed(0)} points (base)</p>
                  <p>• With Silver tier (1.2x) → Earns {(pointsPerCurrency * 100 * 1.2).toFixed(0)} points</p>
                  <p>• Redeem 100 points → {getCurrencySymbol()}{(currencyPerPoint * 100).toFixed(2)} discount</p>
                  <p>• Points expire after {pointsExpiryMonths} months</p>
                </div>
              </div>

              <div className="flex justify-end">
                <Button onClick={handleSaveSettings} disabled={updateProgramMutation.isPending}>
                  {updateProgramMutation.isPending ? 'Saving...' : 'Save Settings'}
                </Button>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Program Information</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Program Name:</span>
                <span className="font-semibold">{program.name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Status:</span>
                <Badge variant={program.is_active ? 'default' : 'secondary'}>
                  {program.is_active ? 'Active' : 'Inactive'}
                </Badge>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Created:</span>
                <span>{new Date(program.created_at).toLocaleDateString()}</span>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Tier Management Tab */}
        <TabsContent value="tiers" className="space-y-4">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Membership Tiers</CardTitle>
                  <CardDescription>View and manage loyalty tier levels</CardDescription>
                </div>
                <Button onClick={() => setIsCreateTierOpen(true)}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  Add Tier
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Tier</TableHead>
                    <TableHead className="text-right">Min Points</TableHead>
                    <TableHead className="text-right">Multiplier</TableHead>
                    <TableHead className="text-right">Discount</TableHead>
                    <TableHead>Benefits</TableHead>
                    <TableHead className="text-center">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(!tiers || tiers.length === 0) && (
                    <TableRow>
                      <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                        No tiers found. Click "Add Tier" to create your first tier.
                      </TableCell>
                    </TableRow>
                  )}
                  {tiers?.map((tier: Tier) => (
                    <TableRow key={tier.id}>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <div 
                            className="w-8 h-8 rounded-full flex items-center justify-center"
                            style={{ backgroundColor: tier.color ? tier.color + '20' : '#f3f4f6' }}
                          >
                            <Icon
                              icon={getTierIcon(tier.name, tier.color)}
                              className="h-4 w-4"
                              style={{ color: tier.color || '#9CA3AF' }}
                            />
                          </div>
                          <span className="font-semibold">{tier.name}</span>
                        </div>
                      </TableCell>
                      <TableCell className="text-right">{(tier.min_points ?? 0).toLocaleString()}</TableCell>
                      <TableCell className="text-right">
                        {parseFloat(tier.earning_multiplier as string).toFixed(2)}x
                      </TableCell>
                      <TableCell className="text-right">
                        {parseFloat(tier.discount_percent as string).toFixed(0)}%
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground max-w-xs">
                        {tier.benefits && tier.benefits.length > 0 ? (
                          <ul className="list-disc list-inside">
                            {tier.benefits.slice(0, 2).map((benefit: string, idx: number) => (
                              <li key={idx} className="truncate">{benefit}</li>
                            ))}
                            {tier.benefits.length > 2 && (
                              <li>+{tier.benefits.length - 2} more</li>
                            )}
                          </ul>
                        ) : (
                          'No benefits'
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openEditTier(tier)}
                          >
                            <Icon icon="solar:pen-bold" className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive"
                            onClick={() => openDeleteTier(tier)}
                          >
                            <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Create Tier Dialog */}
      <Dialog open={isCreateTierOpen} onOpenChange={setIsCreateTierOpen}>
        <DialogContent className="max-w-lg">
          <form onSubmit={handleCreateTier}>
            <DialogHeader>
              <DialogTitle>Create New Tier</DialogTitle>
              <DialogDescription>Add a new membership tier to your loyalty program</DialogDescription>
            </DialogHeader>
            <div className="grid gap-4 py-4">
              <div className="grid gap-2">
                <Label htmlFor="tier-name">Tier Name</Label>
                <Input id="tier-name" name="name" placeholder="e.g., Diamond" required />
              </div>
              
              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="tier-min_points">Min Points</Label>
                  <Input id="tier-min_points" name="min_points" type="number" placeholder="0" required />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="tier-max_points">Max Points (optional)</Label>
                  <Input id="tier-max_points" name="max_points" type="number" placeholder="Unlimited" />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="tier-earning_multiplier">Points Multiplier</Label>
                  <Input id="tier-earning_multiplier" name="earning_multiplier" type="number" step="0.1" placeholder="1.0" required />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="tier-discount_percent">Discount %</Label>
                  <Input id="tier-discount_percent" name="discount_percent" type="number" step="0.1" placeholder="0" required />
                </div>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="tier-color">Tier Color</Label>
                <Select name="color" defaultValue="#CD7F32">
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TIER_COLORS.map((color) => (
                      <SelectItem key={color.value} value={color.value}>
                        <div className="flex items-center gap-2">
                          <div className="w-4 h-4 rounded-full" style={{ backgroundColor: color.value }} />
                          {color.label}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="tier-sort_order">Sort Order</Label>
                <Input id="tier-sort_order" name="sort_order" type="number" placeholder="0" />
                <p className="text-xs text-muted-foreground">Lower numbers appear first</p>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="tier-benefits">Benefits (one per line)</Label>
                <textarea
                  id="tier-benefits"
                  className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                  placeholder="Free shipping&#10;Priority support&#10;Birthday bonus"
                  value={benefitsInput}
                  onChange={(e) => setBenefitsInput(e.target.value)}
                />
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setIsCreateTierOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={createTierMutation.isPending}>
                {createTierMutation.isPending && (
                  <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                )}
                Create Tier
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit Tier Dialog */}
      <Dialog open={isEditTierOpen} onOpenChange={setIsEditTierOpen}>
        <DialogContent className="max-w-lg">
          {selectedTier && (
            <form onSubmit={handleUpdateTier}>
              <DialogHeader>
                <DialogTitle>Edit Tier</DialogTitle>
                <DialogDescription>Update tier details</DialogDescription>
              </DialogHeader>
              <div className="grid gap-4 py-4">
                <div className="grid gap-2">
                  <Label htmlFor="edit-tier-name">Tier Name</Label>
                  <Input id="edit-tier-name" name="name" defaultValue={selectedTier.name} required />
                </div>
                
                <div className="grid grid-cols-2 gap-4">
                  <div className="grid gap-2">
                    <Label htmlFor="edit-tier-min_points">Min Points</Label>
                    <Input id="edit-tier-min_points" name="min_points" type="number" defaultValue={selectedTier.min_points} required />
                  </div>
                  <div className="grid gap-2">
                    <Label htmlFor="edit-tier-max_points">Max Points (optional)</Label>
                    <Input id="edit-tier-max_points" name="max_points" type="number" defaultValue={selectedTier.max_points || ''} placeholder="Unlimited" />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="grid gap-2">
                    <Label htmlFor="edit-tier-earning_multiplier">Points Multiplier</Label>
                    <Input id="edit-tier-earning_multiplier" name="earning_multiplier" type="number" step="0.1" defaultValue={selectedTier.earning_multiplier} required />
                  </div>
                  <div className="grid gap-2">
                    <Label htmlFor="edit-tier-discount_percent">Discount %</Label>
                    <Input id="edit-tier-discount_percent" name="discount_percent" type="number" step="0.1" defaultValue={selectedTier.discount_percent} required />
                  </div>
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="edit-tier-color">Tier Color</Label>
                  <Select name="color" defaultValue={selectedTier.color || '#CD7F32'}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {TIER_COLORS.map((color) => (
                        <SelectItem key={color.value} value={color.value}>
                          <div className="flex items-center gap-2">
                            <div className="w-4 h-4 rounded-full" style={{ backgroundColor: color.value }} />
                            {color.label}
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="edit-tier-sort_order">Sort Order</Label>
                  <Input id="edit-tier-sort_order" name="sort_order" type="number" defaultValue={selectedTier.sort_order} />
                </div>

                <div className="grid gap-2">
                  <Label htmlFor="edit-tier-benefits">Benefits (one per line)</Label>
                  <textarea
                    id="edit-tier-benefits"
                    className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    placeholder="Free shipping&#10;Priority support&#10;Birthday bonus"
                    value={benefitsInput}
                    onChange={(e) => setBenefitsInput(e.target.value)}
                  />
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setIsEditTierOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={updateTierMutation.isPending}>
                  {updateTierMutation.isPending && (
                    <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Save Changes
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* Delete Tier Dialog */}
      <Dialog open={isDeleteTierOpen} onOpenChange={setIsDeleteTierOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-destructive">
              <Icon icon="solar:danger-triangle-bold" className="h-5 w-5" />
              Delete Tier
            </DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{selectedTier?.name}</strong>? 
              This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="mt-4">
            <Button type="button" variant="outline" onClick={() => setIsDeleteTierOpen(false)}>
              Cancel
            </Button>
            <Button 
              type="button" 
              variant="destructive"
              onClick={() => selectedTier && deleteTierMutation.mutate(selectedTier.id)}
              disabled={deleteTierMutation.isPending}
            >
              {deleteTierMutation.isPending && (
                <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Delete Tier
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
