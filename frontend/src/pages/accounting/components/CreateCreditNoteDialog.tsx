import { useState, useEffect, useCallback } from 'react';
import { useCreateCreditNote } from '@/hooks/useCustomerCreditNotes';
import type { CreateCreditNotePayload } from '@/types/creditNote';
import { Icon } from '@iconify/react';
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
import { useToast } from '@/hooks/use-toast';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { cn, formatCurrency } from '@/lib/utils';
import { todayDateOnly } from '@/lib/dateOnly'; // #519
import api from '@/lib/api';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';
import { SalesDocumentLineGrid } from '@/components/sales/SalesDocumentLineGrid';
import {
  creditableQuantity,
  emptySalesDocumentLine,
  exceedsCreditableQuantity,
  getSalesDocumentLineTotal,
  withCreditQuantity,
  type SalesDocumentLine,
} from '@/lib/salesDocumentLines';

interface CreateCreditNoteDialogProps {
  onClose: () => void;
  onSuccess: () => void;
  invoiceId?: number;
  /** #527: called with the new draft invoice after "Full refund and new draft invoice". */
  onReissued?: (invoiceId: number) => void;
}

interface Invoice {
  id: number;
  invoice_number: string;
  customer_id: number | null;
  customer: { name: string } | null;
  total: number;
  balance: number;
  status: string;
  currency_code?: string;
  lines: InvoiceLine[];
}

interface InvoiceLine {
  id: number;
  product_id: number | null;
  product_name: string | null;
  description: string;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  tax_inclusive: boolean;
  discount_amount: number;
  line_total: number;
  account_id: number | null;
}

// #522 (CN-UX1): one row per invoice line, carrying what was invoiced, what
// POSTED credit notes have already credited (the same rule the #513 per-line
// cap enforces at post) and how much this credit note credits. #526 (CN-UX5):
// the rows are shared SalesDocumentLine rows in the grid's credit mode, so the
// line arithmetic (discount, tax-inclusive) is the shared one.
type CreditMode = 'full' | 'partial';

// #527 (SALES-ODOO): the reversal wizard's three modes. 'partial' is the
// #522 draft credit note to edit; the two full modes credit everything not
// yet credited and POST it (reconciled with the invoice), the last one also
// re-issues the invoice as a new draft linked to the credit note.
type RefundMethod = 'partial' | 'full_refund' | 'full_refund_new_draft';

const REFUND_METHODS: Array<{ value: RefundMethod; label: string; help: string }> = [
  {
    value: 'partial',
    label: 'Partial refund',
    help: 'Creates a draft credit note you can edit before posting.',
  },
  {
    value: 'full_refund',
    label: 'Full refund',
    help: 'Credits everything not yet credited and posts it; it is reconciled with the invoice.',
  },
  {
    value: 'full_refund_new_draft',
    label: 'Full refund and new draft invoice',
    help: 'As full refund, then copies the invoice into a new draft to correct and re-issue.',
  },
];

interface PriorCreditNote {
  status: string;
  lines?: Array<{ invoice_line_id: number | null; quantity: number | string }>;
}

function creditedQuantities(notes: PriorCreditNote[]): Record<number, number> {
  const credited: Record<number, number> = {};
  for (const note of notes) {
    if (note.status !== 'posted') continue;
    for (const line of note.lines ?? []) {
      if (line.invoice_line_id == null) continue;
      credited[line.invoice_line_id] = (credited[line.invoice_line_id] ?? 0) + (Number(line.quantity) || 0);
    }
  }
  return credited;
}

function buildRows(invoice: Invoice, credited: Record<number, number>, mode: CreditMode): SalesDocumentLine[] {
  return invoice.lines.map((line) => {
    const credit = {
      invoice_line_id: line.id,
      invoiced_quantity: Number(line.quantity) || 0,
      credited_quantity: credited[line.id] ?? 0,
      invoiced_discount: Number(line.discount_amount) || 0,
    };
    const row: SalesDocumentLine = {
      ...emptySalesDocumentLine(),
      product_id: line.product_id != null ? String(line.product_id) : '',
      description: line.description,
      unit_price: Number(line.unit_price) || 0,
      tax_rate: Number(line.tax_rate) || 0,
      tax_inclusive: !!line.tax_inclusive,
      credit,
    };
    return withCreditQuantity(row, mode === 'full' ? creditableQuantity(credit) : 0);
  });
}

