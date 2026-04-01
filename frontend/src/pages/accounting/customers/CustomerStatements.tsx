import React, { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface StatementRecord {
  id: number;
  customer_id: number;
  customer?: {
    name: string;
    email?: string;
  };
  period: {
    start_date: string;
    end_date: string;
    type: string;
  };
  email_sent: boolean;
  recipient_email?: string | null;
  pdf_url?: string | null;
  generated_at?: string;
  closing_balance: number;
}

export const CustomerStatements: React.FC = () => {
  const [showGenerateDialog, setShowGenerateDialog] = useState(false);
  const [prefilledCustomerId, setPrefilledCustomerId] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // Fetch customers with outstanding balances
  const { data: customers, isLoading } = useQuery({
    queryKey: ['customers-with-balances', searchQuery],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (searchQuery) params.append('search', searchQuery);
      params.append('has_balance', 'true');
      
      const response = await api.get(`/customers?${params.toString()}`);
      return response.data.data || [];
    },
  });

  const { data: history = [], isLoading: isLoadingHistory } = useQuery({
    queryKey: ['customer-statement-history'],
    queryFn: async () => {
      const response = await api.get('/accounting/customers/statements/history');
      return response.data.data || [];
    },
  });

  const latestStatementByCustomer = useMemo(() => {
    const latest = new Map<number, StatementRecord>();
    (history as StatementRecord[]).forEach((statement) => {
      if (!latest.has(statement.customer_id)) {
        latest.set(statement.customer_id, statement);
      }
    });
    return latest;
  }, [history]);

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
          <h1 className="text-3xl font-bold">Customer Statements</h1>
          <p className="text-muted-foreground mt-1">
            Generate and send account statements to customers
          </p>
        </div>
        <Dialog open={showGenerateDialog} onOpenChange={setShowGenerateDialog}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:document-add-bold-duotone" className="h-4 w-4 mr-2" />
              Generate Statement
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-2xl">
            <DialogHeader>
              <DialogTitle>Generate Customer Statement</DialogTitle>
            </DialogHeader>
            <GenerateStatementForm
              customers={customers || []}
              initialCustomerId={prefilledCustomerId}
              onSuccess={() => {
                setShowGenerateDialog(false);
                setPrefilledCustomerId('');
                queryClient.invalidateQueries({ queryKey: ['customer-statement-history'] });
                toast({ title: 'Statement generated successfully' });
              }}
            />
          </DialogContent>
        </Dialog>
      </div>

      {/* Search */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex gap-4">
            <input
              type="text"
              placeholder="Search by customer name, email..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </CardContent>
      </Card>

      {/* Customers List */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>Customers</span>
            {customers && customers.length > 0 && (
              <span className="text-sm font-normal text-muted-foreground">
                {customers.length} customer(s)
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto"></div>
              <p className="text-muted-foreground mt-4">Loading customers...</p>
            </div>
          ) : !customers || customers.length === 0 ? (
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:users-group-rounded-bold-duotone" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium mb-2">No customers found</p>
              <p className="text-sm">Customers with outstanding balances will appear here</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Customer Name</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Phone</TableHead>
                    <TableHead className="text-right">Outstanding Balance</TableHead>
                    <TableHead>Last Statement</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {customers.map((customer: any) => {
                    const latestStatement = latestStatementByCustomer.get(customer.id);

                    return (
                    <TableRow key={customer.id} className="hover:bg-accent/50">
                      <TableCell className="font-medium">{customer.name}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {customer.email || '-'}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {customer.phone || '-'}
                      </TableCell>
                      <TableCell className="text-right font-medium">
                        {formatCurrency(customer.outstanding_balance || 0)}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {latestStatement?.generated_at ? formatDate(latestStatement.generated_at) : 'Never'}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex gap-1 justify-end">
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              setPrefilledCustomerId(customer.id.toString());
                              setShowGenerateDialog(true);
                            }}
                          >
                            <Icon icon="solar:document-add-bold-duotone" className="h-4 w-4 mr-1" />
                            Generate
                          </Button>
                          {latestStatement?.pdf_url && (
                            <Button size="sm" variant="ghost" asChild>
                              <a href={latestStatement.pdf_url} target="_blank" rel="noreferrer">
                                <Icon icon="solar:download-minimalistic-bold" className="h-4 w-4" />
                              </a>
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Statement History</CardTitle>
        </CardHeader>
        <CardContent>
          {isLoadingHistory ? (
            <div className="text-sm text-muted-foreground">Loading statement history...</div>
          ) : !(history as StatementRecord[]).length ? (
            <div className="text-sm text-muted-foreground">No statements generated yet.</div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Customer</TableHead>
                  <TableHead>Period</TableHead>
                  <TableHead className="text-right">Closing Balance</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Generated</TableHead>
                  <TableHead className="text-right">PDF</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(history as StatementRecord[]).map((statement) => (
                  <TableRow key={statement.id}>
                    <TableCell className="font-medium">
                      {statement.customer?.name || `Customer #${statement.customer_id}`}
                    </TableCell>
                    <TableCell>
                      {formatDate(statement.period.start_date)} to {formatDate(statement.period.end_date)}
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(statement.closing_balance)}</TableCell>
                    <TableCell>
                      {statement.email_sent ? (
                        <Badge variant="outline">Sent{statement.recipient_email ? ` to ${statement.recipient_email}` : ''}</Badge>
                      ) : (
                        <Badge variant="secondary">Not sent</Badge>
                      )}
                    </TableCell>
                    <TableCell>{formatDate(statement.generated_at)}</TableCell>
                    <TableCell className="text-right">
                      {statement.pdf_url ? (
                        <Button size="sm" variant="ghost" asChild>
                          <a href={statement.pdf_url} target="_blank" rel="noreferrer">Download</a>
                        </Button>
                      ) : (
                        '-'
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Quick Stats */}
      {customers && customers.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Customers</p>
                  <p className="text-2xl font-bold">{customers.length}</p>
                </div>
                <Icon icon="solar:users-group-rounded-bold-duotone" className="h-8 w-8 text-blue-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Total Outstanding</p>
                  <p className="text-2xl font-bold">
                    {formatCurrency(
                      customers.reduce((sum: number, c: any) => sum + (c.outstanding_balance || 0), 0)
                    )}
                  </p>
                </div>
                <Icon icon="solar:dollar-bold-duotone" className="h-8 w-8 text-green-500" />
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-muted-foreground">Statements This Month</p>
                  <p className="text-2xl font-bold">
                    {customers.filter((c: any) => {
                      if (!c.last_statement_date) return false;
                      const statementDate = new Date(c.last_statement_date);
                      const now = new Date();
                      return statementDate.getMonth() === now.getMonth() && 
                             statementDate.getFullYear() === now.getFullYear();
                    }).length}
                  </p>
                </div>
                <Icon icon="solar:document-text-bold-duotone" className="h-8 w-8 text-orange-500" />
              </div>
            </CardContent>
          </Card>
        </div>
      )}
    </div>
  );
};

// Generate Statement Form Component
const GenerateStatementForm: React.FC<{
  customers: any[];
  initialCustomerId?: string;
  onSuccess: () => void;
}> = ({ customers, initialCustomerId, onSuccess }) => {
  const [formData, setFormData] = useState(() => {
    const endDate = new Date();
    const startDate = new Date(endDate);
    startDate.setMonth(startDate.getMonth() - 1);
    return {
      customer_id: initialCustomerId || '',
      period_type: 'monthly',
      start_date: startDate.toISOString().split('T')[0],
      end_date: endDate.toISOString().split('T')[0],
      include_paid: false,
      send_email: false,
    };
  });

  const { toast } = useToast();

  const generateStatementMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post(`/accounting/customers/${data.customer_id}/statement`, data);
      return response.data;
    },
    onSuccess: (data) => {
      onSuccess();
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to generate statement',
        description: error.response?.data?.message || 'An error occurred',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    generateStatementMutation.mutate(formData);
  };

  // Auto-calculate start date based on period type
  const handlePeriodTypeChange = (value: string) => {
    const endDate = new Date(formData.end_date);
    let startDate = new Date(endDate);

    switch (value) {
      case 'monthly':
        startDate.setMonth(startDate.getMonth() - 1);
        break;
      case 'quarterly':
        startDate.setMonth(startDate.getMonth() - 3);
        break;
      case 'yearly':
        startDate.setFullYear(startDate.getFullYear() - 1);
        break;
    }

    setFormData({
      ...formData,
      period_type: value,
      start_date: startDate.toISOString().split('T')[0],
    });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="customer_id">Customer</Label>
        <Select
          value={formData.customer_id}
          onValueChange={(value) => setFormData({ ...formData, customer_id: value })}
        >
          <SelectTrigger>
            <SelectValue placeholder="Select customer" />
          </SelectTrigger>
          <SelectContent>
            {customers.map((customer) => (
              <SelectItem key={customer.id} value={customer.id.toString()}>
                {customer.name} - {customer.email}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="period_type">Period Type</Label>
          <Select
            value={formData.period_type}
            onValueChange={handlePeriodTypeChange}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="monthly">Monthly</SelectItem>
              <SelectItem value="quarterly">Quarterly</SelectItem>
              <SelectItem value="yearly">Yearly</SelectItem>
              <SelectItem value="custom">Custom Range</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label htmlFor="end_date">End Date</Label>
          <Input
            id="end_date"
            type="date"
            value={formData.end_date}
            onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
            required
          />
        </div>
      </div>

      {formData.period_type === 'custom' && (
        <div className="space-y-2">
          <Label htmlFor="start_date">Start Date</Label>
          <Input
            id="start_date"
            type="date"
            value={formData.start_date}
            onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
            required
          />
        </div>
      )}

      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <input
            type="checkbox"
            id="include_paid"
            checked={formData.include_paid}
            onChange={(e) => setFormData({ ...formData, include_paid: e.target.checked })}
            className="rounded border-gray-300"
          />
          <Label htmlFor="include_paid" className="cursor-pointer">
            Include paid invoices
          </Label>
        </div>

        <div className="flex items-center gap-2">
          <input
            type="checkbox"
            id="send_email"
            checked={formData.send_email}
            onChange={(e) => setFormData({ ...formData, send_email: e.target.checked })}
            className="rounded border-gray-300"
          />
          <Label htmlFor="send_email" className="cursor-pointer">
            Send statement via email
          </Label>
        </div>
      </div>

      <div className="bg-blue-50 dark:bg-blue-950/20 p-4 rounded-lg">
        <div className="flex items-start gap-3">
          <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-blue-600 mt-0.5" />
          <div className="text-sm">
            <p className="font-medium text-blue-900 dark:text-blue-100 mb-1">Statement Preview</p>
            <p className="text-blue-700 dark:text-blue-300">
              The statement will include all transactions (invoices, payments, credits) for the selected period.
              {formData.send_email && ' An email will be sent to the customer with the PDF attachment.'}
            </p>
          </div>
        </div>
      </div>

      <div className="flex justify-end gap-2 pt-4">
        <Button
          type="submit"
          disabled={generateStatementMutation.isPending || !formData.customer_id}
        >
          {generateStatementMutation.isPending ? (
            <>
              <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
              Generating...
            </>
          ) : (
            <>
              <Icon icon="solar:document-add-bold-duotone" className="h-4 w-4 mr-2" />
              Generate Statement
            </>
          )}
        </Button>
      </div>
    </form>
  );
};

export default CustomerStatements;
