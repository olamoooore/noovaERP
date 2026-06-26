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
  DialogTrigger,
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

interface Auditor {
  id: number;
  email: string;
  name: string;
  phone?: string;
  organization?: string;
  status: string;
  invited_at: string;
  accepted_at?: string;
  access_starts_at?: string;
  access_ends_at?: string;
  last_login_at?: string;
  notes?: string;
  scopes: AuditorScope[];
  queries_count: number;
  exports_count: number;
}

interface AuditorScope {
  id: number;
  entity_id: number;
  entity?: { id: number; name: string };
  accounting_period?: string;
  modules: string[];
  reports: string[];
  transactions: string[];
  can_export: boolean;
  can_raise_queries: boolean;
  can_view_documents: boolean;
}

interface LegalEntity {
  id: number;
  name: string;
  code: string;
}

const statusColors: Record<string, string> = {
  pending: 'bg-yellow-100 text-yellow-800',
  active: 'bg-green-100 text-green-800',
  expired: 'bg-red-100 text-red-800',
  revoked: 'bg-gray-100 text-gray-800',
  declined: 'bg-orange-100 text-orange-800',
};

export default function AuditorManagementPage() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isInviteOpen, setIsInviteOpen] = useState(false);
  const [selectedAuditor, setSelectedAuditor] = useState<Auditor | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);

  const [inviteForm, setInviteForm] = useState({
    email: '',
    name: '',
    phone: '',
    organization: '',
    access_starts_at: '',
    access_ends_at: '',
    notes: '',
    scopes: [
      {
        entity_id: '',
        accounting_period: '',
        modules: ['accounting', 'reports'],
        reports: ['profit_loss', 'balance_sheet', 'trial_balance'],
        transactions: ['invoices', 'bills', 'journals'],
        can_export: false,
        can_raise_queries: true,
        can_view_documents: true,
      },
    ],
  });

  const { data: auditors, isLoading } = useQuery({
    queryKey: ['auditors'],
    queryFn: async () => {
      const response = await api.get('/api/v1/auditor');
      return response.data.data as Auditor[];
    },
  });

  const { data: entities } = useQuery({
    queryKey: ['legal-entities'],
    queryFn: async () => {
      const response = await api.get('/api/v1/legal-entities');
      return response.data.data as LegalEntity[];
    },
  });

  const inviteMutation = useMutation({
    mutationFn: async (data: typeof inviteForm) => {
      const response = await api.post('/api/v1/auditor', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Auditor Invited',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditors'] });
      setIsInviteOpen(false);
      setInviteForm({
        email: '',
        name: '',
        phone: '',
        organization: '',
        access_starts_at: '',
        access_ends_at: '',
        notes: '',
        scopes: [
          {
            entity_id: '',
            accounting_period: '',
            modules: ['accounting', 'reports'],
            reports: ['profit_loss', 'balance_sheet', 'trial_balance'],
            transactions: ['invoices', 'bills', 'journals'],
            can_export: false,
            can_raise_queries: true,
            can_view_documents: true,
          },
        ],
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to invite auditor',
        variant: 'destructive',
      });
    },
  });

  const revokeMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/api/v1/auditor/${id}`);
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Auditor Revoked',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditors'] });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to revoke auditor',
        variant: 'destructive',
      });
    },
  });

  const handleInvite = () => {
    if (!inviteForm.email || !inviteForm.name) {
      toast({
        title: 'Error',
        description: 'Email and name are required',
        variant: 'destructive',
      });
      return;
    }
    inviteMutation.mutate(inviteForm);
  };

  const handleRevoke = (id: number) => {
    if (confirm('Are you sure you want to revoke this auditor\'s access? This will also close any open queries.')) {
      revokeMutation.mutate(id);
    }
  };

  const viewDetails = (auditor: Auditor) => {
    setSelectedAuditor(auditor);
    setIsDetailOpen(true);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Auditor Management</h1>
          <p className="text-muted-foreground">Manage external auditors and their access</p>
        </div>
        <Dialog open={isInviteOpen} onOpenChange={setIsInviteOpen}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="lucide:user-plus" className="mr-2 h-4 w-4" />
              Invite Auditor
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>Invite Auditor</DialogTitle>
              <DialogDescription>
                Send an invitation to an external auditor. They will receive access based on the scopes you define.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="email">Email *</Label>
                  <Input
                    id="email"
                    type="email"
                    value={inviteForm.email}
                    onChange={(e) => setInviteForm({ ...inviteForm, email: e.target.value })}
                    placeholder="auditor@company.com"
                  />
                </div>
                <div>
                  <Label htmlFor="name">Name *</Label>
                  <Input
                    id="name"
                    value={inviteForm.name}
                    onChange={(e) => setInviteForm({ ...inviteForm, name: e.target.value })}
                    placeholder="John Smith"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="phone">Phone</Label>
                  <Input
                    id="phone"
                    value={inviteForm.phone}
                    onChange={(e) => setInviteForm({ ...inviteForm, phone: e.target.value })}
                    placeholder="+1 234 567 890"
                  />
                </div>
                <div>
                  <Label htmlFor="organization">Organization</Label>
                  <Input
                    id="organization"
                    value={inviteForm.organization}
                    onChange={(e) => setInviteForm({ ...inviteForm, organization: e.target.value })}
                    placeholder="Audit Firm LLC"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="access_starts_at">Access Start Date</Label>
                  <Input
                    id="access_starts_at"
                    type="date"
                    value={inviteForm.access_starts_at}
                    onChange={(e) => setInviteForm({ ...inviteForm, access_starts_at: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="access_ends_at">Access End Date</Label>
                  <Input
                    id="access_ends_at"
                    type="date"
                    value={inviteForm.access_ends_at}
                    onChange={(e) => setInviteForm({ ...inviteForm, access_ends_at: e.target.value })}
                  />
                </div>
              </div>
              <div>
                <Label htmlFor="notes">Notes</Label>
                <Textarea
                  id="notes"
                  value={inviteForm.notes}
                  onChange={(e) => setInviteForm({ ...inviteForm, notes: e.target.value })}
                  placeholder="Additional notes about this auditor..."
                />
              </div>
              <div className="border rounded-lg p-4">
                <h4 className="font-medium mb-3">Access Scope</h4>
                {inviteForm.scopes.map((scope, index) => (
                  <div key={index} className="space-y-3">
                    <div>
                      <Label>Entity</Label>
                      <Select
                        value={scope.entity_id}
                        onValueChange={(value) => {
                          const newScopes = [...inviteForm.scopes];
                          newScopes[index].entity_id = value;
                          setInviteForm({ ...inviteForm, scopes: newScopes });
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select entity" />
                        </SelectTrigger>
                        <SelectContent>
                          {entities?.map((entity) => (
                            <SelectItem key={entity.id} value={entity.id.toString()}>
                              {entity.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="grid grid-cols-3 gap-4">
                      <div className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          id="can_export"
                          checked={scope.can_export}
                          onChange={(e) => {
                            const newScopes = [...inviteForm.scopes];
                            newScopes[index].can_export = e.target.checked;
                            setInviteForm({ ...inviteForm, scopes: newScopes });
                          }}
                        />
                        <Label htmlFor="can_export">Can Export</Label>
                      </div>
                      <div className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          id="can_raise_queries"
                          checked={scope.can_raise_queries}
                          onChange={(e) => {
                            const newScopes = [...inviteForm.scopes];
                            newScopes[index].can_raise_queries = e.target.checked;
                            setInviteForm({ ...inviteForm, scopes: newScopes });
                          }}
                        />
                        <Label htmlFor="can_raise_queries">Can Raise Queries</Label>
                      </div>
                      <div className="flex items-center space-x-2">
                        <input
                          type="checkbox"
                          id="can_view_documents"
                          checked={scope.can_view_documents}
                          onChange={(e) => {
                            const newScopes = [...inviteForm.scopes];
                            newScopes[index].can_view_documents = e.target.checked;
                            setInviteForm({ ...inviteForm, scopes: newScopes });
                          }}
                        />
                        <Label htmlFor="can_view_documents">Can View Documents</Label>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setIsInviteOpen(false)}>
                Cancel
              </Button>
              <Button onClick={handleInvite} disabled={inviteMutation.isPending}>
                {inviteMutation.isPending ? 'Inviting...' : 'Send Invitation'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Auditors</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-4">Loading auditors...</div>
          ) : !auditors?.length ? (
            <div className="text-center py-8">
              <Icon icon="lucide:user-check" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No auditors invited yet</p>
            </div>
          ) : (
            <div className="space-y-4">
              {auditors.map((auditor) => (
                <div
                  key={auditor.id}
                  className="flex items-center justify-between p-4 border rounded-lg hover:bg-muted/50"
                >
                  <div className="flex items-center space-x-4">
                    <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                      <Icon icon="lucide:user" className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="font-medium">{auditor.name}</p>
                      <p className="text-sm text-muted-foreground">{auditor.email}</p>
                      {auditor.organization && (
                        <p className="text-xs text-muted-foreground">{auditor.organization}</p>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center space-x-4">
                    <div className="text-right">
                      <Badge className={statusColors[auditor.status]}>
                        {auditor.status}
                      </Badge>
                      <p className="text-xs text-muted-foreground mt-1">
                        {auditor.queries_count} queries • {auditor.exports_count} exports
                      </p>
                    </div>
                    <div className="flex space-x-2">
                      <Button variant="outline" size="sm" onClick={() => viewDetails(auditor)}>
                        View
                      </Button>
                      {auditor.status === 'active' && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleRevoke(auditor.id)}
                          className="text-red-600 hover:text-red-700"
                        >
                          Revoke
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={isDetailOpen} onOpenChange={setIsDetailOpen}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>{selectedAuditor?.name}</DialogTitle>
            <DialogDescription>{selectedAuditor?.email}</DialogDescription>
          </DialogHeader>
          {selectedAuditor && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Status</Label>
                  <Badge className={statusColors[selectedAuditor.status]}>
                    {selectedAuditor.status}
                  </Badge>
                </div>
                <div>
                  <Label>Organization</Label>
                  <p>{selectedAuditor.organization || 'N/A'}</p>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Invited</Label>
                  <p>{selectedAuditor.invited_at ? format(new Date(selectedAuditor.invited_at), 'MMM d, yyyy') : 'N/A'}</p>
                </div>
                <div>
                  <Label>Accepted</Label>
                  <p>{selectedAuditor.accepted_at ? format(new Date(selectedAuditor.accepted_at), 'MMM d, yyyy') : 'Pending'}</p>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Access Period</Label>
                  <p>
                    {selectedAuditor.access_starts_at ? format(new Date(selectedAuditor.access_starts_at), 'MMM d, yyyy') : 'Start'} - {selectedAuditor.access_ends_at ? format(new Date(selectedAuditor.access_ends_at), 'MMM d, yyyy') : 'End'}
                  </p>
                </div>
                <div>
                  <Label>Last Login</Label>
                  <p>{selectedAuditor.last_login_at ? format(new Date(selectedAuditor.last_login_at), 'MMM d, yyyy HH:mm') : 'Never'}</p>
                </div>
              </div>
              {selectedAuditor.notes && (
                <div>
                  <Label>Notes</Label>
                  <p className="text-sm text-muted-foreground">{selectedAuditor.notes}</p>
                </div>
              )}
              <div>
                <Label>Scopes</Label>
                <div className="space-y-2 mt-2">
                  {selectedAuditor.scopes?.map((scope) => (
                    <div key={scope.id} className="p-3 border rounded-lg">
                      <div className="flex items-center justify-between">
                        <p className="font-medium">{scope.entity?.name || 'Unknown Entity'}</p>
                        <div className="flex space-x-2">
                          {scope.can_export && <Badge variant="secondary">Export</Badge>}
                          {scope.can_raise_queries && <Badge variant="secondary">Queries</Badge>}
                          {scope.can_view_documents && <Badge variant="secondary">Documents</Badge>}
                        </div>
                      </div>
                      <p className="text-xs text-muted-foreground mt-1">
                        Modules: {scope.modules.join(', ')}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        Reports: {scope.reports.join(', ')}
                      </p>
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
