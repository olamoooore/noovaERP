import { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { useVendorDebitNotes, useDeleteVendorDebitNote } from '@/hooks/useVendorDebitNotes';
import type { VendorDebitNoteFilters } from '@/types/vendorDebitNote';
import CreateVendorDebitNoteDialog from './components/CreateVendorDebitNoteDialog';
import EditVendorDebitNoteDialog from './components/EditVendorDebitNoteDialog';
import VendorDebitNotePostButton from './components/VendorDebitNotePostButton';
import { useToast } from '@/hooks/use-toast';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Pagination } from '@/components/ui/Pagination';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@iconify/react';
import { SortableHead } from '@/components/ui/SortableHead';
import { useSort } from '@/hooks/useSort';
import { cn, formatCurrency, formatDate } from '@/lib/utils';

function StatusBadge({ status }: { status: string }) {
  const statusConfig: Record<string, { label: string; icon: string; className: string }> = {
    draft: {
      label: 'Draft',
      icon: 'solar:document-bold-duotone',
      className: 'bg-muted text-muted-foreground',
    },
    posted: {
      label: 'Posted',
      icon: 'solar:check-circle-bold-duotone',
      className: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300',
    },
    cancelled: {
      label: 'Cancelled',
      icon: 'solar:close-circle-bold-duotone',
      className: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
    },
  };
  const key = String(status || '').toLowerCase();
  const config = statusConfig[key] || {
    label: key ? key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '-',
    icon: 'solar:document-bold-duotone',
    className: 'bg-muted text-muted-foreground',
  };

  return (
    <Badge
      variant="outline"
      className={cn('gap-1 whitespace-nowrap border-transparent px-1.5 py-0 text-[10px] font-medium leading-4', config.className)}
    >
      <Icon icon={config.icon} className="h-3 w-3" />
      {config.label}
    </Badge>
  );
}

