import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { formatCurrency } from '@/lib/utils'

export function CRMPage() {
  const { data: stats, isLoading } = useQuery({
    queryKey: ['crm-stats'],
    queryFn: async () => {
      const response = await api.get('/crm/stats')
      return response.data
    },
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">CRM</h1>
          <p className="text-muted-foreground">
            Customer Relationship Management
          </p>
        </div>
        <Link to="/crm/leads/new">
          <Button>
            <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
            Add Lead
          </Button>
        </Link>
      </div>

      <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Total Leads</CardTitle>
            <Icon icon="solar:user-plus-bold-duotone" className="h-5 w-5 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{isLoading ? '-' : stats?.total || 0}</div>
            <p className="text-xs text-muted-foreground">In pipeline</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">New Leads</CardTitle>
            <Icon icon="solar:star-bold-duotone" className="h-5 w-5 text-blue-500" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{isLoading ? '-' : stats?.new || 0}</div>
            <p className="text-xs text-muted-foreground">Awaiting contact</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Pipeline Value</CardTitle>
            <Icon icon="solar:dollar-bold-duotone" className="h-5 w-5 text-green-500" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {isLoading ? '-' : formatCurrency(Number(stats?.total_value) || 0)}
            </div>
            <p className="text-xs text-muted-foreground">Estimated revenue</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-sm font-medium">Conversion Rate</CardTitle>
            <Icon icon="solar:chart-bold-duotone" className="h-5 w-5 text-purple-500" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{isLoading ? '-' : stats?.conversion_rate || 0}%</div>
            <p className="text-xs text-muted-foreground">Leads won</p>
          </CardContent>
        </Card>
      </div>

      {/* Pipeline Overview */}
      <Card>
        <CardHeader>
          <CardTitle>Pipeline Overview</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-6 gap-2 text-center">
            {[
              { key: 'new', label: 'New', color: 'bg-blue-500' },
              { key: 'contacted', label: 'Contacted', color: 'bg-yellow-500' },
              { key: 'interested', label: 'Interested', color: 'bg-purple-500' },
              { key: 'proposal', label: 'Proposal', color: 'bg-indigo-500' },
              { key: 'won', label: 'Won', color: 'bg-green-500' },
              { key: 'lost', label: 'Lost', color: 'bg-red-500' },
            ].map((stage) => (
              <div key={stage.key} className="space-y-2">
                <div className={`h-2 rounded-full ${stage.color}`} />
                <div className="text-2xl font-bold">{isLoading ? '-' : stats?.[stage.key] || 0}</div>
                <div className="text-xs text-muted-foreground">{stage.label}</div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Quick Actions */}
      <div className="grid gap-4 md:grid-cols-2">
        <Link to="/crm/leads">
          <Card className="hover:bg-muted/50 transition-colors cursor-pointer">
            <CardContent className="flex items-center gap-4 p-6">
              <div className="p-3 rounded-full bg-primary/10">
                <Icon icon="solar:users-group-rounded-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <div>
                <div className="font-semibold">View All Leads</div>
                <div className="text-sm text-muted-foreground">Manage your sales pipeline</div>
              </div>
              <Icon icon="solar:arrow-right-bold-duotone" className="ml-auto h-5 w-5 text-muted-foreground" />
            </CardContent>
          </Card>
        </Link>

        <Link to="/crm/leads/new">
          <Card className="hover:bg-muted/50 transition-colors cursor-pointer">
            <CardContent className="flex items-center gap-4 p-6">
              <div className="p-3 rounded-full bg-green-100">
                <Icon icon="solar:user-plus-bold-duotone" className="h-6 w-6 text-green-600" />
              </div>
              <div>
                <div className="font-semibold">Add New Lead</div>
                <div className="text-sm text-muted-foreground">Capture a new opportunity</div>
              </div>
              <Icon icon="solar:arrow-right-bold-duotone" className="ml-auto h-5 w-5 text-muted-foreground" />
            </CardContent>
          </Card>
        </Link>
      </div>
    </div>
  )
}
