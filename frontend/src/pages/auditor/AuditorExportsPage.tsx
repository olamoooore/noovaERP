import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

interface Export {
  id: number;
  scope: ExportScope;
  file_name: string;
  file_path: string;
  file_size: number;
  file_type: string;
  downloaded_at?: string;
  download_ip?: string;
  created_at: string;
}

interface ExportScope {
  entity_id: number;
  period_start: string;
  period_end: string;
  modules?: string[];
  reports?: string[];
  include_journals?: boolean;
  include_transactions?: boolean;
  include_documents?: boolean;
}

interface LegalEntity {
  id: number;
  name: string;
  code: string;
}

export default function AuditorExportsPage() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isCreateOpen, setIsCreateOpen] = useState(false);

  const [createForm, setCreateForm] = useState({
    entity_id: '',
    period_start: '',
    period_end: '',
    modules: ['accounting', 'reports'],
    reports: ['profit_loss', 'balance_sheet', 'trial_balance'],
    include_journals: true,
    include_transactions: true,
    include_documents: false,
  });

  const { data: exports, isLoading } = useQuery({
    queryKey: ['auditor-exports'],
    queryFn: async () => {
      const response = await api.get('/api/v1/auditor/exports');
      return response.data.data as Export[];
    },
  });

  const { data: entities } = useQuery({
    queryKey: ['legal-entities'],
    queryFn: async () => {
      const response = await api.get('/api/v1/legal-entities');
      return response.data.data as LegalEntity[];
    },
  });

  const createMutation = useMutation({
    mutationFn: async (data: typeof createForm) => {
      const response = await api.post('/api/v1/auditor/exports', data);
      return response.data;
    },
    onSuccess: (data) => {
      toast({
        title: 'Export Created',
        description: data.message,
      });
      queryClient.invalidateQueries({ queryKey: ['auditor-exports'] });
      setIsCreateOpen(false);
      setCreateForm({
        entity_id: '',
        period_start: '',
        period_end: '',
        modules: ['accounting', 'reports'],
        reports: ['profit_loss', 'balance_sheet', 'trial_balance'],
        include_journals: true,
        include_transactions: true,
        include_documents: false,
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create export',
        variant: 'destructive',
      });
    },
  });

  const downloadMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.get(`/api/v1/auditor/exports/${id}/download`, {
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', response.headers['content-disposition']?.split('filename=')[1] || 'export.zip');
      document.body.appendChild(link);
      link.click();
      link.remove();
      return true;
    },
    onSuccess: () => {
      toast({
        title: 'Download Started',
        description: 'Your export is being downloaded',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to download export',
        variant: 'destructive',
      });
    },
  });

  const handleCreate = () => {
    if (!createForm.entity_id || !createForm.period_start || !createForm.period_end) {
      toast({
        title: 'Error',
        description: 'Entity and period are required',
        variant: 'destructive',
      });
      return;
    }
    createMutation.mutate(createForm);
  };

  const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Audit Exports</h1>
          <p className="text-muted-foreground">Export audit data packages</p>
        </div>
        <HeaderActionButton icon="lucide:download" onClick={() => setIsCreateOpen(true)}>
          New Export
        </HeaderActionButton>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Exports ({exports?.length || 0})</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-4">Loading exports...</div>
          ) : !exports?.length ? (
            <div className="text-center py-8">
              <Icon icon="lucide:package" className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
              <p className="text-muted-foreground">No exports yet</p>
            </div>
          ) : (
            <div className="space-y-4">
              {exports.map((exportItem) => (
                <div
                  key={exportItem.id}
                  className="flex items-center justify-between p-4 border rounded-lg hover:bg-muted/50"
                >
                  <div className="flex items-center space-x-4">
                    <div className="h-10 w-10 rounded bg-primary/10 flex items-center justify-center">
                      <Icon icon="lucide:file-archive" className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <p className="font-medium">{exportItem.file_name}</p>
                      <p className="text-sm text-muted-foreground">
                        {format(new Date(exportItem.created_at), 'MMM d, yyyy HH:mm')} • {formatFileSize(exportItem.file_size)}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        Period: {format(new Date(exportItem.scope.period_start), 'MMM d')} - {format(new Date(exportItem.scope.period_end), 'MMM d, yyyy')}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center space-x-4">
                    <div className="text-right">
                      {exportItem.downloaded_at ? (
                        <p className="text-xs text-muted-foreground">
                          Downloaded {format(new Date(exportItem.downloaded_at), 'MMM d, HH:mm')}
                        </p>
                      ) : (
                        <p className="text-xs text-muted-foreground">Not downloaded</p>
                      )}
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => downloadMutation.mutate(exportItem.id)}
                      disabled={downloadMutation.isPending}
                    >
                      <Icon icon="lucide:download" className="mr-2 h-4 w-4" />
                      Download
                    </Button>
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
            <DialogTitle>Create New Export</DialogTitle>
            <DialogDescription>
              Export audit data for a specific entity and period
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div>
              <Label>Entity *</Label>
              <Select
                value={createForm.entity_id}
                onValueChange={(value) => setCreateForm({ ...createForm, entity_id: value })}
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
            <div className="grid grid-cols-2 gap-4">
              <div>
                <Label>Period Start *</Label>
                <Input
                  type="date"
                  value={createForm.period_start}
                  onChange={(e) => setCreateForm({ ...createForm, period_start: e.target.value })}
                />
              </div>
              <div>
                <Label>Period End *</Label>
                <Input
                  type="date"
                  value={createForm.period_end}
                  onChange={(e) => setCreateForm({ ...createForm, period_end: e.target.value })}
                />
              </div>
            </div>
            <div>
              <Label>Modules</Label>
              <div className="grid grid-cols-3 gap-4 mt-2">
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_accounting"
                    checked={createForm.modules.includes('accounting')}
                    onChange={(e) => {
                      const modules = e.target.checked
                        ? [...createForm.modules, 'accounting']
                        : createForm.modules.filter((m) => m !== 'accounting');
                      setCreateForm({ ...createForm, modules });
                    }}
                  />
                  <Label htmlFor="include_accounting">Accounting</Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_reports"
                    checked={createForm.modules.includes('reports')}
                    onChange={(e) => {
                      const modules = e.target.checked
                        ? [...createForm.modules, 'reports']
                        : createForm.modules.filter((m) => m !== 'reports');
                      setCreateForm({ ...createForm, modules });
                    }}
                  />
                  <Label htmlFor="include_reports">Reports</Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_financial_close"
                    checked={createForm.modules.includes('financial_close')}
                    onChange={(e) => {
                      const modules = e.target.checked
                        ? [...createForm.modules, 'financial_close']
                        : createForm.modules.filter((m) => m !== 'financial_close');
                      setCreateForm({ ...createForm, modules });
                    }}
                  />
                  <Label htmlFor="include_financial_close">Financial Close</Label>
                </div>
              </div>
            </div>
            <div>
              <Label>Include</Label>
              <div className="grid grid-cols-3 gap-4 mt-2">
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_journals"
                    checked={createForm.include_journals}
                    onChange={(e) => setCreateForm({ ...createForm, include_journals: e.target.checked })}
                  />
                  <Label htmlFor="include_journals">Journal Entries</Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_transactions"
                    checked={createForm.include_transactions}
                    onChange={(e) => setCreateForm({ ...createForm, include_transactions: e.target.checked })}
                  />
                  <Label htmlFor="include_transactions">Transactions</Label>
                </div>
                <div className="flex items-center space-x-2">
                  <input
                    type="checkbox"
                    id="include_documents"
                    checked={createForm.include_documents}
                    onChange={(e) => setCreateForm({ ...createForm, include_documents: e.target.checked })}
                  />
                  <Label htmlFor="include_documents">Documents</Label>
                </div>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Creating...' : 'Create Export'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
