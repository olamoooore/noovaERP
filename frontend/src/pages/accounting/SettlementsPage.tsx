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
import { Pagination } from '@/components/ui/Pagination';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import settlementsApi, { Settlement } from '@/lib/settlementsApi';
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';

const STATE_COLORS: Record<string, string> = {
  pending: 'bg-gray-100 text-gray-800',
  partially_allocated: 'bg-yellow-100 text-yellow-800',
  fully_allocated: 'bg-green-100 text-green-800',
  reversed: 'bg-red-100 text-red-800',
};

export default function SettlementsPage() {
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [stateFilter, setStateFilter] = useState<string>('all');
  const [sourceTypeFilter, setSourceTypeFilter] = useState<string>('all');
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(25);
  const [selectedSettlement, setSelectedSettlement] = useState<Settlement | null>(null);
  const [showAllocate, setShowAllocate] = useState(false);
  const [showReverse, setShowReverse] = useState(false);
  const [reverseReason, setReverseReason] = useState('');
  const [allocations, setAllocations] = useState<{ source_record_type: string; source_record_id: string; amount: string }[]>([
    { source_record_type: 'App\\Models\\Invoice', source_record_id: '', amount: '' },
  ]);

  const { data, isLoading } = useQuery({
    queryKey: ['settlements', stateFilter, sourceTypeFilter, page, pageSize],
    queryFn: () => settlementsApi.getSettlements({
      state: stateFilter === 'all' ? undefined : stateFilter,
      source_type: sourceTypeFilter === 'all' ? undefined : sourceTypeFilter,
      page,
      per_page: pageSize,
    }),
  });

  const allocateMutation = useMutation({
    mutationFn: settlementsApi.allocate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settlements'] });
      setShowAllocate(false);
      setAllocations([{ source_record_type: 'App\\Models\\Invoice', source_record_id: '', amount: '' }]);
      toast({ title: 'Settlement allocated successfully' });
    },
    onError: (error: any) => {
      toast({ title: 'Allocation failed', description: error.response?.data?.error || 'Unknown error', variant: 'destructive' });
    },
  });

  const reverseMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
      settlementsApi.reverse(id, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settlements'] });
      setShowReverse(false);
      setReverseReason('');
      toast({ title: 'Settlement reversed' });
    },
  });

  const openAllocate = (settlement: Settlement) => {
    setSelectedSettlement(settlement);
    setAllocations([{ source_record_type: 'App\\Models\\Invoice', source_record_id: '', amount: '' }]);
    setShowAllocate(true);
  };

  const openReverse = (settlement: Settlement) => {
    setSelectedSettlement(settlement);
    setReverseReason('');
    setShowReverse(true);
  };

  const addAllocationRow = () => {
    setAllocations([...allocations, { source_record_type: 'App\\Models\\Invoice', source_record_id: '', amount: '' }]);
  };

  const updateAllocation = (index: number, field: string, value: string) => {
    const updated = [...allocations];
    updated[index] = { ...updated[index], [field]: value };
    setAllocations(updated);
  };

  const removeAllocation = (index: number) => {
    setAllocations(allocations.filter((_, i) => i !== index));
  };

  const submitAllocate = () => {
    if (!selectedSettlement) return;
    const validAllocations = allocations
      .filter(a => a.source_record_id && a.amount)
      .map(a => ({
        source_record_type: a.source_record_type,
        source_record_id: parseInt(a.source_record_id),
        amount: parseFloat(a.amount),
      }));
    if (validAllocations.length === 0) {
      toast({ title: 'Please add at least one valid allocation', variant: 'destructive' });
      return;
    }
    allocateMutation.mutate({ settlement_id: selectedSettlement.id, allocations: validAllocations });
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Settlements</h1>
          <p className="text-muted-foreground mt-1">
            Manage payment allocations, unapplied cash, and reversals
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={stateFilter} onValueChange={setStateFilter}>
            <SelectTrigger className="h-8 w-44 rounded-full text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All States</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="partially_allocated">Partially Allocated</SelectItem>
              <SelectItem value="fully_allocated">Fully Allocated</SelectItem>
              <SelectItem value="reversed">Reversed</SelectItem>
            </SelectContent>
          </Select>
          <Select value={sourceTypeFilter} onValueChange={setSourceTypeFilter}>
            <SelectTrigger className="h-8 w-40 rounded-full text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Types</SelectItem>
              <SelectItem value="payment">Payment</SelectItem>
              <SelectItem value="credit_note">Credit Note</SelectItem>
              <SelectItem value="debit_note">Debit Note</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Settlements Table */}
      <Card className="p-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading settlements...</p>
          </div>
        ) : !data?.data || data.data.length === 0 ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:wallet-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No settlements found</p>
          </div>
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>ID</TableHead>
                  <TableHead>State</TableHead>
                  <TableHead>Source</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Allocated</TableHead>
                  <TableHead className="text-right">Unapplied</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.data.map((settlement) => (
                  <TableRow key={settlement.id}>
                    <TableCell className="font-mono">#{settlement.id}</TableCell>
                    <TableCell>
                      <Badge className={STATE_COLORS[settlement.settlement_state] || ''}>
                        {settlement.settlement_state.replace(/_/g, ' ')}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {settlement.source_record_type?.split('\\').pop() || '-'}
                    </TableCell>
                    <TableCell className="text-right font-mono">{formatCurrency(settlement.total_amount)}</TableCell>
                    <TableCell className="text-right font-mono">{formatCurrency(settlement.allocated_amount)}</TableCell>
                    <TableCell className="text-right font-mono">{formatCurrency(settlement.unapplied_amount)}</TableCell>
                    <TableCell>
                      {new Date(settlement.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                    </TableCell>
                    <TableCell>
                      <div className="flex gap-1">
                        {(settlement.settlement_state === 'pending' || settlement.settlement_state === 'partially_allocated') && (
                          <Button size="sm" variant="outline" onClick={() => openAllocate(settlement)}>
                            Allocate
                          </Button>
                        )}
                        {settlement.settlement_state !== 'reversed' && (
                          <Button size="sm" variant="outline" onClick={() => openReverse(settlement)}>
                            Reverse
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {/* Pagination */}
            <Pagination
              page={page}
              totalPages={data.last_page}
              onPageChange={setPage}
              from={(page - 1) * pageSize + 1}
              to={Math.min(page * pageSize, data.total)}
              total={data.total}
              label="settlements"
              pageSize={pageSize}
              onPageSizeChange={(size) => { setPageSize(size); setPage(1); }}
            />
          </>
        )}
      </Card>

      {/* Allocate Dialog */}
      <Dialog open={showAllocate} onOpenChange={setShowAllocate}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Allocate Settlement #{selectedSettlement?.id}</DialogTitle>
          </DialogHeader>
          {selectedSettlement && (
            <div className="space-y-4">
              <div className="p-3 bg-muted rounded-lg">
                <div className="flex justify-between text-sm">
                  <span>Unapplied Amount:</span>
                  <span className="font-mono font-semibold">{formatCurrency(selectedSettlement.unapplied_amount)}</span>
                </div>
              </div>

              <div className="space-y-3">
                {allocations.map((alloc, index) => (
                  <div key={index} className="grid grid-cols-12 gap-2 items-end">
                    <div className="col-span-4">
                      <Label className="text-xs">Type</Label>
                      <Select value={alloc.source_record_type} onValueChange={(v) => updateAllocation(index, 'source_record_type', v)}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="App\\Models\\Invoice">Invoice</SelectItem>
                          <SelectItem value="App\\Models\\VendorBill">Vendor Bill</SelectItem>
                          <SelectItem value="App\\Models\\CustomerCreditNote">Credit Note</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="col-span-3">
                      <Label className="text-xs">Record ID</Label>
                      <Input
                        type="number"
                        value={alloc.source_record_id}
                        onChange={(e) => updateAllocation(index, 'source_record_id', e.target.value)}
                        placeholder="ID"
                      />
                    </div>
                    <div className="col-span-4">
                      <Label className="text-xs">Amount</Label>
                      <Input
                        type="number"
                        step="0.01"
                        value={alloc.amount}
                        onChange={(e) => updateAllocation(index, 'amount', e.target.value)}
                        placeholder="0.00"
                      />
                    </div>
                    <div className="col-span-1">
                      {allocations.length > 1 && (
                        <Button variant="ghost" size="sm" onClick={() => removeAllocation(index)}>
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
              </div>

              <Button variant="outline" size="sm" onClick={addAllocationRow}>
                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                Add Allocation
              </Button>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAllocate(false)}>Cancel</Button>
            <Button onClick={submitAllocate} disabled={allocateMutation.isPending}>
              {allocateMutation.isPending ? 'Allocating...' : 'Allocate'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reverse Dialog */}
      <Dialog open={showReverse} onOpenChange={setShowReverse}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reverse Settlement #{selectedSettlement?.id}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              This will reverse all allocations ({formatCurrency(selectedSettlement?.allocated_amount || 0)} allocated) and return the funds to unapplied cash.
            </p>
            <div className="space-y-2">
              <Label>Reason (optional)</Label>
              <Textarea
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                placeholder="Reason for reversal..."
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowReverse(false)}>Cancel</Button>
            <Button
              variant="destructive"
              onClick={() => selectedSettlement && reverseMutation.mutate({ id: selectedSettlement.id, reason: reverseReason })}
              disabled={reverseMutation.isPending}
            >
              {reverseMutation.isPending ? 'Reversing...' : 'Reverse Settlement'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
