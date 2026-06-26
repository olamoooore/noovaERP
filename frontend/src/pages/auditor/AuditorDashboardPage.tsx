import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@iconify/react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import { format } from 'date-fns';

interface DashboardData {
  active_queries: number;
  total_queries: number;
  total_exports: number;
  recent_activity: ActivityLog[];
  queries_by_status: Record<string, number>;
  queries_by_severity: Record<string, number>;
  upcoming_deadlines: Query[];
}

interface ActivityLog {
  id: number;
  action: string;
  resource_type: string;
  resource_id: string;
  metadata: Record<string, any>;
  created_at: string;
}

interface Query {
  id: number;
  title: string;
  severity: string;
  status: string;
  due_date: string;
}

const severityColors: Record<string, string> = {
  low: 'bg-blue-100 text-blue-800',
  medium: 'bg-yellow-100 text-yellow-800',
  high: 'bg-orange-100 text-orange-800',
  critical: 'bg-red-100 text-red-800',
};

const statusColors: Record<string, string> = {
  open: 'bg-green-100 text-green-800',
  responded: 'bg-blue-100 text-blue-800',
  resolved: 'bg-purple-100 text-purple-800',
  closed: 'bg-gray-100 text-gray-800',
};

export default function AuditorDashboardPage() {
  const navigate = useNavigate();

  const { data: dashboard, isLoading } = useQuery({
    queryKey: ['auditor-dashboard'],
    queryFn: async () => {
      const response = await api.get('/api/v1/auditor/dashboard');
      return response.data as DashboardData;
    },
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-center">
          <Icon icon="lucide:loader-2" className="mx-auto h-8 w-8 animate-spin text-muted-foreground" />
          <p className="mt-2 text-muted-foreground">Loading dashboard...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Auditor Dashboard</h1>
        <p className="text-muted-foreground">Overview of your audit activities</p>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Active Queries</CardTitle>
            <Icon icon="lucide:message-circle" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{dashboard?.active_queries || 0}</div>
            <p className="text-xs text-muted-foreground">
              {dashboard?.total_queries || 0} total queries
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Exports</CardTitle>
            <Icon icon="lucide:download" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{dashboard?.total_exports || 0}</div>
            <p className="text-xs text-muted-foreground">All time exports</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Upcoming Deadlines</CardTitle>
            <Icon icon="lucide:calendar" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{dashboard?.upcoming_deadlines?.length || 0}</div>
            <p className="text-xs text-muted-foreground">Queries due soon</p>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Queries by Status</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {Object.entries(dashboard?.queries_by_status || {}).map(([status, count]) => (
                <div key={status} className="flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <Badge className={statusColors[status]}>{status}</Badge>
                  </div>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Queries by Severity</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {Object.entries(dashboard?.queries_by_severity || {}).map(([severity, count]) => (
                <div key={severity} className="flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <Badge className={severityColors[severity]}>{severity}</Badge>
                  </div>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Upcoming Deadlines</CardTitle>
            <Button variant="ghost" size="sm" onClick={() => navigate('/auditor/queries')}>
              View All
            </Button>
          </CardHeader>
          <CardContent>
            {!dashboard?.upcoming_deadlines?.length ? (
              <p className="text-muted-foreground text-center py-4">No upcoming deadlines</p>
            ) : (
              <div className="space-y-3">
                {dashboard.upcoming_deadlines.map((query) => (
                  <div key={query.id} className="flex items-center justify-between p-2 border rounded">
                    <div>
                      <p className="font-medium text-sm">{query.title}</p>
                      <p className="text-xs text-muted-foreground">
                        Due: {format(new Date(query.due_date), 'MMM d, yyyy')}
                      </p>
                    </div>
                    <Badge className={severityColors[query.severity]}>{query.severity}</Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Recent Activity</CardTitle>
            <Button variant="ghost" size="sm" onClick={() => navigate('/auditor/activity')}>
              View All
            </Button>
          </CardHeader>
          <CardContent>
            {!dashboard?.recent_activity?.length ? (
              <p className="text-muted-foreground text-center py-4">No recent activity</p>
            ) : (
              <div className="space-y-3">
                {dashboard.recent_activity.map((activity) => (
                  <div key={activity.id} className="flex items-center space-x-3">
                    <Icon
                      icon={getActivityIcon(activity.action)}
                      className="h-4 w-4 text-muted-foreground"
                    />
                    <div className="flex-1">
                      <p className="text-sm">{formatActivityAction(activity.action)}</p>
                      <p className="text-xs text-muted-foreground">
                        {format(new Date(activity.created_at), 'MMM d, HH:mm')}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Quick Actions</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-4">
            <Button variant="outline" onClick={() => navigate('/auditor/queries')}>
              <Icon icon="lucide:message-circle" className="mr-2 h-4 w-4" />
              View Queries
            </Button>
            <Button variant="outline" onClick={() => navigate('/auditor/exports')}>
              <Icon icon="lucide:download" className="mr-2 h-4 w-4" />
              View Exports
            </Button>
            <Button variant="outline" onClick={() => navigate('/auditor/activity')}>
              <Icon icon="lucide:activity" className="mr-2 h-4 w-4" />
              Activity Log
            </Button>
            <Button variant="outline" onClick={() => navigate('/reports')}>
              <Icon icon="lucide:bar-chart-2" className="mr-2 h-4 w-4" />
              View Reports
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

function getActivityIcon(action: string): string {
  const icons: Record<string, string> = {
    login: 'lucide:log-in',
    view_report: 'lucide:bar-chart',
    view_transaction: 'lucide:receipt',
    view_document: 'lucide:file-text',
    export: 'lucide:download',
    query_created: 'lucide:message-circle',
    query_responded: 'lucide:reply',
    query_closed: 'lucide:check-circle',
    invitation_accepted: 'lucide:user-check',
  };
  return icons[action] || 'lucide:activity';
}

function formatActivityAction(action: string): string {
  const labels: Record<string, string> = {
    login: 'Logged in',
    view_report: 'Viewed report',
    view_transaction: 'Viewed transaction',
    view_document: 'Viewed document',
    export: 'Created export',
    query_created: 'Created query',
    query_responded: 'Responded to query',
    query_closed: 'Closed query',
    invitation_accepted: 'Accepted invitation',
  };
  return labels[action] || action;
}
