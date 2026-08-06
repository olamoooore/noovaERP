import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

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

  const { data: customers } = useQuery({
    queryKey: ['customers-list'],
    queryFn: async () => {
      const res = await api.get('/customers', { params: { per_page: 1000 } })
      return res.data.data || []
    },
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
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between">
        <Button variant="outline" onClick={() => navigate('/sales/customer-bonus-schemes')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <h1 className="text-3xl font-bold">Assign Bonus Scheme to Customer</h1>
      </div>

      <form onSubmit={handleSubmit}>
        <Card>
          <CardHeader>
            <CardTitle>Assignment Details</CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="text-sm font-medium">Customer *</label>
                <select
                  value={formData.customer_id}
                  onChange={(e) => setFormData({ ...formData, customer_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                >
                  <option value="">Select Customer</option>
                  {customers?.map((c: any) => (
                    <option key={c.id} value={c.id}>{c.company_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Bonus Scheme *</label>
                <select
                  value={formData.bonus_scheme_id}
                  onChange={(e) => setFormData({ ...formData, bonus_scheme_id: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                >
                  <option value="">Select Scheme</option>
                  {schemes?.map((s: any) => (
                    <option key={s.id} value={s.id}>{s.name} ({s.scheme_code})</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium">Start Date *</label>
                <input
                  type="date"
                  value={formData.start_date}
                  onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                />
              </div>
              <div>
                <label className="text-sm font-medium">End Date</label>
                <input
                  type="date"
                  value={formData.end_date}
                  onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Status</label>
                <select
                  value={formData.status}
                  onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="active">Active</option>
                  <option value="suspended">Suspended</option>
                </select>
              </div>
              <div className="md:col-span-2">
                <label className="text-sm font-medium">Remarks</label>
                <textarea
                  value={formData.remarks}
                  onChange={(e) => setFormData({ ...formData, remarks: e.target.value })}
                  className="w-full mt-1 flex min-h-[80px] rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3 mt-6">
          <Button type="button" variant="outline" onClick={() => navigate('/sales/customer-bonus-schemes')}>
            Cancel
          </Button>
          <Button type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? 'Creating...' : 'Create Assignment'}
          </Button>
        </div>
      </form>
    </div>
  )
}
