import { useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { useVendorCreditNotes, useDeleteVendorCreditNote } from '@/hooks/useVendorCreditNotes';
import type { VendorCreditNoteFilters } from '@/types/vendorCreditNote';
import CreateVendorCreditNoteDialog from './components/CreateVendorCreditNoteDialog';
import VendorCreditNotePostButton from './components/VendorCreditNotePostButton';
import { useToast } from '@/hooks/use-toast';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Icon } from '@iconify/react';

function StatusBadge({ status }: { status: string }) {
  const statusConfig: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
    draft: { variant: 'secondary', label: 'Draft' },
    posted: { variant: 'default', label: 'Posted' },
    cancelled: { variant: 'destructive', label: 'Cancelled' },
  };

  const config = statusConfig[status] || { variant: 'secondary', label: status };
  return <Badge variant={config.variant}>{config.label}</Badge>;
}

export default function VendorCreditNotesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [searchParams] = useSearchParams();
  const [searchTerm, setSearchTerm] = useState(searchParams.get('search') || '');
  const [filters, setFilters] = useState<VendorCreditNoteFilters>({
    search: searchParams.get('search') || undefined,
  });
  const [page, setPage] = useState(1);
  const [showCreateDialog, setShowCreateDialog] = useState(false);

  const { data, isLoading, error, refetch } = useVendorCreditNotes(filters, page);
  const deleteMutation = useDeleteVendorCreditNote();

  const handleDelete = async (id: number, status: string) => {
    if (status === 'posted') {
      toast({ variant: 'destructive', title: 'Cannot delete', description: 'Posted credit notes are immutable for compliance.' });
      return;
    }

    if (confirm('Are you sure you want to delete this credit note?')) {
      try {
        await deleteMutation.mutateAsync(id);
        toast({ title: 'Deleted', description: 'Credit note deleted successfully.' });
        refetch();
      } catch (err: any) {
        toast({ variant: 'destructive', title: 'Failed to delete', description: err.response?.data?.message || 'Failed to delete credit note.' });
      }
    }
  };

  const handleFilterChange = (key: keyof VendorCreditNoteFilters, value: any) => {
    setFilters(prev => ({ ...prev, [key]: value }));
    setPage(1);
  };

  const creditNotes = data?.data || [];
  const pagination = data?.meta || {};

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Vendor Credit Notes</h1>
          <p className="text-muted-foreground mt-1">Manage vendor credit notes for returns and corrections</p>
        </div>
        <Button onClick={() => setShowCreateDialog(true)}>
          <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
          New Credit Note
        </Button>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <Input
              type="text"
              placeholder="Search by credit note number or vendor..."
              value={searchTerm}
              onChange={(e) => {
                const value = e.target.value;
                setSearchTerm(value);
                handleFilterChange('search', value || undefined);
              }}
              className="max-w-sm"
            />
            <select
              value={filters.status || ''}
              onChange={(e) => handleFilterChange('status', e.target.value || undefined)}
              className="px-3 py-2 border border-input rounded-md bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="">All Status</option>
              <option value="draft">Draft</option>
              <option value="posted">Posted</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </CardContent>
      </Card>

      {/* Credit Notes List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Vendor Credit Notes</span>
            {creditNotes.length > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {creditNotes.length} credit note(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading credit notes...</p>
            </div>
          ) : error ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:danger-triangle-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50 text-destructive" />
              <p className="text-lg font-medium mb-2">Error loading credit notes</p>
              <p className="text-sm">Please try again later.</p>
            </div>
          ) : creditNotes.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:document-add-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No credit notes found</p>
              <p className="text-sm mb-4">Create your first credit note to get started.</p>
              <Button onClick={() => setShowCreateDialog(true)}>
                <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                Create Credit Note
              </Button>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Credit Note #</TableHead>
                    <TableHead>Vendor</TableHead>
                    <TableHead>Bill #</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {creditNotes.map((creditNote: any) => (
                    <TableRow key={creditNote.id} className="hover:bg-accent/50 cursor-pointer">
                      <TableCell 
                        className="font-medium cursor-pointer hover:text-primary"
                        onClick={() => navigate(`/accounting/vendors/credit-notes/${creditNote.id}`)}
                      >
                        {creditNote.credit_note_number}
                      </TableCell>
                      <TableCell>{creditNote.vendor_name || '-'}</TableCell>
                      <TableCell>{creditNote.bill_number || '-'}</TableCell>
                      <TableCell>{new Date(creditNote.credit_note_date).toLocaleDateString()}</TableCell>
                      <TableCell className="text-right font-medium">
                        {creditNote.currency_code} {parseFloat(creditNote.total_amount).toFixed(2)}
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={creditNote.status} />
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex gap-1 justify-end">
                          {creditNote.status === 'draft' && (
                            <>
                              <VendorCreditNotePostButton 
                                creditNoteId={creditNote.id} 
                                onSuccess={refetch}
                              />
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => handleDelete(creditNote.id, creditNote.status)}
                              >
                                <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4 text-destructive" />
                              </Button>
                            </>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => navigate(`/accounting/vendors/credit-notes/${creditNote.id}`)}
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

          {/* Pagination */}
          {pagination.last_page > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {pagination.from} to {pagination.to} of {pagination.total} results
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage(page - 1)}
                  disabled={page === 1}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage(page + 1)}
                  disabled={page === pagination.last_page}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {showCreateDialog && (
        <CreateVendorCreditNoteDialog
          onClose={() => setShowCreateDialog(false)}
          onSuccess={() => {
            setShowCreateDialog(false);
            refetch();
          }}
        />
      )}
    </div>
  );
}