export default function VendorDebitNotesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [searchParams] = useSearchParams();
  const [searchTerm, setSearchTerm] = useState(searchParams.get('search') || '');
  const [filters, setFilters] = useState<VendorDebitNoteFilters>({
    search: searchParams.get('search') || undefined,
  });
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);
  const [showCreateDialog, setShowCreateDialog] = useState(false);
  const [editingDebitNoteId, setEditingDebitNoteId] = useState<number | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; status: string } | null>(null);

  const { data, isLoading, error, refetch } = useVendorDebitNotes(filters, page, pageSize);
  const deleteMutation = useDeleteVendorDebitNote();

  const requestDelete = (id: number, status: string) => {
    if (status === 'posted') {
      toast({
        variant: 'destructive',
        title: 'Cannot delete',
        description: 'Posted debit notes are immutable for compliance.',
      });
      return;
    }
    setDeleteTarget({ id, status });
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    try {
      await deleteMutation.mutateAsync(deleteTarget.id);
      toast({ title: 'Deleted', description: 'Debit note deleted successfully.' });
      setDeleteTarget(null);
      refetch();
    } catch (err: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to delete',
        description: err.response?.data?.message || 'Failed to delete debit note.',
      });
    }
  };

  const handleFilterChange = (key: keyof VendorDebitNoteFilters, value: any) => {
    setFilters((prev) => ({ ...prev, [key]: value }));
    setPage(1);
  };

  const resetFilters = () => {
    setSearchTerm('');
    setFilters({ search: undefined, status: undefined });
    setPage(1);
  };

  const debitNotes = data?.data || [];
  const { sorted: sortedDebitNotes, sortKey, sortDir, toggle: toggleSort } = useSort<any>(
    debitNotes,
    'debit_note_date',
    'desc',
  );
  const pagination = data?.meta || {};
  const hasFilters = Boolean(filters.search || filters.status);
  const totalRecords = pagination.total ?? debitNotes.length;

  return (
    <div className="p-6 space-y-6">
      {/* Header — search and filters sit with the actions so the page does not
          spend a whole row on them. */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Debit Notes</h1>
          <p className="mt-1 text-muted-foreground">Manage debit notes for vendor returns and corrections</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-full sm:w-64">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              type="text"
              placeholder="Search by debit note number or vendor…"
              value={searchTerm}
              onChange={(e) => {
                const value = e.target.value;
                setSearchTerm(value);
                handleFilterChange('search', value || undefined);
              }}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>

          <Select
            value={filters.status || 'all'}
            onValueChange={(value) => handleFilterChange('status', value === 'all' ? undefined : value)}
          >
            <SelectTrigger className="h-8 w-40 rounded-full text-xs" aria-label="Filter by status">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="posted">Posted</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>

          {hasFilters && (
            <HeaderActionButton variant="ghost" icon="solar:restart-bold" onClick={resetFilters}>
              Reset
            </HeaderActionButton>
          )}

          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => setShowCreateDialog(true)}>
            New Debit Note
          </HeaderActionButton>
        </div>
      </div>

      {/* List */}
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <CardTitle className="text-base">Debit Notes</CardTitle>
          {totalRecords > 0 && (
            <span className="text-sm font-normal text-muted-foreground">
              {totalRecords} debit note(s)
            </span>
          )}
        </CardHeader>
        <CardContent className="pt-6">
          {isLoading ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2">Loading debit notes…</p>
            </div>
          ) : error ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto mb-4 h-12 w-12 text-destructive opacity-60" />
              <p className="text-lg font-medium mb-2">Error loading debit notes</p>
              <p className="text-sm">Please try again later.</p>
            </div>
          ) : debitNotes.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:document-add-bold-duotone" className="mx-auto mb-4 h-12 w-12 opacity-50" />
              {hasFilters ? (
                <>
                  <p className="text-lg font-medium mb-2">No debit notes match your filters</p>
                  <p className="mb-4 text-sm">Try a different search or status.</p>
                  <HeaderActionButton variant="outline" icon="solar:restart-bold" onClick={resetFilters}>
                    Reset filters
                  </HeaderActionButton>
                </>
              ) : (
                <>
                  <p className="text-lg font-medium mb-2">No debit notes found</p>
                  <p className="mb-4 text-sm">Create your first debit note to get started.</p>
                  <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => setShowCreateDialog(true)}>
                    Create Debit Note
                  </HeaderActionButton>
                </>
              )}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Debit Note #" sortKey="debit_note_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Vendor" sortKey="vendor_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Bill #" sortKey="bill_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Date" sortKey="debit_note_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Amount" sortKey="total_amount" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead className="w-[8.5rem] text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedDebitNotes.map((debitNote: any) => (
                    <TableRow
                      key={debitNote.id}
                      className="cursor-pointer hover:bg-accent/50"
                      onClick={() => navigate(`/accounting/vendors/debit-notes/${debitNote.id}`)}
                    >
                      <TableCell className="font-medium text-primary">
                        {debitNote.debit_note_number}
                      </TableCell>
                      <TableCell className="font-medium text-primary hover:underline">
                        {debitNote.vendor_name || '-'}
                      </TableCell>
                      <TableCell>{debitNote.bill_number || '-'}</TableCell>
                      <TableCell>{debitNote.debit_note_date ? formatDate(debitNote.debit_note_date) : '-'}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatCurrency(Number(debitNote.total_amount || 0), debitNote.currency_code)}
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={debitNote.status} />
                      </TableCell>
                      <TableCell className="w-[8.5rem] text-right" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1">
                          {debitNote.status === 'draft' && (
                            <>
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7"
                                title="Edit"
                                aria-label="Edit debit note"
                                onClick={() => setEditingDebitNoteId(debitNote.id)}
                              >
                                <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                              </Button>
                              <VendorDebitNotePostButton creditNoteId={debitNote.id} onSuccess={refetch} />
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                title="Delete"
                                aria-label="Delete debit note"
                                onClick={() => requestDelete(debitNote.id, debitNote.status)}
                              >
                                <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                              </Button>
                            </>
                          )}
                          <Button
                            size="icon"
                            variant="ghost"
                            className="h-7 w-7"
                            title="View"
                            aria-label="View debit note"
                            onClick={() => navigate(`/accounting/vendors/debit-notes/${debitNote.id}`)}
                          >
                            <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          <Pagination
            page={page}
            totalPages={pagination.last_page ?? 1}
            onPageChange={setPage}
            from={pagination.from}
            to={pagination.to}
            total={pagination.total}
            label="debit notes"
            pageSize={pageSize}
            onPageSizeChange={(size) => {
              setPageSize(size);
              setPage(1);
            }}
          />
        </CardContent>
      </Card>

      {showCreateDialog && (
        <CreateVendorDebitNoteDialog
          onClose={() => setShowCreateDialog(false)}
          onSuccess={() => {
            setShowCreateDialog(false);
            refetch();
          }}
        />
      )}

      {editingDebitNoteId && (
        <EditVendorDebitNoteDialog
          debitNoteId={editingDebitNoteId}
          onClose={() => setEditingDebitNoteId(null)}
          onSuccess={() => {
            setEditingDebitNoteId(null);
            refetch();
          }}
        />
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title="Delete this debit note?"
        description="Only draft debit notes can be deleted. This cannot be undone."
        confirmLabel="Delete"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
