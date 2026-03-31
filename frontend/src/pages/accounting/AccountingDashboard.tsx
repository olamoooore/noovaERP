import React, { useEffect, useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { useNavigate } from 'react-router-dom';
import api from '@/lib/api';

interface DashboardMetrics {
  outstanding_receivables: {
    amount: number;
    formatted: string;
  };
  outstanding_payables: {
    amount: number;
    formatted: string;
  };
  bank_balance: {
    amount: number;
    formatted: string;
  };
  unreconciled_items: {
    count: number;
  };
  recent_invoices: Array<{
    id: number;
    invoice_number: string;
    invoice_date: string;
    total_amount: number;
    status: string;
    customer_name: string;
  }>;
  recent_bills: Array<{
    id: number;
    bill_number: string;
    bill_date: string;
    total_amount: number;
    status: string;
    vendor_name: string;
  }>;
}

/**
 * Accounting Dashboard Page
 * Main dashboard for the accounting module showing key metrics and quick actions
 */
export const AccountingDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchDashboardMetrics();
  }, []);

  const fetchDashboardMetrics = async () => {
    try {
      setLoading(true);
      const response = await api.get('/accounting/dashboard');
      setMetrics(response.data);
    } catch (err) {
      console.error('Error fetching dashboard metrics:', err);
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="p-6 space-y-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="h-12 w-12 mx-auto text-primary" />
          <p className="mt-4 text-muted-foreground">Loading dashboard...</p>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6 space-y-6">
        <div className="text-center py-12">
          <Icon icon="solar:danger-circle-bold-duotone" className="h-12 w-12 mx-auto text-destructive" />
          <p className="mt-4 text-destructive">Error: {error}</p>
          <button 
            onClick={fetchDashboardMetrics}
            className="mt-4 px-4 py-2 bg-primary text-primary-foreground rounded-md hover:bg-primary/90"
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-3xl font-bold">Accounting Dashboard</h1>
        <p className="text-muted-foreground mt-1">
          Overview of your accounting operations
        </p>
      </div>

      {/* Quick Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">
              Outstanding Receivables
            </CardTitle>
            <Icon icon="solar:wallet-money-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{metrics?.outstanding_receivables.formatted || '$0.00'}</div>
            <p className="text-xs text-muted-foreground">
              From customer invoices
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">
              Outstanding Payables
            </CardTitle>
            <Icon icon="solar:card-send-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{metrics?.outstanding_payables.formatted || '$0.00'}</div>
            <p className="text-xs text-muted-foreground">
              To vendors
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">
              Bank Balance
            </CardTitle>
            <Icon icon="solar:card-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{metrics?.bank_balance.formatted || '$0.00'}</div>
            <p className="text-xs text-muted-foreground">
              Across all accounts
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">
              Unreconciled Items
            </CardTitle>
            <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{metrics?.unreconciled_items.count || 0}</div>
            <p className="text-xs text-muted-foreground">
              Pending reconciliation
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Quick Actions */}
      <Card>
        <CardHeader>
          <CardTitle>Quick Actions</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <button onClick={() => navigate('/accounting/customers/bills/create')} className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:bg-accent transition-colors">
              <Icon icon="solar:document-add-bold-duotone" className="h-8 w-8 text-primary" />
              <span className="text-sm font-medium">New Invoice</span>
            </button>
            <button onClick={() => navigate('/accounting/vendors/bills/create')} className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:bg-accent transition-colors">
              <Icon icon="solar:bill-list-bold-duotone" className="h-8 w-8 text-primary" />
              <span className="text-sm font-medium">New Bill</span>
            </button>
            <button onClick={() => navigate('/accounting/customers/payments')} className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:bg-accent transition-colors">
              <Icon icon="solar:wallet-money-bold-duotone" className="h-8 w-8 text-primary" />
              <span className="text-sm font-medium">Record Payment</span>
            </button>
            <button onClick={() => navigate('/accounting/core/journal-entries')} className="flex flex-col items-center gap-2 p-4 rounded-lg border hover:bg-accent transition-colors">
              <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-primary" />
              <span className="text-sm font-medium">Journal Entry</span>
            </button>
          </div>
        </CardContent>
      </Card>

      {/* Recent Activity */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Recent Customer Invoices</CardTitle>
          </CardHeader>
          <CardContent>
            {metrics?.recent_invoices && metrics.recent_invoices.length > 0 ? (
              <div className="space-y-3">
                {metrics.recent_invoices.map((invoice) => (
                  <div 
                    key={invoice.id}
                    className="flex items-center justify-between p-3 rounded-lg border hover:bg-accent cursor-pointer transition-colors"
                    onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                  >
                    <div className="flex-1">
                      <div className="font-medium">{invoice.invoice_number}</div>
                      <div className="text-sm text-muted-foreground">{invoice.customer_name}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-medium">${invoice.total_amount.toFixed(2)}</div>
                      <div className="text-xs text-muted-foreground">{new Date(invoice.invoice_date).toLocaleDateString()}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-8 text-muted-foreground">
                <Icon icon="solar:document-text-bold-duotone" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                <p>No recent invoices</p>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Recent Vendor Bills</CardTitle>
          </CardHeader>
          <CardContent>
            {metrics?.recent_bills && metrics.recent_bills.length > 0 ? (
              <div className="space-y-3">
                {metrics.recent_bills.map((bill) => (
                  <div 
                    key={bill.id}
                    className="flex items-center justify-between p-3 rounded-lg border hover:bg-accent cursor-pointer transition-colors"
                    onClick={() => navigate(`/accounting/vendors/bills/${bill.id}`)}
                  >
                    <div className="flex-1">
                      <div className="font-medium">{bill.bill_number}</div>
                      <div className="text-sm text-muted-foreground">{bill.vendor_name}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-medium">${bill.total_amount.toFixed(2)}</div>
                      <div className="text-xs text-muted-foreground">{new Date(bill.bill_date).toLocaleDateString()}</div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-8 text-muted-foreground">
                <Icon icon="solar:bill-list-bold-duotone" className="h-12 w-12 mx-auto mb-2 opacity-50" />
                <p>No recent bills</p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default AccountingDashboard;
