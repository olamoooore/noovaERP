import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';

/** Issue #328: overdue = everything past the current bucket. */
const overdueAmount = (customer: any): number =>
  (parseFloat(customer.days_1_30) || 0) +
  (parseFloat(customer.days_31_60) || 0) +
  (parseFloat(customer.days_61_90) || 0) +
  (parseFloat(customer.days_90_plus) || 0);

export const CustomerFollowupReports: React.FC = () => {
  const [agingPeriod, setAgingPeriod] = useState('all');
  const navigate = useNavigate();
  const { toast } = useToast();

  // Issue #328: manual follow-up — one reminder per overdue invoice of the
  // customer, on the customer's preferred channel (skips are reported).
  const reminderMutation = useMutation({
    mutationFn: async (customerId: number) => {
      const response = await api.post(`/customers/${customerId}/followup-reminders`);
      return response.data;
    },
    onSuccess: (result: any) => {
      const skipped = (result?.data?.results ?? []).filter((r: any) => r.outcome !== 'sent');
      toast({
        title: result?.message ?? 'Reminders sent',
        description: skipped.length > 0
          ? skipped.map((r: any) => `${r.invoice_number}: ${r.reason ?? r.outcome}`).join('; ')
          : undefined,
        variant: (result?.data?.sent ?? 0) > 0 ? 'default' : 'destructive',
      });
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Reminder not sent',
        description: error.response?.data?.message || 'Please try again.',
      });
    },
  });

  // Fetch aged receivables data
  const { data: agedReceivables, isLoading } = useQuery({
    queryKey: ['aged-receivables', agingPeriod],
    queryFn: async () => {
      const response = await api.get('/accounting/reports/aged-receivables');
      // API returns { data: { data: [...], summary: {...} } }
      return response.data.data?.data || [];
    },
  });

  const getAgingBadge = (days: number) => {
    if (days <= 30) return <Badge variant="default">Current</Badge>;
    if (days <= 60) return <Badge variant="default">1-30 Days</Badge>;
    if (days <= 90) return <Badge variant="default">31-60 Days</Badge>;
    if (days <= 120) return <Badge variant="default">61-90 Days</Badge>;
    return <Badge variant="destructive">90+ Days</Badge>;
  };

  const calculateTotals = () => {
    // Ensure agedReceivables is an array before using reduce
    if (!agedReceivables || !Array.isArray(agedReceivables) || agedReceivables.length === 0) {
      return { current: 0, days30: 0, days60: 0, days90: 0, days90plus: 0, total: 0 };
    }

    return agedReceivables.reduce((acc: any, customer: any) => {
      return {
        current: acc.current + (parseFloat(customer.current) || 0),
        days30: acc.days30 + (parseFloat(customer.days_1_30) || 0),
        days60: acc.days60 + (parseFloat(customer.days_31_60) || 0),
        days90: acc.days90 + (parseFloat(customer.days_61_90) || 0),
        days90plus: acc.days90plus + (parseFloat(customer.days_90_plus) || 0),
        total: acc.total + (parseFloat(customer.total) || 0),
      };
    }, { current: 0, days30: 0, days60: 0, days90: 0, days90plus: 0, total: 0 });
  };

  const totals = calculateTotals();

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Customer Follow-up Reports</h1>
          <p className="text-muted-foreground mt-1">
            Monitor overdue invoices and customer payment behavior
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={agingPeriod}
            onChange={(e) => setAgingPeriod(e.target.value)}
            className="h-8 rounded-full border border-input bg-background px-3 text-xs focus:outline-none focus:ring-2 focus:ring-ring"
          >
            <option value="all">All Periods</option>
            <option value="current">Current Only</option>
            <option value="overdue">Overdue Only</option>
            <option value="30">1-30 Days</option>
            <option value="60">31-60 Days</option>
            <option value="90">61-90 Days</option>
            <option value="90plus">90+ Days</option>
          </select>
          <HeaderActionButton icon="solar:refresh-bold-duotone" variant="outline">
            Refresh
          </HeaderActionButton>
          <HeaderActionButton icon="solar:printer-bold-duotone" variant="outline">
            Print Report
          </HeaderActionButton>
          <HeaderActionButton icon="solar:export-bold-duotone" variant="outline">
            Export
          </HeaderActionButton>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-6 gap-4">
        <Card>
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">Current</p>
              <p className="text-lg font-bold">{formatCurrency(totals.current)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">1-30 Days</p>
              <p className="text-lg font-bold text-blue-600">{formatCurrency(totals.days30)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">31-60 Days</p>
              <p className="text-lg font-bold text-yellow-600">{formatCurrency(totals.days60)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">61-90 Days</p>
              <p className="text-lg font-bold text-orange-600">{formatCurrency(totals.days90)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">90+ Days</p>
              <p className="text-lg font-bold text-red-600">{formatCurrency(totals.days90plus)}</p>
            </div>
          </CardContent>
        </Card>
        <Card className="bg-primary/5">
          <CardContent className="pt-6">
            <div className="text-center">
              <p className="text-xs text-muted-foreground mb-1">Total Outstanding</p>
              <p className="text-lg font-bold text-primary">{formatCurrency(totals.total)}</p>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Aged Receivables Table */}
      <Card>
        <CardHeader>
          <CardTitle>Aged Receivables by Customer</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading report...</p>
            </div>
          ) : !agedReceivables || !Array.isArray(agedReceivables) || agedReceivables.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:chart-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No outstanding receivables</p>
              <p className="text-sm">All customer invoices are paid</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Customer</TableHead>
                    <TableHead>Contact</TableHead>
                    <TableHead className="text-right">Current</TableHead>
                    <TableHead className="text-right">1-30 Days</TableHead>
                    <TableHead className="text-right">31-60 Days</TableHead>
                    <TableHead className="text-right">61-90 Days</TableHead>
                    <TableHead className="text-right">90+ Days</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {agedReceivables.map((customer: any) => (
                    <TableRow key={customer.customer_id} className="hover:bg-accent/50">
                      <TableCell className="font-medium">
                        {customer.customer_name}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {customer.email || customer.phone || '-'}
                      </TableCell>
                      <TableCell className="text-right">
                        {formatCurrency(parseFloat(customer.current) || 0)}
                      </TableCell>
                      <TableCell className="text-right text-blue-600">
                        {formatCurrency(parseFloat(customer.days_1_30) || 0)}
                      </TableCell>
                      <TableCell className="text-right text-yellow-600">
                        {formatCurrency(parseFloat(customer.days_31_60) || 0)}
                      </TableCell>
                      <TableCell className="text-right text-orange-600">
                        {formatCurrency(parseFloat(customer.days_61_90) || 0)}
                      </TableCell>
                      <TableCell className="text-right text-red-600 font-medium">
                        {formatCurrency(parseFloat(customer.days_90_plus) || 0)}
                      </TableCell>
                      <TableCell className="text-right font-bold">
                        {formatCurrency(parseFloat(customer.total) || 0)}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex gap-1 justify-end">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigate(`/accounting/reporting/customer-statements?customer_id=${customer.customer_id}`)}
                          >
                            <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4 mr-1" />
                            Statement
                          </Button>
                          {overdueAmount(customer) > 0 && (
                            <Button
                              size="sm"
                              variant="outline"
                              disabled={reminderMutation.isPending}
                              onClick={() => reminderMutation.mutate(customer.customer_id)}
                            >
                              <Icon icon="solar:letter-bold-duotone" className="h-4 w-4 mr-1" />
                              Send Reminder
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => navigate(`/customers/${customer.customer_id}`)}
                          >
                            <Icon icon="solar:eye-bold-duotone" className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                  {/* Totals Row */}
                  <TableRow className="bg-accent/30 font-bold">
                    <TableCell colSpan={2}>TOTAL</TableCell>
                    <TableCell className="text-right">{formatCurrency(totals.current)}</TableCell>
                    <TableCell className="text-right text-blue-600">{formatCurrency(totals.days30)}</TableCell>
                    <TableCell className="text-right text-yellow-600">{formatCurrency(totals.days60)}</TableCell>
                    <TableCell className="text-right text-orange-600">{formatCurrency(totals.days90)}</TableCell>
                    <TableCell className="text-right text-red-600">{formatCurrency(totals.days90plus)}</TableCell>
                    <TableCell className="text-right text-primary">{formatCurrency(totals.total)}</TableCell>
                    <TableCell></TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Follow-up Actions */}
      {agedReceivables && Array.isArray(agedReceivables) && agedReceivables.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Recommended Follow-up Actions</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {agedReceivables
                .filter((c: any) => (parseFloat(c.days_90_plus) || 0) > 0)
                .map((customer: any) => (
                  <div key={customer.customer_id} className="flex items-center justify-between p-3 bg-red-50 dark:bg-red-950/20 rounded-lg">
                    <div className="flex items-center gap-3">
                      <Icon icon="solar:danger-circle-bold-duotone" className="h-6 w-6 text-red-600" />
                      <div>
                        <p className="font-medium">{customer.customer_name}</p>
                        <p className="text-sm text-muted-foreground">
                          {formatCurrency(parseFloat(customer.days_90_plus) || 0)} overdue for 90+ days
                        </p>
                      </div>
                    </div>
                    <HeaderActionButton
                      icon="solar:letter-bold-duotone"
                      size="sm"
                      variant="destructive"
                      disabled={reminderMutation.isPending}
                      onClick={() => reminderMutation.mutate(customer.customer_id)}
                    >
                      Send Reminder
                    </HeaderActionButton>
                  </div>
                ))}
              {agedReceivables
                .filter((c: any) => (parseFloat(c.days_61_90) || 0) > 0)
                .slice(0, 3)
                .map((customer: any) => (
                  <div key={customer.customer_id} className="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-950/20 rounded-lg">
                    <div className="flex items-center gap-3">
                      <Icon icon="solar:clock-circle-bold-duotone" className="h-6 w-6 text-orange-600" />
                      <div>
                        <p className="font-medium">{customer.customer_name}</p>
                        <p className="text-sm text-muted-foreground">
                          {formatCurrency(parseFloat(customer.days_61_90) || 0)} overdue for 61-90 days
                        </p>
                      </div>
                    </div>
                    <HeaderActionButton icon="solar:phone-bold-duotone" size="sm" variant="outline">
                      Call Customer
                    </HeaderActionButton>
                  </div>
                ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default CustomerFollowupReports;
