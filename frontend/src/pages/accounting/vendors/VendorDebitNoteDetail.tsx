import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams, useNavigate } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import api from '@/lib/api';

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  posted: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
};

const formatCurrency = (amount: number | string, currency = 'USD') =>
  new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(Number(amount) || 0);

const formatDate = (date: string) =>
  date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '-';

export const VendorDebitNoteDetail: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const { data: debitNote, isLoading } = useQuery({
    queryKey: ['vendor-debit-note', id],
    queryFn: async () => {
      const response = await api.get(`/accounting/vendors/debit-notes/${id}`);
      return response.data;
    },
    enabled: !!id,
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!debitNote) {
    return (
      <div className="p-6 text-center text-muted-foreground">
        Debit note not found.
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/accounting/vendors/debit-notes')}>
            <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold">Debit Note {debitNote.debit_note_number}</h1>
            <p className="text-muted-foreground">
              {debitNote.vendor?.name || debitNote.vendor_name || 'Unknown Vendor'}
            </p>
          </div>
        </div>
        <Badge className={statusColors[debitNote.status] || 'bg-gray-100 text-gray-800'}>
          {debitNote.status?.charAt(0).toUpperCase() + debitNote.status?.slice(1)}
        </Badge>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Date</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold">{formatDate(debitNote.debit_note_date)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Related Bill</CardTitle>
          </CardHeader>
          <CardContent>
            {debitNote.bill ? (
              <button
                className="text-lg font-semibold text-blue-600 hover:underline"
                onClick={() => navigate(`/accounting/vendors/bills/${debitNote.bill.id}`)}
              >
                {debitNote.bill.bill_number || debitNote.bill.uuid?.slice(0, 8)}
              </button>
            ) : (
              <p className="text-lg font-semibold text-muted-foreground">-</p>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm text-muted-foreground">Total Amount</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-lg font-semibold">
              {formatCurrency(debitNote.total_amount, debitNote.currency_code)}
            </p>
          </CardContent>
        </Card>
      </div>

      {debitNote.lines && debitNote.lines.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Line Items</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Description</TableHead>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Quantity</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {debitNote.lines.map((line: any) => (
                  <TableRow key={line.id}>
                    <TableCell>{line.description || line.product?.name || '-'}</TableCell>
                    <TableCell>{line.account?.name || '-'}</TableCell>
                    <TableCell className="text-right">{line.quantity}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.unit_price, debitNote.currency_code)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(line.line_total, debitNote.currency_code)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {debitNote.journal_entry && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5" />
              Journal Entry
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Debit</TableHead>
                  <TableHead className="text-right">Credit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {debitNote.journal_entry.lines?.map((line: any) => (
                  <TableRow key={line.id}>
                    <TableCell>{line.account?.name || '-'}</TableCell>
                    <TableCell className="text-right">{line.debit ? formatCurrency(line.debit, debitNote.currency_code) : '-'}</TableCell>
                    <TableCell className="text-right">{line.credit ? formatCurrency(line.credit, debitNote.currency_code) : '-'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-sm text-muted-foreground">Additional Info</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          {debitNote.reason && (
            <div>
              <p className="text-muted-foreground">Reason</p>
              <p className="font-medium">{debitNote.reason}</p>
            </div>
          )}
          {debitNote.created_by && (
            <div>
              <p className="text-muted-foreground">Created By</p>
              <p className="font-medium">{debitNote.created_by?.name || '-'}</p>
            </div>
          )}
          {debitNote.posted_by && (
            <div>
              <p className="text-muted-foreground">Posted By</p>
              <p className="font-medium">{debitNote.posted_by?.name || '-'}</p>
            </div>
          )}
          <div>
            <p className="text-muted-foreground">Created</p>
            <p className="font-medium">{formatDate(debitNote.created_at)}</p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default VendorDebitNoteDetail;
