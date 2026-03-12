import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import fieldServiceApi from '@/lib/fieldServiceApi'

const defaultForm = {
  title: '',
  description: '',
  type_id: '',
  priority: 'normal',
  scheduled_start: '',
  scheduled_end: '',
  estimated_duration_hours: '',
  location_address: '',
  customer_id: '',
  team_id: '',
  assigned_to: '',
}

export function WorkOrderCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const [form, setForm] = useState(defaultForm)

  const { data: types = [] } = useQuery({
    queryKey: ['fs-wo-types'],
    queryFn: () => fieldServiceApi.getWorkOrderTypes().then(r => r.data),
  })

  const { data: teams = [] } = useQuery({
    queryKey: ['fs-teams'],
    queryFn: () => fieldServiceApi.getTeams().then(r => r.data),
  })

  const { data: technicians = [] } = useQuery({
    queryKey: ['fs-technicians'],
    queryFn: () => fieldServiceApi.getTechnicians().then(r => r.data),
  })

  const set = (field: string, value: string) => setForm(f => ({ ...f, [field]: value }))

  const create = useMutation({
    mutationFn: () => fieldServiceApi.createWorkOrder({
      ...form,
      type_id:                   form.type_id || undefined,
      customer_id:               form.customer_id || undefined,
      team_id:                   form.team_id || undefined,
      assigned_to:               form.assigned_to || undefined,
      estimated_duration_hours:  form.estimated_duration_hours || undefined,
      scheduled_start:           form.scheduled_start || undefined,
      scheduled_end:             form.scheduled_end || undefined,
    } as any),
    onSuccess: (res) => {
      toast({ title: 'Work order created' })
      navigate(`/field-service/work-orders/${res.data.id}`)
    },
    onError: () => toast({ title: 'Failed to create work order', variant: 'destructive' }),
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
          <Icon icon="solar:arrow-left-bold" width={20} />
        </Button>
        <h1 className="text-xl font-bold">New Work Order</h1>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Basic Info */}
        <Card>
          <CardHeader className="pb-3"><CardTitle className="text-base">Basic Information</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div>
              <Label>Title *</Label>
              <Input required value={form.title} onChange={e => set('title', e.target.value)} placeholder="Work order title" className="mt-1" />
            </div>
            <div>
              <Label>Description</Label>
              <Textarea value={form.description} onChange={e => set('description', e.target.value)} placeholder="Describe the work..." rows={3} className="mt-1 resize-none" />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Type</Label>
                <Select value={form.type_id} onValueChange={v => set('type_id', v)}>
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select type" /></SelectTrigger>
                  <SelectContent>
                    {(types as any[]).map((t: any) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Priority</Label>
                <Select value={form.priority} onValueChange={v => set('priority', v)}>
                  <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {['low', 'normal', 'high', 'urgent'].map(p => <SelectItem key={p} value={p} className="capitalize">{p.charAt(0).toUpperCase() + p.slice(1)}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Scheduling */}
        <Card>
          <CardHeader className="pb-3"><CardTitle className="text-base">Scheduling</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Scheduled Start</Label>
                <Input type="datetime-local" value={form.scheduled_start} onChange={e => set('scheduled_start', e.target.value)} className="mt-1" />
              </div>
              <div>
                <Label>Scheduled End</Label>
                <Input type="datetime-local" value={form.scheduled_end} onChange={e => set('scheduled_end', e.target.value)} className="mt-1" />
              </div>
            </div>
            <div>
              <Label>Estimated Duration (hours)</Label>
              <Input type="number" min="0" step="0.5" value={form.estimated_duration_hours} onChange={e => set('estimated_duration_hours', e.target.value)} placeholder="e.g. 2.5" className="mt-1 max-w-xs" />
            </div>
          </CardContent>
        </Card>

        {/* Location */}
        <Card>
          <CardHeader className="pb-3"><CardTitle className="text-base">Location</CardTitle></CardHeader>
          <CardContent>
            <Label>Address</Label>
            <Input value={form.location_address} onChange={e => set('location_address', e.target.value)} placeholder="Service address" className="mt-1" />
          </CardContent>
        </Card>

        {/* Assignment */}
        <Card>
          <CardHeader className="pb-3"><CardTitle className="text-base">Assignment</CardTitle></CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Team</Label>
                <Select value={form.team_id} onValueChange={v => set('team_id', v)}>
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select team" /></SelectTrigger>
                  <SelectContent>
                    {(teams as any[]).map((t: any) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Assigned Technician</Label>
                <Select value={form.assigned_to} onValueChange={v => set('assigned_to', v)}>
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select technician" /></SelectTrigger>
                  <SelectContent>
                    {(technicians as any[]).map((t: any) => <SelectItem key={t.id} value={String(t.user_id)}>{t.user?.name ?? `Technician #${t.id}`}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate(-1)}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending ? 'Creating...' : 'Create Work Order'}
          </Button>
        </div>
      </form>
    </div>
  )
}
