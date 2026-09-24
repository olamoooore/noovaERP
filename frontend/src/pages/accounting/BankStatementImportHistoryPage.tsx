import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';

export default function BankStatementImportHistoryPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const queryClient = useQueryClient();
  const { toast } = useToast();
  const [selectedBankAccount, setSelectedBankAccount] = useState('');
  const [reverseImportId, setReverseImportId] = useState<string | null>(null);
  const [reverseReason, setReverseReason] = useState('');
  const [downloadingImportId, setDownloadingImportId] = useState<string | null>(null);

  const bankAccountsQuery = useQuery({ queryKey: ['bank-accounts'], queryFn: () => reportApi.getBankAccounts() });
  const accountParam = searchParams.get('account');

  useEffect(() => {
    if (accountParam && (bankAccountsQuery.data?.data ?? []).some((account: any) => String(account.id) === accountParam)) {
      setSelectedBankAccount(accountParam);
    }
  }, [accountParam, bankAccountsQuery.data]);

  const importsQuery = useQuery({
    queryKey: ['bank-statement-imports', selectedBankAccount],
    queryFn: () => reportApi.getBankStatementImports(Number(selectedBankAccount)),
    enabled: selectedBankAccount !== '',
  });

  const reverseMutation = useMutation({
    mutationFn: ({ importId, reason }: { importId: string; reason: string }) => reportApi.reverseBankStatementImport(importId, reason),
    onSuccess: () => {
      setReverseImportId(null);
      setReverseReason('');
      queryClient.invalidateQueries({ queryKey: ['bank-statement-imports', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', Number(selectedBankAccount)] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', Number(selectedBankAccount)] });
      toast({ title: 'Statement import reversed', description: 'The source file can now be imported again with corrected statement controls.' });
    },
    onError: (error: any) => toast({ title: 'Import reversal blocked', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const downloadSource = async (batch: any) => {
    try {
      setDownloadingImportId(batch.id);
      const blob = await reportApi.downloadBankStatementSource(batch.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = batch.file_name || 'bank-statement';
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);
    } catch (error: any) {
      toast({ title: 'Source download failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' });
    } finally {
      setDownloadingImportId(null);
    }
  };

  const imports = importsQuery.data?.data ?? [];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Bank Statement Import History</h1>
          <p className="text-muted-foreground mt-1">Review source files and reverse incorrect imports before reimporting.</p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-bold" type="button" variant="outline" onClick={() => navigate(`/accounting/core/reconciliation${selectedBankAccount ? `?account=${selectedBankAccount}` : ''}`)}>
          Back to Reconcile
        </HeaderActionButton>
      </div>

      <Card className="p-6">
        <div className="max-w-md space-y-2">
          <Label>Bank Account</Label>
          <Select value={selectedBankAccount || 'select'} onValueChange={(value) => setSelectedBankAccount(value === 'select' ? '' : value)}>
            <SelectTrigger><SelectValue placeholder="Select a bank account" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="select">Select a bank account</SelectItem>
              {(bankAccountsQuery.data?.data ?? []).map((account: any) => <SelectItem key={account.id} value={String(account.id)}>{account.name} ({account.account_number})</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
      </Card>

      <Card className="p-6">
        {!selectedBankAccount ? <p className="text-sm text-muted-foreground">Select a bank account to view import history.</p>
          : importsQuery.isLoading ? <p className="text-sm text-muted-foreground">Loading import history…</p>
            : importsQuery.error ? <p className="text-sm text-destructive">Unable to load import history.</p>
              : imports.length === 0 ? <p className="text-sm text-muted-foreground">No statement imports found for this bank account.</p>
                : <div className="overflow-x-auto rounded-lg border">
                  <table className="w-full text-sm">
                    <thead><tr className="border-b bg-muted/40">
                      <th className="text-left px-3 py-2">File</th><th className="text-left px-3 py-2">Statement period</th>
                      <th className="text-right px-3 py-2">Opening</th><th className="text-right px-3 py-2">Closing</th>
                      <th className="text-left px-3 py-2">Status</th><th className="text-right px-3 py-2">Rows</th>
                      <th className="text-right px-3 py-2">Created</th><th className="text-right px-3 py-2">Duplicates</th>
                      <th className="text-right px-3 py-2">Errors</th><th className="text-right px-3 py-2">Actions</th>
                    </tr></thead>
                    <tbody>{imports.map((batch: any) => <tr key={batch.id} className="border-b last:border-0">
                      <td className="px-3 py-2 font-medium">{batch.file_name}</td>
                      <td className="px-3 py-2 whitespace-nowrap">{batch.statement_start_date ? `${String(batch.statement_start_date).slice(0, 10)} – ` : ''}{String(batch.statement_end_date ?? '').slice(0, 10) || '—'}</td>
                      <td className="px-3 py-2 text-right">{formatCurrency(Number(batch.opening_balance ?? 0))}</td>
                      <td className="px-3 py-2 text-right">{formatCurrency(Number(batch.closing_balance ?? 0))}</td>
                      <td className="px-3 py-2 capitalize">{String(batch.status).replace('_', ' ')}</td>
                      <td className="px-3 py-2 text-right">{batch.row_count ?? 0}</td><td className="px-3 py-2 text-right">{batch.transaction_count ?? 0}</td>
                      <td className="px-3 py-2 text-right">{batch.duplicate_count ?? 0}</td><td className="px-3 py-2 text-right">{batch.error_count ?? 0}</td>
                      <td className="px-3 py-2"><div className="flex justify-end gap-2">
                        <Button type="button" size="sm" variant="ghost" disabled={downloadingImportId === batch.id} onClick={() => downloadSource(batch)}>{downloadingImportId === batch.id ? 'Downloading…' : 'Download'}</Button>
                        {['imported', 'failed'].includes(batch.status) && <Button type="button" size="sm" variant="outline" className="text-destructive" onClick={() => { setReverseImportId(batch.id); setReverseReason(''); }}>Reverse</Button>}
                      </div></td>
                    </tr>)}</tbody>
                  </table>
                </div>}
      </Card>

      <Dialog open={reverseImportId !== null} onOpenChange={(open) => { if (!open) { setReverseImportId(null); setReverseReason(''); } }}>
        <DialogContent><DialogHeader><DialogTitle>Reverse statement import</DialogTitle><DialogDescription>This reverses imported transactions and releases uncompleted matches. Reverse a completed reconciliation first when applicable.</DialogDescription></DialogHeader>
          <div className="space-y-2"><Label htmlFor="import-reversal-reason">Reason</Label><Input id="import-reversal-reason" value={reverseReason} onChange={(event) => setReverseReason(event.target.value)} placeholder="Why is this import being reversed?" /></div>
          <DialogFooter><Button type="button" variant="outline" onClick={() => { setReverseImportId(null); setReverseReason(''); }}>Cancel</Button><Button type="button" variant="destructive" disabled={!reverseReason.trim() || reverseMutation.isPending} onClick={() => reverseImportId && reverseMutation.mutate({ importId: reverseImportId, reason: reverseReason.trim() })}>{reverseMutation.isPending ? 'Reversing…' : 'Reverse import'}</Button></DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
