import React, { useEffect, useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DocumentUpload } from '@/components/ocr/DocumentUpload';
import { useToast } from '@/hooks/use-toast';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api, { accountingApi } from '@/lib/api';
import { SortableHead } from '@/components/ui/SortableHead';
import { useSort } from '@/hooks/useSort';
import { cn, formatCurrency } from '@/lib/utils';

const billAmount = (bill: any): number =>
  Number(
    bill.extracted_data?.total_amount ??
      bill.total_amount ??
      bill.extracted_data?.total ??
      bill.total ??
      bill.extracted_data?.amount ??
      bill.amount ??
      0,
  );

export const VendorBills: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showUpload, setShowUpload] = useState(false);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  // Wide search hits the DB per term; debounce typing like the other lists do.
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearchQuery(searchQuery), 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);
  const vendorIdParam = searchParams.get('vendor_id');
  const agingBucketParam = searchParams.get('aging_bucket');
  const [vendorFilter, setVendorFilter] = useState<string | null>(vendorIdParam);
  const [agingBucketFilter, setAgingBucketFilter] = useState<string | null>(agingBucketParam);
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  useEffect(() => {
    setVendorFilter(vendorIdParam);
    setAgingBucketFilter(agingBucketParam);
  }, [vendorIdParam, agingBucketParam]);

  // Fetch vendor bills from both OCR documents and regular bills API
  const { data: bills, isLoading, refetch } = useQuery({
    queryKey: ['vendor-bills', statusFilter, debouncedSearchQuery, vendorFilter, agingBucketFilter],
    queryFn: async () => {
      if (vendorFilter && agingBucketFilter) {
        const response = await api.get('/accounting/reports/aged-payables/details', {
          params: {
            vendor_id: vendorFilter,
            aging_bucket: agingBucketFilter,
          },
        });

        return (response.data.data || []).map((bill: any) => ({
          uuid: bill.id,
          source: 'aging-drilldown',
          status: bill.status,
          document_date: bill.bill_date,
          due_date: bill.due_date,
          vendor_name: vendorFilter,
          total_amount: bill.total,
          balance: bill.balance,
          extracted_data: {
            invoice_number: bill.bill_number,
            vendor_name: vendorFilter,
            invoice_date: bill.bill_date,
            due_date: bill.due_date,
            total_amount: bill.total,
            total: bill.total,
            amount: bill.total,
          },
          confidence_score: 1,
        }));
      }

      const fetchRegularBills = async () => {
        const billsResponse = await accountingApi.get('/accounting/vendor-bills', {
          params: {
            per_page: 1000,
            vendor_id: vendorFilter || undefined,
            status: statusFilter !== 'all' ? statusFilter : undefined,
            search: debouncedSearchQuery || undefined,
          },
        });

        const billsData = billsResponse.data.data || [];

        return billsData.map((bill: any) => ({
          uuid: `bill-${bill.id}`,
          source: 'regular',
          raw_id: bill.id,
          status: bill.status || 'posted',
          document_date: bill.bill_date,
          due_date: bill.due_date,
          vendor_name: bill.vendor?.name || 'Unknown Vendor',
          total_amount: bill.total,
          balance: bill.balance,
          // Wide-search hints set by the list endpoint.
          matched_contacts: bill.matched_contacts,
          matched_tags: bill.matched_tags,
          matched_lines: bill.matched_lines,
          matched_addresses: bill.matched_addresses,
          extracted_data: {
            invoice_number: bill.bill_number,
            vendor_name: bill.vendor?.name || 'Unknown Vendor',
            invoice_date: bill.bill_date,
            due_date: bill.due_date,
            total_amount: bill.total,
            total: bill.total,
            amount: bill.total,
          },
          confidence_score: 1.0,
        }));
      };

      const fetchOcrBills = async () => {
        const ocrParams = new URLSearchParams();
        ocrParams.append('document_type', 'vendor_bill');
        if (statusFilter !== 'all') ocrParams.append('status', statusFilter);
        if (debouncedSearchQuery) ocrParams.append('search', debouncedSearchQuery);

        const ocrResponse = await api.get(`/ocr/documents?${ocrParams.toString()}`);
        const ocrBills = ocrResponse.data.data || [];

        return ocrBills.map((bill: any) => ({
          ...bill,
          uuid: `ocr-${bill.uuid}`,
          raw_uuid: bill.uuid,
          source: 'ocr',
          extracted_data: typeof bill.extracted_data === 'string'
            ? JSON.parse(bill.extracted_data)
            : bill.extracted_data,
        }));
      };

      const regularBills = await fetchRegularBills();
      let ocrBills = [];
      try {
        ocrBills = await fetchOcrBills();
      } catch (error) {
        console.warn('Failed to fetch OCR vendor bills. Falling back to canonical vendor bills only.', error);
      }

      const merged = [...ocrBills, ...regularBills];
      merged.sort((a, b) => {
        const dateA = a.created_at || a.document_date || '';
        const dateB = b.created_at || b.document_date || '';
        return dateB.localeCompare(dateA);
      });
      return merged;
    },
  });

  const navigateBill = (bill: any) => {
    if (bill.source === 'regular') {
      navigate(`/accounting/vendors/bills/${bill.raw_id}`);
    } else {
      navigate(`/ocr/documents/${bill.raw_uuid}`);
    }
  };

  // Sortable rows: pre-compute the values shown in the table (the API payloads
  // carry them under different aliases) so every column sorts correctly.
  const sortableBills = useMemo(
    () =>
      (bills ?? []).map((bill: any) => ({
        ...bill,
        sort_amount: billAmount(bill),
        sort_confidence: Number(bill.confidence_score ?? 0),
        sort_bill_date: bill.extracted_data?.invoice_date || bill.document_date || '',
        sort_due_date: bill.extracted_data?.due_date || bill.due_date || '',
      })),
    [bills],
  );
  const { sorted: sortedBills, sortKey, sortDir, toggle: toggleSort } = useSort(
    sortableBills,
    'sort_bill_date',
    'desc',
  );

  const postBillMutation = useMutation({
    mutationFn: async (bill: any) => {
      if (bill.source === 'ocr') {
        // OCR document posting path
        const response = await api.post(`/ocr/documents/${bill.raw_uuid}/post`);
        return response.data;
      } else {
        // Regular vendor bill posting path
        const response = await accountingApi.post(`/accounting/vendor-bills/${bill.raw_id}/post`);
        return response.data;
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor-bills'] });
      toast({ title: 'Vendor bill posted successfully' });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to post bill',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleUploadComplete = () => {
    setShowUpload(false);
    refetch();
    toast({
      title: 'Upload complete',
      description: 'Vendor bills uploaded and OCR extraction completed',
    });
  };

  const handleClearDrillDownFilters = () => {
    setVendorFilter(null);
    setAgingBucketFilter(null);
    setSearchParams({});
  };

  const getAgingBucketLabel = (bucket: string) => {
    const labels: Record<string, string> = {
      current: 'Current',
      days_1_30: '1-30 Days',
      days_31_60: '31-60 Days',
      days_61_90: '61-90 Days',
      days_90_plus: '90+ Days',
    };

    return labels[bucket] || bucket;
  };

  const getStatusBadge = (status: string) => {
    const statusConfig: Record<string, { label: string; icon: string; className: string }> = {
      draft: { label: 'Draft', icon: 'solar:document-bold-duotone', className: 'bg-muted text-muted-foreground' },
      pending: { label: 'Pending OCR', icon: 'solar:clock-circle-bold-duotone', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
      needs_review: { label: 'Needs Review', icon: 'solar:danger-triangle-bold-duotone', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
      review: { label: 'Needs Review', icon: 'solar:danger-triangle-bold-duotone', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
      extracted: { label: 'Extracted', icon: 'solar:document-text-bold-duotone', className: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300' },
      posted: { label: 'Posted', icon: 'solar:check-circle-bold-duotone', className: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300' },
      paid: { label: 'Paid', icon: 'solar:wallet-money-bold-duotone', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' },
      failed: { label: 'Failed', icon: 'solar:close-circle-bold-duotone', className: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' },
      cancelled: { label: 'Cancelled', icon: 'solar:close-circle-bold-duotone', className: 'bg-muted text-muted-foreground' },
      rejected: { label: 'Rejected', icon: 'solar:close-circle-bold-duotone', className: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' },
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
  };

  const formatDate = (date: string) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Vendor Bills</h1>
          <p className="text-muted-foreground mt-1">Manage bills from vendors/suppliers</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-full sm:w-64">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              placeholder="Search bill, vendor, address, line…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>

          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-40 rounded-full text-xs" aria-label="Filter by status">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="pending">Pending OCR</SelectItem>
              <SelectItem value="extracted">Extracted</SelectItem>
              <SelectItem value="review">Review</SelectItem>
              <SelectItem value="posted">Posted</SelectItem>
              <SelectItem value="paid">Paid</SelectItem>
            </SelectContent>
          </Select>

          <HeaderActionButton icon="solar:upload-bold-duotone" variant="outline" onClick={() => setShowUpload(true)}>
            Upload Bill (OCR)
          </HeaderActionButton>
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => navigate('/accounting/vendors/bills/create')}>
            New Bill
          </HeaderActionButton>
        </div>
      </div>

      {bills && bills.length > 0 && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="Total Bills" value={bills.length} icon="solar:bill-list-bold-duotone" tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400" />
          <StatCard label="Pending Review" value={bills.filter((bill: any) => ['review', 'extracted', 'needs_review'].includes(String(bill.status).toLowerCase())).length} icon="solar:document-bold-duotone" tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400" />
          <StatCard label="Unpaid" value={bills.filter((bill: any) => bill.status === 'posted').length} icon="solar:clock-circle-bold-duotone" tone="bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400" />
          <StatCard label="Paid" value={bills.filter((bill: any) => bill.status === 'paid').length} icon="solar:check-circle-bold-duotone" tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400" />
        </div>
      )}

      {(vendorFilter || agingBucketFilter) && (
        <Card className="border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-800">
          <CardContent className="pt-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Icon icon="solar:filter-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                <span className="font-medium text-blue-900 dark:text-blue-100">
                  Filtered from Aged Payables Report
                </span>
                <div className="flex gap-2 ml-4">
                  {vendorFilter && (
                    <Badge variant="secondary" className="bg-blue-100 dark:bg-blue-900">
                      Vendor ID: {vendorFilter}
                    </Badge>
                  )}
                  {agingBucketFilter && (
                    <Badge variant="secondary" className="bg-blue-100 dark:bg-blue-900">
                      Aging: {getAgingBucketLabel(agingBucketFilter)}
                    </Badge>
                  )}
                </div>
              </div>
              <Button
                variant="ghost"
                size="icon"
                onClick={handleClearDrillDownFilters}
                className="h-8 w-8 text-blue-600 hover:text-blue-700 dark:text-blue-400"
                title="Clear filters"
                aria-label="Clear filters"
              >
                <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {showUpload && (
        <Card>
          <CardHeader>
            {/* Cancel lives in the DocumentUpload footer — no duplicate close here. */}
            <CardTitle>Upload Vendor Bills</CardTitle>
          </CardHeader>
          <CardContent>
            <DocumentUpload
              onComplete={handleUploadComplete}
              onCancel={() => setShowUpload(false)}
            />
          </CardContent>
        </Card>
      )}

      {/* ACC-UI-RM-003: OCR is a source-capture/AI digitalization workflow,
          NOT a second Bill authority. The vendor bill list below is the
          canonical record. */}
      <div className="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 rounded-lg p-3 flex items-start gap-2 text-sm">
        <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5 shrink-0" />
        <div>
          <p className="font-medium text-blue-900 dark:text-blue-200">OCR documents are not vendor bills</p>
          <p className="text-blue-800 dark:text-blue-300 text-xs mt-1">
            Uploaded files are extracted and reviewed, then promoted to the canonical vendor-bill
            record you see below. Once promoted, only the bill here is the authoritative accounting
            document — not the uploaded file, not the OCR preview.
          </p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Vendor Bills</span>
            {bills && bills.length > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {bills.length} bill(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading bills...</p>
            </div>
          ) : !bills || bills.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:bill-list-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No vendor bills yet</p>
              <p className="text-sm mb-4">Upload a bill via OCR or create one manually</p>
              <div className="flex gap-2 justify-center">
                <HeaderActionButton icon="solar:upload-bold-duotone" variant="outline" onClick={() => setShowUpload(true)}>
                  Upload Bill
                </HeaderActionButton>
                <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => navigate('/accounting/vendors/bills/create')}>
                  Create Bill
                </HeaderActionButton>
              </div>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Bill #" sortKey="extracted_data.invoice_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Vendor" sortKey="extracted_data.vendor_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Bill Date" sortKey="sort_bill_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Due Date" sortKey="sort_due_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Amount" sortKey="sort_amount" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="OCR Confidence" sortKey="sort_confidence" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead className="w-[7.5rem] text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedBills.map((bill: any) => (
                    <TableRow
                      key={bill.uuid}
                      className="cursor-pointer hover:bg-accent/50"
                      onClick={() => navigateBill(bill)}
                    >
                      <TableCell className="font-medium text-primary">
                        {bill.extracted_data?.invoice_number || bill.uuid.toString().slice(0, 8)}
                      </TableCell>
                      <TableCell className="font-medium text-primary hover:underline">
                        <div>{bill.extracted_data?.vendor_name || bill.vendor_name || '-'}</div>
                        {/* Wide search can match a vendor address/line/tag — show which. */}
                        {(bill.matched_contacts?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-primary" title={bill.matched_contacts.join(', ')}>
                            Contact: {bill.matched_contacts.join(', ')}
                          </div>
                        )}
                        {(bill.matched_tags?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-amber-600" title={bill.matched_tags.join(', ')}>
                            Tag: {bill.matched_tags.join(', ')}
                          </div>
                        )}
                        {(bill.matched_lines?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-muted-foreground" title={bill.matched_lines.join(' · ')}>
                            Line: {bill.matched_lines.join(' · ')}
                          </div>
                        )}
                        {(bill.matched_addresses?.length ?? 0) > 0 && (
                          <div className="truncate text-xs text-muted-foreground" title={bill.matched_addresses.join(' · ')}>
                            Address: {bill.matched_addresses.join(' · ')}
                          </div>
                        )}
                      </TableCell>
                      <TableCell>{formatDate(bill.sort_bill_date)}</TableCell>
                      <TableCell>{formatDate(bill.sort_due_date)}</TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatCurrency(billAmount(bill))}
                      </TableCell>
                      <TableCell>{getStatusBadge(bill.status)}</TableCell>
                      <TableCell>
                        {bill.confidence_score ? (
                          <div className="flex items-center gap-2">
                            <div className="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                              <div
                                className={`h-full ${
                                  bill.confidence_score >= 0.8
                                    ? 'bg-green-500'
                                    : bill.confidence_score >= 0.6
                                    ? 'bg-yellow-500'
                                    : 'bg-red-500'
                                }`}
                                style={{ width: `${bill.confidence_score * 100}%` }}
                              />
                            </div>
                            <span className="text-xs text-muted-foreground">
                              {Math.round(bill.confidence_score * 100)}%
                            </span>
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">-</span>
                        )}
                      </TableCell>
                      <TableCell className="w-[7.5rem] text-right" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-end gap-1">
                          {(['review', 'needs_review'].includes(String(bill.status).toLowerCase())
                            || (bill.source === 'regular' && bill.status === 'draft')) && (
                            <Button
                              size="sm"
                              className="h-7 rounded-full bg-emerald-600 px-2.5 text-xs text-white hover:bg-emerald-700"
                              onClick={() => postBillMutation.mutate(bill)}
                              disabled={postBillMutation.isPending}
                            >
                              <Icon icon="solar:check-circle-bold-duotone" className="mr-1 h-3.5 w-3.5" />
                              Post
                            </Button>
                          )}
                          <Button
                            size="icon"
                            variant="ghost"
                            className="h-7 w-7"
                            onClick={() => navigateBill(bill)}
                            aria-label="View bill"
                            title="View"
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
        </CardContent>
      </Card>

    </div>
  );
};

export default VendorBills;
