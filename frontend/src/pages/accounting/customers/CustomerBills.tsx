import React, { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { DocumentUpload } from '@/components/ocr/DocumentUpload';
import { useToast } from '@/hooks/use-toast';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '@/lib/api';

/**
 * Customer Invoices Page
 * Manage all customer invoices with OCR integration
 */
export const CustomerBills: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showUpload, setShowUpload] = useState(false);
  const [statusFilter, setStatusFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  // Get drill-down parameters from URL
  const customerIdParam = searchParams.get('customer_id');
  const agingBucketParam = searchParams.get('aging_bucket');

  // State for drill-down filters
  const [customerFilter, setCustomerFilter] = useState<string | null>(customerIdParam);
  const [agingBucketFilter, setAgingBucketFilter] = useState<string | null>(agingBucketParam);

  // Update filters when URL params change
  useEffect(() => {
    setCustomerFilter(customerIdParam);
    setAgingBucketFilter(agingBucketParam);
  }, [customerIdParam, agingBucketParam]);

  // Fetch invoices
  const { data: invoices, isLoading, refetch } = useQuery({
    queryKey: ['customer-bills', statusFilter, searchQuery, customerFilter, agingBucketFilter],
    queryFn: async () => {
      if (customerFilter && agingBucketFilter) {
        const response = await api.get('/accounting/reports/aged-receivables/details', {
          params: {
            customer_id: customerFilter,
            aging_bucket: agingBucketFilter,
          },
        });

        return (response.data.data || []).map((invoice: any) => ({
          id: invoice.id,
          invoice_number: invoice.invoice_number,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          customer: {
            name: customerFilter,
          },
          total: invoice.total,
          paid_amount: invoice.paid_amount,
          balance: invoice.balance,
          status: invoice.status,
          is_aging_drilldown: true,
        }));
      }

      const params = new URLSearchParams();
      if (statusFilter !== 'all') params.append('status', statusFilter);
      if (searchQuery) params.append('search', searchQuery);
      if (customerFilter) params.append('customer_id', customerFilter);
      
      const response = await api.get(`/invoices?${params.toString()}`);
      return response.data.data || [];
    },
  });

  // Send invoice mutation
  const postInvoiceMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/invoices/${id}/send`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-bills'] });
      toast({ title: 'Invoice sent successfully' });
    },
    onError: (error: any) => {
      toast({ 
        variant: 'destructive', 
        title: 'Failed to post invoice',
        description: error.response?.data?.message || 'An error occurred'
      });
    },
  });

  const handleUploadComplete = () => {
    setShowUpload(false);
    refetch();
    toast({
      title: 'Upload complete',
      description: 'Documents uploaded and OCR processing started',
    });
  };

  const handleClearDrillDownFilters = () => {
    setCustomerFilter(null);
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
      posted: { variant: 'default', label: 'Posted' },
      paid: { variant: 'default', label: 'Paid' },
      partial: { variant: 'default', label: 'Partially Paid' },
      overdue: { variant: 'destructive', label: 'Overdue' },
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
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customer Invoices</h1>
          <p className="text-muted-foreground mt-1">
            Manage customer invoices
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => setShowUpload(true)}>
            <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
            Upload Invoice (OCR)
          </Button>
          <Button onClick={() => navigate('/accounting/customers/bills/create')}>
            <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
            New Invoice
          </Button>
        </div>
      </div>

      {/* Drill-down Filter Indicator */}
      {(customerFilter || agingBucketFilter) && (
        <Card className="border-blue-200 bg-blue-50 dark:bg-blue-950 dark:border-blue-800">
          <CardContent className="pt-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Icon icon="solar:filter-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                <span className="font-medium text-blue-900 dark:text-blue-100">
                  Filtered from Aged Receivables Report
                </span>
                <div className="flex gap-2 ml-4">
                  {customerFilter && (
                    <Badge variant="secondary" className="bg-blue-100 dark:bg-blue-900">
                      Customer ID: {customerFilter}
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

      {/* OCR Upload Section */}
      {showUpload && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center justify-between">
              <span>Upload Customer Invoices</span>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setShowUpload(false)}
              >
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

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="all">All Status</option>
              <option value="draft">Draft</option>
              <option value="posted">Posted</option>
              <option value="paid">Paid</option>
              <option value="partial">Partially Paid</option>
              <option value="overdue">Overdue</option>
            </select>
            <input
              type="text"
              placeholder="Search by customer, invoice number..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <Button variant="outline">
              <Icon icon="solar:filter-bold-duotone" className="h-4 w-4 mr-2" />
              More Filters
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Bills List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Customer Invoices</span>
            {invoices && invoices.length > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {invoices.length} invoice(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading invoices...</p>
            </div>
          ) : !invoices || invoices.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:document-text-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No customer invoices yet</p>
              <p className="text-sm mb-4">Upload an invoice via OCR or create one manually</p>
              <div className="flex gap-2 justify-center">
                <Button variant="outline" onClick={() => setShowUpload(true)}>
                  <Icon icon="solar:upload-bold-duotone" className="h-4 w-4 mr-2" />
                  Upload Invoice
                </Button>
                <Button onClick={() => navigate('/accounting/customers/bills/create')}>
                  <Icon icon="solar:add-circle-bold-duotone" className="h-4 w-4 mr-2" />
                  Create Invoice
                </Button>
              </div>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Invoice #</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Due Date</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Source</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoices.map((invoice: any) => (
                    <TableRow key={invoice.id} className="hover:bg-accent/50 cursor-pointer">
                      <TableCell 
                        className="font-medium cursor-pointer hover:text-primary"
                        onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                      >
                        {invoice.invoice_number || `INV-${invoice.id}`}
                      </TableCell>
                      <TableCell>{invoice.customer?.name || invoice.customer_name || '-'}</TableCell>
                      <TableCell>{formatDate(invoice.invoice_date)}</TableCell>
                      <TableCell>{formatDate(invoice.due_date)}</TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(invoice.total || invoice.total_amount)}
                      </TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(invoice.balance || invoice.total)}
                      </TableCell>
                      <TableCell>{getStatusBadge(invoice.status)}</TableCell>
                      <TableCell>
                        {invoice.ocr_document_id ? (
                          <Badge variant="outline" className="gap-1">
                            <Icon icon="solar:document-add-bold-duotone" className="h-3 w-3" />
                            OCR
                          </Badge>
                        ) : (
                          <Badge variant="outline" className="gap-1">
                            <Icon icon="solar:pen-bold-duotone" className="h-3 w-3" />
                            Manual
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex gap-1 justify-end">
                          {invoice.status === 'draft' && (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={(e) => {
                                e.stopPropagation();
                                postInvoiceMutation.mutate(invoice.id);
                              }}
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
                              navigate(`/accounting/customers/bills/${invoice.id}`);
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

      {/* Quick Stats */}
      {invoices && invoices.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Invoices</p>
                  <p className="text-2xl font-bold">{invoices.length}</p>
                </div>
                <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Draft</p>
                  <p className="text-2xl font-bold">
                    {invoices.filter((inv: any) => inv.status === 'draft').length}
                  </p>
                </div>
                <Icon icon="solar:document-bold-duotone" className="h-8 w-8 text-gray-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Unpaid</p>
                  <p className="text-2xl font-bold">
                    {invoices.filter((inv: any) => inv.status === 'posted' || inv.status === 'overdue').length}
                  </p>
                </div>
                <Icon icon="solar:clock-circle-bold-duotone" className="h-8 w-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Paid</p>
                  <p className="text-2xl font-bold">
                    {invoices.filter((inv: any) => inv.status === 'paid').length}
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

export default CustomerBills;
