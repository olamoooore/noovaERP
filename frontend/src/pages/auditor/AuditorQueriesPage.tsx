import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Icon } from '@iconify/react';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import { format } from 'date-fns';

interface Query {
  id: number;
  source_type: string;
  source_id: string;
  source_context: Record<string, any>;
  title: string;
  description: string;
  severity: string;
  status: string;
  due_date: string;
  assigned_to?: number;
  responded_at?: string;
  closed_at?: string;
  closed_by?: number;
  closure_notes?: string;
  created_at: string;
  responses: QueryResponse[];
  assigned_to_user?: { id: number; name: string };
}

interface QueryResponse {
  id: number;
  responder_id: number;
  responder_role: string;
  response: string;
  evidence_urls?: string[];
  created_at: string;
  responder: { id: number; name: string };
}

interface User {
  id: number;
  name: string;
  email: string;
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
  rejected: 'bg-orange-100 text-orange-800',
  closed: 'bg-gray-100 text-gray-800',
};

export default function AuditorQueriesPage() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [selectedQuery, setSelectedQuery] = useState<Query | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isResponseOpen, setIsResponseOpen] = useState(false);
  const [filters, setFilters] = useState({
    status: '',
    severity: '',
    search: '',
  });

  const [createForm, setCreateForm] = useState({
    source_type: 'report_line',
    source_id: '',
    title: '',
    description: '',
    severity: 'medium',
    due_date: '',
    assigned_to: '',
  });

  const [responseForm, setResponseForm] = useState({
    response: '',
    evidence_urls: [] as string[],
  });

  const { data: queriesData, isLoading } = useQuery({
    queryKey: ['auditor-queries', filters],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (filters.status) params.append('status', filters.status);
      if (filters.severity) params.append('severity', filters.severity);
      if (filters.search) params.append('search', filters.search);
      const response = await api.get(`/api/v1/auditor/queries?${params.toString()}`);
      return response.data;
    },
  });

  const { data: users } = useQuery({
    queryKey: ['users'],
    queryFn: async () => {
      const response = await api.get('/api/v1/tenant-users');
      return response.data.data as User[];
    },
  });

  const createMutation = useMutation({
    mutationFn: async (data: typeof createForm) => {
      const response = await api.post('/api/v1/auditor/queries', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Query Created',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditor-queries'] });
      setIsCreateOpen(false);
      setCreateForm({
        source_type: 'report_line',
        source_id: '',
        title: '',
        description: '',
        severity: 'medium',
        due_date: '',
        assigned_to: '',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create query',
        variant: 'destructive',
      });
    },
  });

  const respondMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: typeof responseForm }) => {
      const response = await api.post(`/api/v1/auditor/queries/${id}/respond`, data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Response Added',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditor-queries'] });
      setIsResponseOpen(false);
      setResponseForm({ response: '', evidence_urls: [] });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to add response',
        variant: 'destructive',
      });
    },
  });

  const closeMutation = useMutation({
    mutationFn: async ({ id, notes }: { id: number; notes?: string }) => {
      const response = await api.post(`/api/v1/auditor/queries/${id}/close`, {
        closure_notes: notes,
      });
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Query Closed',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditor-queries'] });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to close query',
        variant: 'destructive',
      });
    },
  });

  const handleCreate = () => {
    if (!createForm.title || !createForm.description) {
      toast({
        title: 'Error',
        description: 'Title and description are required',
        variant: 'destructive',
      });
      return;
    }
    createMutation.mutate(createForm);
  };

  const handleRespond = () => {
    if (!responseForm.response || !selectedQuery) return;
    respondMutation.mutate({ id: selectedQuery.id, data: responseForm });
  };

  const handleClose = (query: Query) => {
    if (confirm('Are you sure you want to close this query?')) {
      closeMutation.mutate({ id: query.id });
    }
  };

  const viewDetails = (query: Query) => {
    setSelectedQuery(query);
    setIsDetailOpen(true);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Audit Queries</h1>
          <p className="text-muted-foreground">Manage and respond to audit queries</p>
        </div>
        <Button onClick={() => setIsCreateOpen(true)}>
          <Icon icon="lucide:plus" className="mr-2 h-4 w-4" />
          New Query
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-4">
            <div>
              <Label>Search</Label>
              <Input
                placeholder="Search queries..."
                value={filters.search}
                onChange={(e) => setFilters({ ...filters, search: e.target.value })}
              />
            </div>
            <div>
              <Label>Status</Label>
              <Select
                value={filters.status}
                onValueChange={(value) => setFilters({ ...filters, status: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="open">Open</SelectItem>
                  <SelectItem value="responded">Responded</SelectItem>
                  <SelectItem value="resolved">Resolved</SelectItem>
                  <SelectItem value="closed">Closed</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label>Severity</Label>
              <Select
                value={filters.severity}
                onValueChange={(value) => setFilters({ ...filters, severity: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="All severities" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="low">Low</SelectItem>
                  <SelectItem value="medium">Medium</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="critical">Critical</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-end">
              <Button
                variant="outline"
                onClick={() => setFilters({ status: '', severity: '', search: '' })}
              >
                Clear Filters
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Queries ({queriesData?.data?.length || 0})</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-4">Loading queries...</div>
          ) : !queriesData?.data?.length ? (
            <div className="text-center py-8">
              <Icon icon="lucide:message-circle" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No queries found</p>
            </div>
          ) : (
            <div className="space-y-4">
              {queriesData.data.map((query: Query) => (
                <div
                  key={query.id}
                  className="p-4 border rounded-lg hover:bg-muted/50"
                >
                  <div className="flex items-start justify-between">
                    <div className="flex-1">
                      <div className="flex items-center space-x-2">
                        <h3 className="font-medium">{query.title}</h3>
                        <Badge className={severityColors[query.severity]}>{query.severity}</Badge>
                        <Badge className={statusColors[query.status]}>{query.status}</Badge>
                      </div>
                      <p className="text-sm text-muted-foreground mt-1 line-clamp-2">
                        {query.description}
                      </p>
                      <div className="flex items-center space-x-4 mt-2 text-xs text-muted-foreground">
                        <span>Source: {query.source_type}</span>
                        <span>Created: {format(new Date(query.created_at), 'MMM d, yyyy')}</span>
                        {query.due_date && (
                          <span>Due: {format(new Date(query.due_date), 'MMM d, yyyy')}</span>
                        )}
                        {query.assigned_to_user && (
                          <span>Assigned to: {query.assigned_to_user.name}</span>
                        )}
                      </div>
                    </div>
                    <div className="flex space-x-2">
                      <Button variant="outline" size="sm" onClick={() => viewDetails(query)}>
                        View
                      </Button>
                      {query.status !== 'closed' && (
                        <>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              setSelectedQuery(query);
                              setIsResponseOpen(true);
                            }}
                          >
                            Respond
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleClose(query)}
                            className="text-green-600 hover:text-green-700"
                          >
                            Close
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Create New Query</DialogTitle>
            <DialogDescription>
              Raise a new audit query for clarification or investigation
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Source Type</Label>
                <Select
                  value={createForm.source_type}
                  onValueChange={(value) => setCreateForm({ ...createForm, source_type: value })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="report_line">Report Line</SelectItem>
                    <SelectItem value="transaction">Transaction</SelectItem>
                    <SelectItem value="document">Document</SelectItem>
                    <SelectItem value="account">Account</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Source ID</Label>
                {/* ACC-UI-RM-006: canonical resource linkage. Source ID must
                    resolve to an authoritative record. The free-text input
                    is deprecated; the resource picker is being introduced
                    per source_type below. For now, validate format here. */}
                <Input
                  value={createForm.source_id}
                  onChange={(e) => {
                    const val = e.target.value;
                    // Numeric IDs only (resource primary keys are bigint).
                    // Free-text invoice numbers should use the
                    // invoice_number field on the invoice record itself.
                    const numeric = /^[0-9]+$/.test(val) || val === '';
                    if (numeric) {
                      setCreateForm({ ...createForm, source_id: val });
                    }
                  }}
                  placeholder={createForm.source_type === 'account'
                    ? 'e.g., 1001 (account code)'
                    : `e.g., 42 (${createForm.source_type} ID)`}
                />
                <p className="text-xs text-muted-foreground mt-1">
                  Must be a numeric ID of an existing {createForm.source_type.replace('_', ' ')}.
                  The full resource picker is tracked under ACC-UI-RM-006.
                </p>
              </div>
            </div>
            <div>
              <Label>Title *</Label>
              <Input
                value={createForm.title}
                onChange={(e) => setCreateForm({ ...createForm, title: e.target.value })}
                placeholder="Brief title for the query"
              />
            </div>
            <div>
              <Label>Description *</Label>
              <Textarea
                value={createForm.description}
                onChange={(e) => setCreateForm({ ...createForm, description: e.target.value })}
                placeholder="Detailed description of the query..."
                rows={4}
              />
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <Label>Severity</Label>
                <Select
                  value={createForm.severity}
                  onValueChange={(value) => setCreateForm({ ...createForm, severity: value })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="low">Low</SelectItem>
                    <SelectItem value="medium">Medium</SelectItem>
                    <SelectItem value="high">High</SelectItem>
                    <SelectItem value="critical">Critical</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Due Date</Label>
                <Input
                  type="date"
                  value={createForm.due_date}
                  onChange={(e) => setCreateForm({ ...createForm, due_date: e.target.value })}
                />
              </div>
              <div>
                <Label>Assign To</Label>
                <Select
                  value={createForm.assigned_to}
                  onValueChange={(value) => setCreateForm({ ...createForm, assigned_to: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Unassigned" />
                  </SelectTrigger>
                  <SelectContent>
                    {users?.map((user) => (
                      <SelectItem key={user.id} value={user.id.toString()}>
                        {user.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create Query'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={isResponseOpen} onOpenChange={setIsResponseOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Respond to Query</DialogTitle>
            <DialogDescription>Add your response to this audit query</DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div>
              <Label>Response *</Label>
              <Textarea
                value={responseForm.response}
                onChange={(e) => setResponseForm({ ...responseForm, response: e.target.value })}
                placeholder="Enter your response..."
                rows={4}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsResponseOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleRespond} disabled={respondMutation.isPending}>
              {respondMutation.isPending ? 'Submitting...' : 'Submit Response'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={isDetailOpen} onOpenChange={setIsDetailOpen}>
        <DialogContent className="max-w-3xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{selectedQuery?.title}</DialogTitle>
            <DialogDescription>
              <Badge className={severityColors[selectedQuery?.severity || '']}>
                {selectedQuery?.severity}
              </Badge>{' '}
              <Badge className={statusColors[selectedQuery?.status || '']}>
                {selectedQuery?.status}
              </Badge>
            </DialogDescription>
          </DialogHeader>
          {selectedQuery && (
            <div className="space-y-4">
              <div>
                <Label>Description</Label>
                <p className="text-sm mt-1">{selectedQuery.description}</p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Source</Label>
                  <p className="text-sm">{selectedQuery.source_type}: {selectedQuery.source_id}</p>
                </div>
                <div>
                  <Label>Created</Label>
                  <p className="text-sm">{format(new Date(selectedQuery.created_at), 'MMM d, yyyy HH:mm')}</p>
                </div>
              </div>
              {selectedQuery.due_date && (
                <div>
                  <Label>Due Date</Label>
                  <p className="text-sm">{format(new Date(selectedQuery.due_date), 'MMM d, yyyy')}</p>
                </div>
              )}
              {selectedQuery.closure_notes && (
                <div>
                  <Label>Closure Notes</Label>
                  <p className="text-sm">{selectedQuery.closure_notes}</p>
                </div>
              )}
              <div>
                <Label>Responses ({selectedQuery.responses?.length || 0})</Label>
                <div className="space-y-3 mt-2">
                  {selectedQuery.responses?.map((response) => (
                    <div key={response.id} className="p-3 border rounded-lg">
                      <div className="flex items-center justify-between">
                        <p className="font-medium text-sm">{response.responder?.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {format(new Date(response.created_at), 'MMM d, yyyy HH:mm')}
                        </p>
                      </div>
                      <p className="text-sm mt-1">{response.response}</p>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
