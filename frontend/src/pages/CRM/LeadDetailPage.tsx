import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useState } from 'react'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'

interface Activity {
  id: number
  type: string
  subject: string
  description: string | null
  outcome: string | null
  activity_date: string | null
  created_at: string
  user?: { name: string }
}

const statusColors: Record<string, string> = {
  new: 'bg-blue-100 text-blue-800',
  contacted: 'bg-yellow-100 text-yellow-800',
  interested: 'bg-purple-100 text-purple-800',
  proposal: 'bg-indigo-100 text-indigo-800',
  won: 'bg-green-100 text-green-800',
  lost: 'bg-red-100 text-red-800',
}

const activityIcons: Record<string, string> = {
  call: 'solar:phone-bold-duotone',
  email: 'solar:letter-bold-duotone',
  meeting: 'solar:users-group-rounded-bold-duotone',
  task: 'solar:checklist-bold-duotone',
  note: 'solar:document-text-bold-duotone',
}

export function LeadDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [showActivityForm, setShowActivityForm] = useState(false)
  const [activityType, setActivityType] = useState('note')
  const [activityTitle, setActivityTitle] = useState('')
  const [activityDescription, setActivityDescription] = useState('')

  const { data: lead, isLoading } = useQuery({
    queryKey: ['lead', id],
    queryFn: async () => {
      const response = await api.get(`/crm/leads/${id}`)
      return response.data
    },
  })

const addActivityMutation = useMutation({
    mutationFn: (data: { type: string; subject: string; description: string }) =>
        api.post(`/crm/leads/${id}/activities`, data),
    onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['lead', id] })
        setShowActivityForm(false)
        setActivityTitle('')
        setActivityDescription('')
        toast({ title: 'Activity added' })
    },
    onError: (error: unknown) => {
        console.error('Failed to add activity', error)
        toast({ title: 'Error', description: 'Failed to add activity', variant: 'destructive' })
    },
})

  const deleteActivityMutation = useMutation({
    mutationFn: (activityId: number) => api.delete(`/crm/activities/${activityId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lead', id] })
      toast({ title: 'Activity deleted' })
    },
  })

  if (isLoading) {
    return <div className="p-6">Loading...</div>
  }

  if (!lead) {
    return <div className="p-6">Lead not found</div>
  }

  return (
    <div className="space-y-6">
      <div>
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/crm/leads')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <div>
              <h1 className="text-3xl font-bold tracking-tight">
                {lead.first_name} {lead.last_name}
              </h1>
              <p className="text-muted-foreground">{lead.company || 'No company'}</p>
            </div>
            <Badge className={statusColors[lead.status]} variant="secondary">
              {lead.status}
            </Badge>
          </div>
          <div className="flex gap-2">
            <Link to={`/crm/leads/${id}/edit`}>
              <Button variant="outline">
                <Icon icon="solar:pen-bold-duotone" className="mr-2 h-4 w-4" />
                Edit
              </Button>
            </Link>
          </div>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-3">
        {/* Lead Info */}
        <Card className="md:col-span-2">
          <CardHeader>
            <CardTitle>Lead Information</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 md:grid-cols-2">
            <div>
              <div className="text-sm text-muted-foreground">Email</div>
              <div>{lead.email || '-'}</div>
            </div>
            <div>
              <div className="text-sm text-muted-foreground">Phone</div>
              <div>{lead.phone || '-'}</div>
            </div>
            <div>
              <div className="text-sm text-muted-foreground">Job Title</div>
              <div>{lead.job_title || '-'}</div>
            </div>
            <div>
              <div className="text-sm text-muted-foreground">Source</div>
              <div className="capitalize">{lead.source?.replace('_', ' ') || '-'}</div>
            </div>
            <div>
              <div className="text-sm text-muted-foreground">Estimated Value</div>
              <div>{lead.estimated_value ? `$${Number(lead.estimated_value).toLocaleString()}` : '-'}</div>
            </div>
            <div>
              <div className="text-sm text-muted-foreground">Expected Close</div>
              <div>{lead.expected_close_date || '-'}</div>
            </div>
            {lead.notes && (
              <div className="md:col-span-2">
                <div className="text-sm text-muted-foreground">Notes</div>
                <div className="whitespace-pre-wrap">{lead.notes}</div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Lead Score */}
        <Card>
          <CardHeader>
            <CardTitle>Lead Score</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-center">
              <div className="text-5xl font-bold">{lead.score}</div>
              <div className="text-muted-foreground">/ 100</div>
              <div className="mt-4 h-3 bg-gray-200 rounded-full overflow-hidden">
                <div
                  className="h-full bg-primary rounded-full transition-all"
                  style={{ width: `${lead.score}%` }}
                />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Activities */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Activities</CardTitle>
          <Button size="sm" onClick={() => setShowActivityForm(!showActivityForm)}>
            <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
            Add Activity
          </Button>
        </CardHeader>
        <CardContent className="space-y-4">
          {showActivityForm && (
            <div className="border rounded-lg p-4 space-y-4 bg-muted/50">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Select value={activityType} onValueChange={setActivityType}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="note">Note</SelectItem>
                      <SelectItem value="call">Call</SelectItem>
                      <SelectItem value="email">Email</SelectItem>
                      <SelectItem value="meeting">Meeting</SelectItem>
                      <SelectItem value="task">Task</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <Input
                  placeholder="Subject"
                  value={activityTitle}
                  onChange={(e) => setActivityTitle(e.target.value)}
                />
              </div>
              <Textarea
                placeholder="Description (optional)"
                value={activityDescription}
                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setActivityDescription(e.target.value)}
              />
              <div className="flex gap-2">
                <Button
                  size="sm"
                  onClick={() => addActivityMutation.mutate({
                    type: activityType,
                    subject: activityTitle,
                    description: activityDescription,
                  })}
                  disabled={!activityTitle || addActivityMutation.isPending}
                >
                  Save Activity
                </Button>
                <Button size="sm" variant="outline" onClick={() => setShowActivityForm(false)}>
                  Cancel
                </Button>
              </div>
            </div>
          )}

          {lead.activities?.length === 0 ? (
            <div className="text-center text-muted-foreground py-8">
              No activities yet. Add one to track your interactions.
            </div>
          ) : (
            <div className="space-y-3">
              {lead.activities?.map((activity: Activity) => (
                <div
                  key={activity.id}
                  className="flex items-start gap-3 p-3 rounded-lg border"
                >
                  <div className="p-2 rounded-full bg-primary/10">
                    <Icon
                      icon={activityIcons[activity.type] || activityIcons.note}
                      className="h-4 w-4 text-primary"
                    />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="font-medium">{activity.subject}</span>
                      <Badge variant="outline" className="text-xs capitalize">
                        {activity.type}
                      </Badge>
                    </div>
                    {activity.description && (
                      <div className="text-sm text-muted-foreground mt-1">{activity.description}</div>
                    )}
                    {activity.outcome && (
                      <div className="text-sm mt-1">
                        <span className="text-muted-foreground">Outcome:</span> {activity.outcome}
                      </div>
                    )}
                    <div className="text-xs text-muted-foreground mt-2">
                      {new Date(activity.created_at).toLocaleDateString()} by {activity.user?.name || 'Unknown'}
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:text-destructive"
                    onClick={() => deleteActivityMutation.mutate(activity.id)}
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                  </Button>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
