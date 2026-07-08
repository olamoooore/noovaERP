import { useForm } from 'react-hook-form'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'

interface LeadFormData {
  first_name: string
  last_name: string
  email: string
  phone: string
  company: string
  job_title: string
  status: string
  source: string
  estimated_value: string
  score: string
  notes: string
  expected_close_date: string
}

interface LeadFormProps {
  mode: 'create' | 'edit'
}

export function LeadForm({ mode }: LeadFormProps) {
  const { id } = useParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { register, handleSubmit, setValue, watch, formState: { errors } } = useForm<LeadFormData>({
    defaultValues: {
      status: 'new',
      source: 'other',
      score: '0',
    },
  })

  // Fetch existing lead for edit mode
  const { isLoading: loadingLead } = useQuery({
    queryKey: ['lead', id],
    queryFn: async () => {
      const response = await api.get(`/crm/leads/${id}`)
      const lead = response.data
      // Populate form
      setValue('first_name', lead.first_name || '')
      setValue('last_name', lead.last_name || '')
      setValue('email', lead.email || '')
      setValue('phone', lead.phone || '')
      setValue('company', lead.company || '')
      setValue('job_title', lead.job_title || '')
      setValue('status', lead.status || 'new')
      setValue('source', lead.source || 'other')
      setValue('estimated_value', lead.estimated_value?.toString() || '')
      setValue('score', lead.score?.toString() || '0')
      setValue('notes', lead.notes || '')
      setValue('expected_close_date', lead.expected_close_date || '')
      return lead
    },
    enabled: mode === 'edit' && !!id,
  })

  const mutation = useMutation({
    mutationFn: (data: LeadFormData) => {
      const payload = {
        ...data,
        estimated_value: data.estimated_value ? parseFloat(data.estimated_value) : null,
        score: parseInt(data.score) || 0,
      }
      if (mode === 'edit') {
        return api.put(`/crm/leads/${id}`, payload)
      }
      return api.post('/crm/leads', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leads'] })
      toast({ title: mode === 'edit' ? 'Lead updated' : 'Lead created' })
      navigate('/crm/leads')
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save lead', variant: 'destructive' })
    },
  })

  const onSubmit = (data: LeadFormData) => {
    mutation.mutate(data)
  }

  if (mode === 'edit' && loadingLead) {
    return <div className="p-6">Loading...</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate('/crm/leads')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-3xl font-bold tracking-tight">
            {mode === 'edit' ? 'Edit Lead' : 'New Lead'}
          </h1>
          <p className="text-muted-foreground">
            {mode === 'edit' ? 'Update lead information' : 'Add a new lead to your pipeline'}
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <Card>
          <CardHeader>
            <CardTitle>Contact Information</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="first_name">First Name *</Label>
              <Input
                id="first_name"
                {...register('first_name', { required: 'First name is required' })}
                placeholder="John"
              />
              {errors.first_name && (
                <p className="text-sm text-destructive">{errors.first_name.message}</p>
              )}
            </div>
            <div className="space-y-2">
              <Label htmlFor="last_name">Last Name</Label>
              <Input id="last_name" {...register('last_name')} placeholder="Doe" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="email">Email</Label>
              <Input id="email" type="email" {...register('email')} placeholder="john@example.com" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" {...register('phone')} placeholder="+1 234 567 8900" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="company">Company</Label>
              <Input id="company" {...register('company')} placeholder="Acme Inc" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="job_title">Job Title</Label>
              <Input id="job_title" {...register('job_title')} placeholder="CEO" />
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Lead Details</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <Label>Status</Label>
              <Select value={watch('status')} onValueChange={(v) => setValue('status', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="new">New</SelectItem>
                  <SelectItem value="contacted">Contacted</SelectItem>
                  <SelectItem value="interested">Interested</SelectItem>
                  <SelectItem value="proposal">Proposal Sent</SelectItem>
                  <SelectItem value="won">Won</SelectItem>
                  <SelectItem value="lost">Lost</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Source</Label>
              <Select value={watch('source')} onValueChange={(v) => setValue('source', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="website">Website</SelectItem>
                  <SelectItem value="referral">Referral</SelectItem>
                  <SelectItem value="social">Social Media</SelectItem>
                  <SelectItem value="advertisement">Advertisement</SelectItem>
                  <SelectItem value="cold_call">Cold Call</SelectItem>
                  <SelectItem value="email">Email</SelectItem>
                  <SelectItem value="trade_show">Trade Show</SelectItem>
                  <SelectItem value="other">Other</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="estimated_value">Estimated Value ($)</Label>
              <Input
                id="estimated_value"
                type="number"
                step="0.01"
                {...register('estimated_value')}
                placeholder="10000"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="score">Lead Score (0-100)</Label>
              <Input
                id="score"
                type="number"
                min="0"
                max="100"
                {...register('score')}
                placeholder="50"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="expected_close_date">Expected Close Date</Label>
              <Input
                id="expected_close_date"
                type="date"
                {...register('expected_close_date')}
              />
            </div>
            <div className="space-y-2 md:col-span-2">
              <Label htmlFor="notes">Notes</Label>
              <Textarea
                id="notes"
                {...register('notes')}
                placeholder="Additional notes about this lead..."
                rows={4}
              />
            </div>
          </CardContent>
        </Card>

        <div className="flex gap-4">
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving...' : mode === 'edit' ? 'Update Lead' : 'Create Lead'}
          </Button>
          <Button type="button" variant="outline" onClick={() => navigate('/crm/leads')}>
            Cancel
          </Button>
        </div>
      </form>
    </div>
  )
}
