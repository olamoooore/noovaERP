import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Icon } from '@iconify/react';
import api from '@/lib/api';
import { format } from 'date-fns';

interface ActivityLog {
  id: number;
  action: string;
  resource_type: string;
  resource_id: string;
  metadata: Record<string, any>;
  ip_address: string;
  user_agent: string;
  created_at: string;
}

interface ActivityStats {
  today: number;
  this_week: number;
  this_month: number;
  by_action: Record<string, number>;
}

const actionColors: Record<string, string> = {
  login: 'bg-blue-100 text-blue-800',
  view_report: 'bg-green-100 text-green-800',
  view_transaction: 'bg-purple-100 text-purple-800',
  view_document: 'bg-orange-100 text-orange-800',
  export: 'bg-cyan-100 text-cyan-800',
  query_created: 'bg-yellow-100 text-yellow-800',
  query_responded: 'bg-indigo-100 text-indigo-800',
  query_closed: 'bg-gray-100 text-gray-800',
  invitation_accepted: 'bg-pink-100 text-pink-800',
};

export default function AuditorActivityPage() {
  const [filters, setFilters] = useState({
    action: '',
    resource_type: '',
    from_date: '',
    to_date: '',
  });

  const { data: activityData, isLoading } = useQuery({
    queryKey: ['auditor-activity', filters],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters.action) params.append('action', filters.action);
      if (filters.resource_type) params.append('resource_type', filters.resource_type);
      if (filters.from_date) params.append('from_date', filters.from_date);
      if (filters.to_date) params.append('to_date', filters.to_date);
      const response = await api.get(`/api/v1/auditor/activity-logs?${params.toString()}`);
      return response.data;
    },
  });

  const { data: stats } = useQuery({
    queryKey: ['auditor-activity-stats'],
    queryFn: async () => {
      const response = await api.get('/api/v1/auditor/activity-logs/stats');
      return response.data as ActivityStats;
    },
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Activity Log</h1>
        <p className="text-muted-foreground">Track all your audit activities</p>
      </div>

      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Today</CardTitle>
            <Icon icon="lucide:calendar" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.today || 0}</div>
            <p className="text-xs text-muted-foreground">activities</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">This Week</CardTitle>
            <Icon icon="lucide:calendar-days" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.this_week || 0}</div>
            <p className="text-xs text-muted-foreground">activities</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">This Month</CardTitle>
            <Icon icon="lucide:calendar-range" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.this_month || 0}</div>
            <p className="text-xs text-muted-foreground">activities</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">By Action</CardTitle>
            <Icon icon="lucide:pie-chart" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              {Object.entries(stats?.by_action || {}).slice(0, 3).map(([action, count]) => (
                <div key={action} className="flex justify-between text-xs">
                  <span className="text-muted-foreground">{formatAction(action)}</span>
                  <span className="font-medium">{count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-4">
            <div>
              <Label>Action</Label>
              <Select
                value={filters.action}
                onValueChange={(value) => setFilters({ ...filters, action: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="All actions" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="login">Login</SelectItem>
                  <SelectItem value="view_report">View Report</SelectItem>
                  <SelectItem value="view_transaction">View Transaction</SelectItem>
                  <SelectItem value="view_document">View Document</SelectItem>
                  <SelectItem value="export">Export</SelectItem>
                  <SelectItem value="query_created">Query Created</SelectItem>
                  <SelectItem value="query_responded">Query Responded</SelectItem>
                  <SelectItem value="query_closed">Query Closed</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Resource Type</Label>
              <Select
                value={filters.resource_type}
                onValueChange={(value) => setFilters({ ...filters, resource_type: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="All types" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="report">Report</SelectItem>
                  <SelectItem value="transaction">Transaction</SelectItem>
                  <SelectItem value="document">Document</SelectItem>
                  <SelectItem value="query">Query</SelectItem>
                  <SelectItem value="export">Export</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>From Date</Label>
              <Input
                type="date"
                value={filters.from_date}
                onChange={(e) => setFilters({ ...filters, from_date: e.target.value })}
              />
            </div>
            <div>
              <Label>To Date</Label>
              <Input
                type="date"
                value={filters.to_date}
                onChange={(e) => setFilters({ ...filters, to_date: e.target.value })}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Activity Log ({activityData?.data?.length || 0})</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-4">Loading activity...</div>
          ) : !activityData?.data?.length ? (
            <div className="text-center py-8">
              <Icon icon="lucide:activity" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No activity found</p>
            </div>
          ) : (
            <div className="space-y-4">
              {activityData.data.map((activity: ActivityLog) => (
                <div
                  key={activity.id}
                  className="flex items-start space-x-4 p-4 border rounded-lg hover:bg-muted/50"
                >
                  <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                    <Icon
                      icon={getActivityIcon(activity.action)}
                      className="h-5 w-5 text-primary"
                    />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center space-x-2">
                      <p className="font-medium">{formatActivityAction(activity.action)}</p>
                      <Badge className={actionColors[activity.action]}>
                        {activity.action}
                      </Badge>
                    </div>
                    {activity.resource_type && (
                      <p className="text-sm text-muted-foreground">
                        {activity.resource_type}: {activity.resource_id}
                      </p>
                    )}
                    {activity.metadata && Object.keys(activity.metadata).length > 0 && (
                      <div className="mt-1 text-xs text-muted-foreground">
                        {Object.entries(activity.metadata).map(([key, value]) => (
                          <span key={key} className="mr-3">
                            {key}: {String(value)}
                          </span>
                        ))}
                      </div>
                    )}
                    <div className="flex items-center space-x-4 mt-2 text-xs text-muted-foreground">
                      <span>{format(new Date(activity.created_at), 'MMM d, yyyy HH:mm:ss')}</span>
                      {activity.ip_address && <span>IP: {activity.ip_address}</span>}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
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

function formatAction(action: string): string {
  const labels: Record<string, string> = {
    login: 'Login',
    view_report: 'Report',
    view_transaction: 'Transaction',
    view_document: 'Document',
    export: 'Export',
    query_created: 'Query',
    query_responded: 'Response',
    query_closed: 'Closed',
    invitation_accepted: 'Accepted',
  };
  return labels[action] || action;
}
