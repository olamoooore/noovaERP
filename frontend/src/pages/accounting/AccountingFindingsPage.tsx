import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import accountingFindingsApi, { AccountingFinding } from '@/lib/accountingFindingsApi';
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';

const SEVERITY_COLORS: Record<string, string> = {
  low: 'bg-blue-100 text-blue-800',
  medium: 'bg-yellow-100 text-yellow-800',
  high: 'bg-orange-100 text-orange-800',
  critical: 'bg-red-100 text-red-800',
};

const STATUS_COLORS: Record<string, string> = {
  open: 'bg-gray-100 text-gray-800',
  acknowledged: 'bg-blue-100 text-blue-800',
  investigating: 'bg-purple-100 text-purple-800',
  resolved: 'bg-green-100 text-green-800',
  dismissed: 'bg-slate-100 text-slate-800',
  escalated: 'bg-red-100 text-red-800',
};

export default function AccountingFindingsPage() {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [entityId, setEntityId] = useState('default');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [severityFilter, setSeverityFilter] = useState<string>('all');
  const [selectedFinding, setSelectedFinding] = useState<AccountingFinding | null>(null);
  const [showDetail, setShowDetail] = useState(false);
  const [resolveNotes, setResolveNotes] = useState('');
  const [dismissReason, setDismissReason] = useState('');
  const [escalateReason, setEscalateReason] = useState('');

  const { data: findings = [], isLoading } = useQuery({
    queryKey: ['accounting-findings', entityId, statusFilter, severityFilter],
    queryFn: () => accountingFindingsApi.getFindings(entityId, {
      status: statusFilter === 'all' ? undefined : statusFilter,
      severity: severityFilter === 'all' ? undefined : severityFilter,
    }),
  });

  const { data: summary } = useQuery({
    queryKey: ['accounting-findings-summary', entityId],
    queryFn: () => accountingFindingsApi.getSummary(entityId),
  });

  const acknowledgeMutation = useMutation({
    mutationFn: (id: string) => accountingFindingsApi.acknowledge(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-findings'] });
      toast({ title: 'Finding acknowledged' });
    },
  });

  const investigateMutation = useMutation({
    mutationFn: (id: string) => accountingFindingsApi.investigate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-findings'] });
      toast({ title: 'Finding marked as investigating' });
    },
  });

  const resolveMutation = useMutation({
    mutationFn: ({ id, notes }: { id: string; notes?: string }) =>
      accountingFindingsApi.resolve(id, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-findings'] });
      setShowDetail(false);
      setResolveNotes('');
      toast({ title: 'Finding resolved' });
    },
  });

  const dismissMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      accountingFindingsApi.dismiss(id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-findings'] });
      setShowDetail(false);
      setDismissReason('');
      toast({ title: 'Finding dismissed' });
    },
  });

  const escalateMutation = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      accountingFindingsApi.escalate(id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-findings'] });
      setShowDetail(false);
      setEscalateReason('');
      toast({ title: 'Finding escalated' });
    },
  });

  const openDetail = (finding: AccountingFinding) => {
    setSelectedFinding(finding);
    setShowDetail(true);
    setResolveNotes('');
    setDismissReason('');
    setEscalateReason('');
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Accounting Findings</h1>
          <p className="text-muted-foreground mt-1">
            Review and resolve accounting findings and anomalies
          </p>
        </div>
      </div>

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card className="p-4">
            <div className="text-sm text-muted-foreground">Total</div>
            <div className="text-2xl font-bold">{summary.total}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-muted-foreground">Open</div>
            <div className="text-2xl font-bold text-gray-600">{summary.by_status?.open || 0}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-muted-foreground">Critical</div>
            <div className="text-2xl font-bold text-red-600">{summary.by_severity?.critical || 0}</div>
          </Card>
          <Card className="p-4">
            <div className="text-sm text-muted-foreground">Escalated</div>
            <div className="text-2xl font-bold text-orange-600">{summary.by_status?.escalated || 0}</div>
          </Card>
        </div>
      )}

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
          <div className="space-y-2">
            <Label>Entity ID</Label>
            <Input value={entityId} onChange={(e) => setEntityId(e.target.value)} />
          </div>
          <div className="space-y-2">
            <Label>Status</Label>
            <Select value={statusFilter} onValueChange={setStatusFilter}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="open">Open</SelectItem>
                <SelectItem value="acknowledged">Acknowledged</SelectItem>
                <SelectItem value="investigating">Investigating</SelectItem>
                <SelectItem value="resolved">Resolved</SelectItem>
                <SelectItem value="dismissed">Dismissed</SelectItem>
                <SelectItem value="escalated">Escalated</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Severity</Label>
            <Select value={severityFilter} onValueChange={setSeverityFilter}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Severities</SelectItem>
                <SelectItem value="low">Low</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="high">High</SelectItem>
                <SelectItem value="critical">Critical</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </Card>

      {/* Findings Table */}
      <Card className="p-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading findings...</p>
          </div>
        ) : findings.length === 0 ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:check-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No findings found</p>
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Title</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Severity</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Account</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {findings.map((finding) => (
                <TableRow
                  key={finding.id}
                  className="cursor-pointer hover:bg-muted/50"
                  onClick={() => openDetail(finding)}
                >
                  <TableCell className="font-medium">{finding.title}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{finding.finding_type}</TableCell>
                  <TableCell>
                    <Badge className={SEVERITY_COLORS[finding.severity] || ''}>
                      {finding.severity}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    <Badge className={STATUS_COLORS[finding.status] || ''}>
                      {finding.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="font-mono text-sm">{finding.account_code || '-'}</TableCell>
                  <TableCell className="text-right font-mono">
                    {finding.amount != null ? formatCurrency(finding.amount) : '-'}
                  </TableCell>
                  <TableCell onClick={(e) => e.stopPropagation()}>
                    <div className="flex gap-1">
                      {finding.status === 'open' && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => acknowledgeMutation.mutate(finding.id)}
                        >
                          Ack
                        </Button>
                      )}
                      {(finding.status === 'open' || finding.status === 'acknowledged') && (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => investigateMutation.mutate(finding.id)}
                        >
                          Investigate
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      {/* Detail Dialog */}
      <Dialog open={showDetail} onOpenChange={setShowDetail}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{selectedFinding?.title}</DialogTitle>
          </DialogHeader>
          {selectedFinding && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label className="text-xs text-muted-foreground">Type</Label>
                  <p>{selectedFinding.finding_type}</p>
                </div>
                <div>
                  <Label className="text-xs text-muted-foreground">Severity</Label>
                  <p><Badge className={SEVERITY_COLORS[selectedFinding.severity]}>{selectedFinding.severity}</Badge></p>
                </div>
                <div>
                  <Label className="text-xs text-muted-foreground">Status</Label>
                  <p><Badge className={STATUS_COLORS[selectedFinding.status]}>{selectedFinding.status}</Badge></p>
                </div>
                <div>
                  <Label className="text-xs text-muted-foreground">Account</Label>
                  <p className="font-mono">{selectedFinding.account_code || '-'}</p>
                </div>
                {selectedFinding.amount != null && (
                  <div>
                    <Label className="text-xs text-muted-foreground">Amount</Label>
                    <p className="font-mono font-semibold">{formatCurrency(selectedFinding.amount)}</p>
                  </div>
                )}
                {selectedFinding.confidence != null && (
                  <div>
                    <Label className="text-xs text-muted-foreground">Confidence</Label>
                    <p>{(selectedFinding.confidence * 100).toFixed(0)}%</p>
                  </div>
                )}
              </div>
              <div>
                <Label className="text-xs text-muted-foreground">Description</Label>
                <p className="mt-1">{selectedFinding.description}</p>
              </div>
              {selectedFinding.recommendation && (
                <div>
                  <Label className="text-xs text-muted-foreground">Recommendation</Label>
                  <p className="mt-1">{selectedFinding.recommendation}</p>
                </div>
              )}
              {selectedFinding.status === 'resolved' && selectedFinding.resolution_notes && (
                <div>
                  <Label className="text-xs text-muted-foreground">Resolution Notes</Label>
                  <p className="mt-1">{selectedFinding.resolution_notes}</p>
                </div>
              )}

              {/* Action Sections */}
              {selectedFinding.status !== 'resolved' && selectedFinding.status !== 'dismissed' && (
                <>
                  {selectedFinding.status !== 'investigating' && (
                    <div className="border-t pt-4">
                      <Label className="text-xs text-muted-foreground">Resolve</Label>
                      <Textarea
                        className="mt-1"
                        placeholder="Resolution notes (optional)"
                        value={resolveNotes}
                        onChange={(e) => setResolveNotes(e.target.value)}
                      />
                      <Button
                        className="mt-2"
                        onClick={() => resolveMutation.mutate({ id: selectedFinding.id, notes: resolveNotes })}
                      >
                        Resolve Finding
                      </Button>
                    </div>
                  )}
                  <div className="border-t pt-4">
                    <Label className="text-xs text-muted-foreground">Dismiss</Label>
                    <Input
                      className="mt-1"
                      placeholder="Dismiss reason (optional)"
                      value={dismissReason}
                      onChange={(e) => setDismissReason(e.target.value)}
                    />
                    <Button
                      className="mt-2"
                      variant="outline"
                      onClick={() => dismissMutation.mutate({ id: selectedFinding.id, reason: dismissReason })}
                    >
                      Dismiss Finding
                    </Button>
                  </div>
                  <div className="border-t pt-4">
                    <Label className="text-xs text-muted-foreground">Escalate</Label>
                    <Input
                      className="mt-1"
                      placeholder="Escalation reason (optional)"
                      value={escalateReason}
                      onChange={(e) => setEscalateReason(e.target.value)}
                    />
                    <Button
                      className="mt-2"
                      variant="destructive"
                      onClick={() => escalateMutation.mutate({ id: selectedFinding.id, reason: escalateReason })}
                    >
                      Escalate Finding
                    </Button>
                  </div>
                </>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDetail(false)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
