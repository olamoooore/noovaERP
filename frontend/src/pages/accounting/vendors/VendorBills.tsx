import React, { useEffect, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DocumentUpload } from '@/components/ocr/DocumentUpload';
import { useToast } from '@/hooks/use-toast';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api, { accountingApi } from '@/lib/api';

export const VendorBills: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showUpload, setShowUpload] = useState(false);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
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
    queryKey: ['vendor-bills', statusFilter, searchQuery, vendorFilter, agingBucketFilter],
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
            search: searchQuery || undefined,
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
        if (searchQuery) ocrParams.append('search', searchQuery);

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
    const statusConfig: Record<string, { variant: any; label: string }> = {
      draft: { variant: 'secondary', label: 'Draft' },
      pending: { variant: 'secondary', label: 'Pending OCR' },
      extracted: { variant: 'default', label: 'Extracted' },
      review: { variant: 'default', label: 'Review' },
      posted: { variant: 'default', label: 'Posted' },
      paid: { variant: 'default', label: 'Paid' },
      failed: { variant: 'destructive', label: 'Failed' },
      cancelled: { variant: 'secondary', label: 'Cancelled' },
    };

    const config = statusConfig[status] || { variant: 'secondary', label: status };
    return <Badge variant={config.variant}>{config.label}</Badge>;
  };

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount || 0);
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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Vendor Bills</h1>
          <p className="text-muted-foreground mt-1">Manage bills from vendors/suppliers</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setShowUpload(true)}>
            <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
            Upload Bill (OCR)
          </Button>
          <Button onClick={() => navigate('/accounting/vendors/bills/create')}>
            <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
            New Bill
          </Button>
        </div>
      </div>

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
                size="sm"
                onClick={handleClearDrillDownFilters}
                className="text-blue-600 hover:text-blue-700 dark:text-blue-400"
              >
                <Icon icon="solar:close-circle-bold" className="h-4 w-4 mr-1" />
                Clear Filters
              </Button>
            </div>
          </CardContent>
        </Card>
      )}

      {showUpload && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Upload Vendor Bills</span>
              <Button variant="ghost" size="sm" onClick={() => setShowUpload(false)}>
                <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <DocumentUpload
              onComplete={handleUploadComplete}
              onCancel={() => setShowUpload(false)}
            />
          </CardContent>
        </Card>
      )}

      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="all">All Status</option>
              <option value="pending">Pending OCR</option>
              <option value="extracted">Extracted</option>
              <option value="review">Review</option>
              <option value="posted">Posted</option>
              <option value="paid">Paid</option>
            </select>
            <input
              type="text"
              placeholder="Search by vendor, bill number..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </CardContent>
      </Card>

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
                <Button variant="outline" onClick={() => setShowUpload(true)}>
                  <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
                  Upload Bill
                </Button>
                <Button onClick={() => navigate('/accounting/vendors/bills/create')}>
                  <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                  Create Bill
                </Button>
              </div>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Bill #</TableHead>
                    <TableHead>Vendor</TableHead>
                    <TableHead>Bill Date</TableHead>
                    <TableHead>Due Date</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>OCR Confidence</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {bills.map((bill: any) => (
                    <TableRow key={bill.uuid} className="hover:bg-accent/50 cursor-pointer">
                      <TableCell
                        className="font-medium"
                        onClick={() => {
                          if (bill.source === 'regular') {
                            navigate(`/accounting/vendors/bills/${bill.raw_id}`);
                          } else {
                            navigate(`/ocr/documents/${bill.raw_uuid}`);
                          }
                        }}
                      >
                        {bill.extracted_data?.invoice_number || bill.uuid.toString().slice(0, 8)}
                      </TableCell>
                      <TableCell>
                        {bill.extracted_data?.vendor_name || bill.vendor_name || '-'}
                      </TableCell>
                      <TableCell>
                        {formatDate(bill.extracted_data?.invoice_date || bill.document_date)}
                      </TableCell>
                      <TableCell>
                        {formatDate(bill.extracted_data?.due_date)}
                      </TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(
                          bill.extracted_data?.total_amount || 
                          bill.total_amount ||
                          bill.extracted_data?.total || 
                          bill.total ||
                          bill.extracted_data?.amount || 
                          bill.amount ||
                          0
                        )}
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
                      <TableCell className="text-right">
                        <div className="flex gap-1 justify-end">
                          {(bill.status === 'review' || (bill.source === 'regular' && bill.status === 'draft')) && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={(e) => {
                                e.stopPropagation();
                                postBillMutation.mutate(bill);
                              }}
                              disabled={postBillMutation.isPending}
                            >
                              <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 mr-1" />
                              Post
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={(e) => {
                              e.stopPropagation();
                              if (bill.source === 'regular') {
                                navigate(`/accounting/vendors/bills/${bill.raw_id}`);
                              } else {
                                navigate(`/ocr/documents/${bill.raw_uuid}`);
                              }
                            }}
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

      {bills && bills.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Bills</p>
                  <p className="text-2xl font-bold">{bills.length}</p>
                </div>
                <Icon icon="solar:bill-list-bold-duotone" className="h-8 w-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Pending Review</p>
                  <p className="text-2xl font-bold">
                    {bills.filter((bill: any) => bill.status === 'review' || bill.status === 'extracted').length}
                  </p>
                </div>
                <Icon icon="solar:document-bold-duotone" className="h-8 w-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Unpaid</p>
                  <p className="text-2xl font-bold">
                    {bills.filter((bill: any) => bill.status === 'posted').length}
                  </p>
                </div>
                <Icon icon="solar:clock-circle-bold-duotone" className="h-8 w-8 text-red-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Paid</p>
                  <p className="text-2xl font-bold">
                    {bills.filter((bill: any) => bill.status === 'paid').length}
                  </p>
                </div>
                <Icon icon="solar:check-circle-bold-duotone" className="h-8 w-8 text-green-500" />
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

export default VendorBills;
