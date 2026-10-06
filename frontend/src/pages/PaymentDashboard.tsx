import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import api from '@/lib/api';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  DollarSign,
  TrendingUp,
  CheckCircle2,
  XCircle,
  RefreshCcw,
  CreditCard,
} from 'lucide-react';
import { formatCurrency } from '@/lib/utils';

interface AnalyticsData {
  summary: {
    total_revenue: number;
    total_transactions: number;
    successful_transactions: number;
    failed_transactions: number;
    refunded_amount: number;
    average_transaction_value: number;
  };
  revenue_trend: Array<{
    period: string;
    revenue: number;
    transactions: number;
    successful: number;
    failed: number;
  }>;
  success_rate: number;
  top_gateways: Array<{
    payment_gateway_id: number;
    transactions: number;
    revenue: number;
    successful: number;
    success_rate: number;
    gateway: {
      id: number;
      gateway_name: string;
    };
  }>;
  payment_methods: Array<{
    payment_method: string;
    count: number;
    revenue: number;
  }>;
  failed_payments: Array<{
    id: number;
    transaction_id: string;
    amount: number;
    currency: string;
    error_message: string;
    invoice: {
      id: number;
      invoice_number: string;
    };
    gateway: {
      id: number;
      gateway_name: string;
    };
    created_at: string;
  }>;
}

export function PaymentDashboard() {
  const [period, setPeriod] = useState('30days');

  const { data, isLoading, error } = useQuery<{ data: AnalyticsData }>({
    queryKey: ['payment-analytics', period],
    queryFn: async () => {
      const response = await api.get(`/payment-analytics?period=${period}`);
      return response.data;
    },
  });

  const analytics = data?.data;

  if (error) {
    return (
      <div className="p-6">
        <Card className="border-red-200 bg-red-50">
          <CardContent className="p-6">
            <p className="text-red-800">Failed to load analytics data. Please try again.</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Payment Analytics</h1>
          <p className="text-gray-600">Track payment performance and metrics</p>
        </div>
        <Select value={period} onValueChange={setPeriod}>
          <SelectTrigger className="w-[180px]">
            <SelectValue placeholder="Select period" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="7days">Last 7 days</SelectItem>
            <SelectItem value="30days">Last 30 days</SelectItem>
            <SelectItem value="90days">Last 90 days</SelectItem>
            <SelectItem value="1year">Last year</SelectItem>
            <SelectItem value="all">All time</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Revenue</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-32" />
            ) : (
              <>
                <div className="text-2xl font-bold">
                  {formatCurrency(Number(analytics?.summary.total_revenue) || 0)}
                </div>
                <p className="text-xs text-muted-foreground mt-1">
                  {analytics?.summary.total_transactions || 0} transactions
                </p>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Success Rate</CardTitle>
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-24" />
            ) : (
              <>
                <div className="text-2xl font-bold">{analytics?.success_rate || 0}%</div>
                <p className="text-xs text-muted-foreground mt-1">
                  {analytics?.summary.successful_transactions || 0} successful
                </p>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Avg Transaction</CardTitle>
            <CreditCard className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-32" />
            ) : (
              <>
                <div className="text-2xl font-bold">
                  {formatCurrency(Number(analytics?.summary.average_transaction_value) || 0)}
                </div>
                <p className="text-xs text-muted-foreground mt-1">Average value</p>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Successful</CardTitle>
            <CheckCircle2 className="h-4 w-4 text-green-600" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-20" />
            ) : (
              <>
                <div className="text-2xl font-bold text-green-600">
                  {analytics?.summary.successful_transactions || 0}
                </div>
                <p className="text-xs text-muted-foreground mt-1">Completed payments</p>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Failed</CardTitle>
            <XCircle className="h-4 w-4 text-red-600" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-20" />
            ) : (
              <>
                <div className="text-2xl font-bold text-red-600">
                  {analytics?.summary.failed_transactions || 0}
                </div>
                <p className="text-xs text-muted-foreground mt-1">Failed payments</p>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Refunded</CardTitle>
            <RefreshCcw className="h-4 w-4 text-orange-600" />
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-8 w-32" />
            ) : (
              <>
                <div className="text-2xl font-bold text-orange-600">
                  {formatCurrency(Number(analytics?.summary.refunded_amount) || 0)}
                </div>
                <p className="text-xs text-muted-foreground mt-1">Total refunds</p>
              </>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Top Payment Gateways */}
      <Card>
        <CardHeader>
          <CardTitle>Top Payment Gateways</CardTitle>
          <CardDescription>Performance by payment gateway</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-2">
              {[1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          ) : (
            <div className="space-y-4">
              {analytics?.top_gateways.map((gateway) => (
                <div
                  key={gateway.payment_gateway_id}
                  className="flex items-center justify-between p-4 border rounded-lg"
                >
                  <div>
                    <p className="font-medium">{gateway.gateway.gateway_name}</p>
                    <p className="text-sm text-gray-600">
                      {gateway.transactions} transactions • {gateway.success_rate}% success rate
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="font-semibold">{formatCurrency(Number(gateway.revenue) || 0)}</p>
                    <p className="text-sm text-green-600">{gateway.successful} successful</p>
                  </div>
                </div>
              ))}
              {(!analytics?.top_gateways || analytics.top_gateways.length === 0) && (
                <p className="text-center text-gray-500 py-8">No gateway data available</p>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Recent Failed Payments */}
      <Card>
        <CardHeader>
          <CardTitle>Recent Failed Payments</CardTitle>
          <CardDescription>Last 10 failed payment attempts</CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="space-y-2">
              {[1, 2, 3].map((i) => (
                <Skeleton key={i} className="h-16 w-full" />
              ))}
            </div>
          ) : (
            <div className="space-y-3">
              {analytics?.failed_payments.map((payment) => (
                <div
                  key={payment.id}
                  className="flex items-start justify-between p-4 border border-red-100 rounded-lg bg-red-50/50"
                >
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <p className="font-medium text-sm">{payment.transaction_id}</p>
                      <span className="text-xs text-gray-500">
                        Invoice: {payment.invoice.invoice_number}
                      </span>
                    </div>
                    <p className="text-sm text-red-700 mt-1">
                      {payment.error_message || 'Payment failed'}
                    </p>
                    <p className="text-xs text-gray-600 mt-1">
                      {payment.gateway.gateway_name} •{' '}
                      {new Date(payment.created_at).toLocaleDateString()}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="font-semibold text-red-700">
                      {formatCurrency(Number(payment.amount) || 0, payment.currency || undefined)}
                    </p>
                  </div>
                </div>
              ))}
              {(!analytics?.failed_payments || analytics.failed_payments.length === 0) && (
                <p className="text-center text-gray-500 py-8">No failed payments in this period</p>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
