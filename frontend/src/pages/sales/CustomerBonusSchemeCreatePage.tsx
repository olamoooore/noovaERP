import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'

export function CustomerBonusSchemeCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [formData, setFormData] = useState({
    customer_id: '',
    bonus_scheme_id: '',
    start_date: '',
    end_date: '',
    status: 'active',
    remarks: '',
  })

  const { data: schemes } = useQuery({
    queryKey: ['bonus-schemes-list'],
    queryFn: async () => {
      const res = await api.get('/bonus-schemes', { params: { status: 'active', per_page: 1000 } })
      return res.data.data || []
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const res = await api.post('/customer-bonus-schemes', data)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-bonus-schemes'] })
      toast({ title: 'Success', description: 'Customer bonus scheme assignment created successfully' })
      navigate('/sales/customer-bonus-schemes')
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to create', variant: 'destructive' })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const payload = {
      ...formData,
      customer_id: Number(formData.customer_id),
      bonus_scheme_id: Number(formData.bonus_scheme_id),
      start_date: formData.start_date,
      end_date: formData.end_date || null,
    }
    createMutation.mutate(payload)
  }

  return (
    <div className="max-w-3xl space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/sales/customer-bonus-schemes')}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">Assign Bonus Scheme to Customer</h1>
          <p className="mt-1 text-muted-foreground">
            Link an active bonus scheme to a customer for a defined period.
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2 pt-8">
          <HeaderActionButton variant="outline" onClick={() => navigate('/sales/customer-bonus-schemes')} disabled={createMutation.isPending}>
            Cancel
          </HeaderActionButton>
          <HeaderActionButton
            type="submit"
            form="customer-bonus-scheme-form"
            icon="solar:user-check-bold-duotone"
            loading={createMutation.isPending}
            disabled={createMutation.isPending}
          >
            Create Assignment
          </HeaderActionButton>
        </div>
      </div>

      <form id="customer-bonus-scheme-form" onSubmit={handleSubmit}>
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg">Assignment Details</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-5 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Customer <span className="text-destructive">*</span></Label>
              <CustomerSearchCombobox
                value={formData.customer_id}
                onValueChange={(value) => setFormData({ ...formData, customer_id: value })}
                placeholder="Search customer name or code..."
              />
            </div>

            <div className="space-y-2">
              <Label>Bonus Scheme <span className="text-destructive">*</span></Label>
              <Select
                value={formData.bonus_scheme_id}
                onValueChange={(value) => setFormData({ ...formData, bonus_scheme_id: value })}
              >
                <SelectTrigger className="h-9"><SelectValue placeholder="Select scheme" /></SelectTrigger>
                <SelectContent>
                  {schemes?.map((scheme: any) => (
                    <SelectItem key={scheme.id} value={String(scheme.id)}>
                      {scheme.name} ({scheme.scheme_code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label>Start Date <span className="text-destructive">*</span></Label>
              <Input
                type="date"
                className="h-9"
                value={formData.start_date}
                onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                required
              />
            </div>

            <div className="space-y-2">
              <Label>End Date</Label>
              <Input
                type="date"
                className="h-9"
                value={formData.end_date}
                onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Status</Label>
              <Select
                value={formData.status}
                onValueChange={(value) => setFormData({ ...formData, status: value })}
              >
                <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="suspended">Suspended</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2 md:col-span-2">
              <Label>Remarks</Label>
              <Textarea
                value={formData.remarks}
                onChange={(e) => setFormData({ ...formData, remarks: e.target.value })}
                placeholder="Optional notes about this assignment"
                rows={2}
              />
            </div>
          </CardContent>
        </Card>
      </form>
    </div>
  )
}