export default function CreateCreditNoteDialog({ onClose, onSuccess, invoiceId, onReissued }: CreateCreditNoteDialogProps) {
  const { toast } = useToast();
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [formData, setFormData] = useState({
    invoice_id: invoiceId ?? 0,
    customer_id: 0,
    credit_note_date: todayDateOnly(), // #519: local business date, never UTC
    reason: '',
    notes: '',
  });
  // #522: partial is the default — lines start at 0 so nothing is credited
  // unless the user asks for it.
  const [mode, setMode] = useState<CreditMode>('partial');
  const [refundMethod, setRefundMethod] = useState<RefundMethod>('partial');
  const [isReversing, setIsReversing] = useState(false);
  const isFullRefund = refundMethod !== 'partial';
  // The full refund modes always show the full remaining quantity per line.
  const lineMode: CreditMode = isFullRefund ? 'full' : mode;
  const [credited, setCredited] = useState<Record<number, number> | null>(null);
  const [creditsError, setCreditsError] = useState<string | null>(null);
  const [rows, setRows] = useState<SalesDocumentLine[]>([]);

  const createMutation = useCreateCreditNote();

  const selectInvoice = useCallback((invoice: Invoice) => {
    setSelectedInvoice(invoice);
    setFormData(prev => ({
      ...prev,
      invoice_id: invoice.id,
      customer_id: invoice.customer_id || 0,
    }));
  }, []);

  // #522: what earlier POSTED credit notes already credited, per invoice line.
  useEffect(() => {
    if (!selectedInvoice) {
      setCredited(null);
      setCreditsError(null);
      return;
    }
    let cancelled = false;
    setCredited(null);
    setCreditsError(null);
    api.get(`/accounting/customers/invoices/${selectedInvoice.id}/credit-notes`)
      .then(res => {
        if (cancelled) return;
        const notes = (res.data?.data ?? res.data ?? []) as PriorCreditNote[];
        setCredited(creditedQuantities(notes));
      })
      .catch(err => {
        if (cancelled) return;
        console.error('Failed to load earlier credit notes:', err);
        setCreditsError('Could not load earlier credit notes for this invoice, so the quantities already credited are unknown. Posting still rejects any line credited beyond what was invoiced.');
        setCredited({});
      });
    return () => { cancelled = true; };
  }, [selectedInvoice]);

  // Rebuild the rows when the invoice, its earlier credits or the mode change.
  useEffect(() => {
    if (!selectedInvoice || credited === null) {
      setRows([]);
      return;
    }
    setRows(buildRows(selectedInvoice, credited, lineMode));
  }, [selectedInvoice, credited, lineMode]);

  // Fetch data
  useEffect(() => {
    setIsLoading(true);

    if (invoiceId) {
      // When opened from an invoice detail page, fetch that specific invoice
      api.get(`/invoices/${invoiceId}`)
        .then(res => {
          selectInvoice(res.data as Invoice);
        })
        .catch(err => console.error('Failed to load invoice:', err))
        .finally(() => setIsLoading(false));
    } else {
      // When opened from the credit notes list page, fetch all posted invoices
      api.get('/invoices', {
        params: { status: 'posted,paid,sent,overdue,partial', per_page: 100 },
      })
        .then(res => {
          const data = (res.data.data || res.data || []) as Invoice[];
          setInvoices(data);
        })
        .catch(err => console.error('Failed to load invoices:', err))
        .finally(() => setIsLoading(false));
    }
  }, [invoiceId, selectInvoice]);

  const handleSelect = (invoiceIdStr: string) => {
    const id = Number(invoiceIdStr);
    const invoice = invoices.find(inv => inv.id === id);
    if (invoice) {
      selectInvoice(invoice);
      setOpen(false);
      setSearchQuery('');
    }
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    setSelectedInvoice(null);
    setFormData(prev => ({ ...prev, invoice_id: 0, customer_id: 0 }));
  };

  const getDisplayValue = () => {
    if (!selectedInvoice) return 'Select an invoice...';
    const customerName = selectedInvoice.customer?.name ?? 'Unknown';
    const total = Number(selectedInvoice.total) || 0;
    return `${selectedInvoice.invoice_number} — ${customerName} (${formatCurrency(total, selectedInvoice.currency_code || undefined)})`;
  };

  const filteredInvoices = searchQuery
    ? invoices.filter(inv =>
        inv.invoice_number.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (inv.customer?.name ?? '').toLowerCase().includes(searchQuery.toLowerCase())
      )
    : invoices;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!formData.invoice_id) {
      toast({ variant: 'destructive', title: 'Invoice required', description: 'Please select an invoice for this credit note.' });
      return;
    }

    if (!formData.customer_id) {
      toast({ variant: 'destructive', title: 'Customer required', description: 'Please select a customer for this credit note.' });
      return;
    }

    if (credited === null) {
      toast({ variant: 'destructive', title: 'Still loading', description: 'Earlier credit notes for this invoice are still loading.' });
      return;
    }

    // #527: the full refund modes are built and posted server-side from the
    // invoice (remaining quantities), in one step with the new draft.
    if (isFullRefund) {
      if (!rows.some(row => (Number(row.quantity) || 0) > 0)) {
        toast({ variant: 'destructive', title: 'Nothing to credit', description: 'Every line of this invoice has already been credited in full.' });
        return;
      }
      setIsReversing(true);
      try {
        const res = await api.post(`/accounting/customers/invoices/${formData.invoice_id}/credit-note-reversal`, {
          mode: refundMethod,
          credit_note_date: formData.credit_note_date,
          reason: formData.reason || null,
          notes: formData.notes || null,
        });
        const reissuedId = Number(res.data?.invoice?.id) || null;
        toast({
          title: 'Credit note posted',
          description: reissuedId
            ? `The invoice is fully credited and was re-issued as draft ${res.data?.invoice?.invoice_number ?? ''}.`.trim()
            : 'The invoice is fully credited.',
        });
        onSuccess();
        if (reissuedId && onReissued) onReissued(reissuedId);
      } catch (error: any) {
        const msg = error.response?.data?.error || error.response?.data?.message || error.message || 'Failed to credit the invoice';
        toast({ variant: 'destructive', title: 'Failed to credit the invoice', description: msg });
      } finally {
        setIsReversing(false);
      }
      return;
    }

    if (rows.some(exceedsCreditableQuantity)) {
      toast({ variant: 'destructive', title: 'Quantity too high', description: 'A line credits more than its remaining creditable quantity.' });
      return;
    }

    // #522: only lines with a quantity to credit are included.
    const sourceLines = new Map((selectedInvoice?.lines ?? []).map(line => [line.id, line]));
    const lines = rows
      .filter(row => row.credit && (Number(row.quantity) || 0) > 0)
      .map(row => {
        const source = sourceLines.get(row.credit!.invoice_line_id);
        return {
          invoice_line_id: row.credit!.invoice_line_id,
          product_id: source?.product_id ?? null,
          description: row.description,
          quantity: Number(row.quantity) || 0,
          unit_price: Number(row.unit_price) || 0,
          tax_rate: Number(row.tax_rate) || 0,
          tax_inclusive: !!row.tax_inclusive,
          discount_amount: Number(row.discount_amount) || 0,
          account_id: source?.account_id ?? null,
        };
      });

    if (lines.length === 0) {
      toast({
        variant: 'destructive',
        title: 'Nothing to credit',
        description: mode === 'partial'
          ? 'Enter a quantity to credit on at least one line.'
          : 'Every line of this invoice has already been credited in full.',
      });
      return;
    }

    try {
      const payload: CreateCreditNotePayload = { ...formData, lines };
      await createMutation.mutateAsync(payload);
      toast({ title: 'Credit note created', description: 'The credit note has been created successfully.' });
      onSuccess();
    } catch (error: any) {
      const msg = error.response?.data?.error || error.response?.data?.message || error.message || 'Failed to create credit note';
      toast({ variant: 'destructive', title: 'Failed to create credit note', description: msg });
    }
  };

  // #526: the shared line calculation (#512: discount first, tax extracted
  // from tax-inclusive prices). Document-level discount is applied
  // server-side when the credit note is created.
  const creditTotal = rows.reduce((sum, line) => sum + getSalesDocumentLineTotal(line), 0);
  const money = (value: number) => formatCurrency(value, selectedInvoice?.currency_code || undefined);

  const isPreselected = !!invoiceId && !!selectedInvoice;

  return (
    <Dialog open onOpenChange={(next) => { if (!next) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>
            {isPreselected ? `Credit Note for ${selectedInvoice.invoice_number}` : 'Create Customer Credit Note'}
          </DialogTitle>
          <DialogDescription>
            {isPreselected
              ? `Customer: ${selectedInvoice.customer?.name ?? 'Unknown'} · Total: ${formatCurrency(Number(selectedInvoice.total) || 0, selectedInvoice.currency_code || undefined)}`
              : 'Create a credit note for a customer invoice'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-5">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {!isPreselected && (
              <>
                <div>
                  <Label className="mb-1 block">
                    Select Invoice *
                  </Label>
                  <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                      <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        disabled={isLoading}
                        className={cn(
                          'w-full justify-between font-normal',
                          !selectedInvoice && 'text-muted-foreground'
                        )}
                      >
                        <span className="truncate">{getDisplayValue()}</span>
                        <div className="flex items-center gap-1 ml-2 shrink-0">
                          {selectedInvoice && (
                            <Icon
                              icon="solar:close-circle-bold"
                              className="h-4 w-4 opacity-50 hover:opacity-100 cursor-pointer"
                              onClick={handleClear}
                            />
                          )}
                          <Icon
                            icon="solar:alt-arrow-down-linear"
                            className={cn('h-4 w-4 transition-transform', open && 'rotate-180')}
                          />
                        </div>
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-[400px] p-0" align="start">
                      <Command shouldFilter={false}>
                        <CommandInput
                          placeholder="Search by invoice number or customer..."
                          value={searchQuery}
                          onValueChange={setSearchQuery}
                        />
                        <CommandList>
                          {isLoading ? (
                            <div className="py-6 text-center text-sm text-muted-foreground">
                              <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin inline mr-2" />
                              Loading invoices...
                            </div>
                          ) : filteredInvoices.length === 0 ? (
                            <CommandEmpty>
                              {searchQuery
                                ? 'No matching invoices found.'
                                : 'No posted invoices available.'}
                            </CommandEmpty>
                          ) : (
                            <CommandGroup heading={`Invoices (${filteredInvoices.length})`}>
                              {filteredInvoices.map((invoice) => (
                                <CommandItem
                                  key={invoice.id}
                                  value={String(invoice.id)}
                                  onSelect={handleSelect}
                                  className="flex items-center justify-between py-3"
                                >
                                  <div className="flex flex-col">
                                    <span className="font-medium">{invoice.invoice_number}</span>
                                    <span className="text-xs text-muted-foreground">
                                      {invoice.customer?.name ?? 'Unknown'} · {formatCurrency(Number(invoice.total) || 0, invoice.currency_code || undefined)}
                                    </span>
                                  </div>
                                  <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground capitalize">{invoice.status}</span>
                                    {selectedInvoice?.id === invoice.id && (
                                      <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />
                                    )}
                                  </div>
                                </CommandItem>
                              ))}
                            </CommandGroup>
                          )}
                        </CommandList>
                      </Command>
                    </PopoverContent>
                  </Popover>
                </div>
              </>
            )}

            {isPreselected && (
              <div>
                <Label className="mb-1 block">Invoice</Label>
                <div className="rounded-lg border bg-muted/50 px-3 py-2 text-sm">
                  {selectedInvoice.invoice_number} — {selectedInvoice.customer?.name ?? 'Unknown'}
                </div>
              </div>
            )}

            <div>
              <Label className="mb-1 block">
                Customer *
              </Label>
              <CustomerSearchCombobox
                value={formData.customer_id ? String(formData.customer_id) : ''}
                onValueChange={(value) => setFormData(prev => ({ ...prev, customer_id: value ? Number(value) : 0 }))}
                placeholder="Search customer name or code..."
                disabled={!!selectedInvoice}
              />
            </div>

            <div>
              <Label className="mb-1 block">
                Credit Note Date *
              </Label>
              <Input
                type="date"
                value={formData.credit_note_date}
                onChange={(e) => setFormData(prev => ({ ...prev, credit_note_date: e.target.value }))}
                required
              />
            </div>

            <div className="md:col-span-2">
              <Label className="mb-1 block">
                Reason
              </Label>
              <Input
                type="text"
                value={formData.reason}
                onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
                placeholder="e.g., Product return, Billing error"
              />
            </div>

            <div className="md:col-span-2">
              <Label className="mb-1 block">
                Notes
              </Label>
              <Textarea
                value={formData.notes}
                onChange={(e) => setFormData(prev => ({ ...prev, notes: e.target.value }))}
                rows={3}
              />
            </div>
          </div>

          {selectedInvoice && (
            <fieldset className="space-y-2">
              <legend className="mb-1 text-sm font-medium">Refund method</legend>
              {REFUND_METHODS.map((option) => (
                <label key={option.value} className="flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm">
                  <input
                    type="radio"
                    name="refund-method"
                    value={option.value}
                    checked={refundMethod === option.value}
                    onChange={() => setRefundMethod(option.value)}
                    className="mt-1"
                  />
                  <span>
                    <span className="block font-medium">{option.label}</span>
                    <span className="block text-xs text-muted-foreground">{option.help}</span>
                  </span>
                </label>
              ))}
            </fieldset>
          )}

          {selectedInvoice && (
            <div className="mb-6">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-lg font-semibold">Line Items</h3>
                {/* #522 (CN-UX1): full vs partial credit (draft credit note only). */}
                {!isFullRefund && (
                <div role="group" aria-label="Credit type" className="inline-flex rounded-lg border p-0.5">
                  {(['full', 'partial'] as const).map((m) => (
                    <Button
                      key={m}
                      type="button"
                      size="sm"
                      variant={mode === m ? 'default' : 'ghost'}
                      aria-pressed={mode === m}
                      onClick={() => setMode(m)}
                    >
                      {m === 'full' ? 'Full credit' : 'Partial credit'}
                    </Button>
                  ))}
                </div>
                )}
              </div>
              <p className="mb-2 text-xs text-muted-foreground">
                {lineMode === 'full'
                  ? 'Credits everything not yet credited on each line.'
                  : 'Enter the quantity to credit on each line; lines left at 0 are not included.'}
              </p>
              {creditsError && (
                <p role="alert" className="mb-2 text-xs text-destructive">{creditsError}</p>
              )}
              {credited === null ? (
                <div className="py-4 text-center text-sm text-muted-foreground">
                  <Icon icon="solar:refresh-bold" className="mr-2 inline h-4 w-4 animate-spin" />
                  Loading earlier credits...
                </div>
              ) : (
              <>
                <SalesDocumentLineGrid
                  lines={rows}
                  setLines={setRows}
                  money={money}
                  credit={{ readOnly: lineMode === 'full' }}
                  tableMinWidth="min-w-[720px]"
                />
                <div className="mt-2 flex justify-end gap-4 rounded-lg bg-muted/50 px-3 py-2">
                  <span className="font-semibold">Total:</span>
                  <span className="font-bold tabular-nums">{money(creditTotal)}</span>
                </div>
              </>
              )}
            </div>
          )}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={createMutation.isPending || isReversing || isLoading || (!!selectedInvoice && credited === null)}>
              {createMutation.isPending || isReversing
                ? (isFullRefund ? 'Posting...' : 'Creating...')
                : refundMethod === 'full_refund'
                  ? 'Post Credit Note'
                  : refundMethod === 'full_refund_new_draft'
                    ? 'Post & Create Draft Invoice'
                    : 'Create Credit Note'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
