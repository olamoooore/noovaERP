import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import api from '@/lib/api';
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox';

interface AgingData {
  current: number;
  days_1_30: number;
  days_31_60: number;
  days_61_90: number;
  days_90_plus: number;
}

interface ComparisonSnapshot {
  as_of_date: string;
  total_outstanding: number;
  total_invoice_value: number;
  invoice_count: number;
  aging: AgingData;
  transactions: any[];
}

interface Delta {
  from_date: string;
  to_date: string;
  outstanding_change: number;
  outstanding_change_pct: number;
  invoice_count_change: number;
  aging_changes: AgingData;
}

interface ComparisonResponse {
  customer: {
    id: number;
    code: string;
    name: string;
    email: string;
    billing_address: string;
    payment_terms: string;
  };
  company: {
    name: string;
    address: string;
    email: string;
    phone: string;
  };
  comparisons: ComparisonSnapshot[];
  deltas: Delta[];
  currency?: string;
  generated_at: string;
}

const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount || 0);
};

const DeltaIndicator: React.FC<{ value: number; showSign?: boolean }> = ({ value, showSign = true }) => {
  if (value === 0) return <span className="text-muted-foreground">—</span>;
  const isPositive = value > 0;
  return (
    <span className={`text-xs font-medium ${isPositive ? 'text-red-600' : 'text-green-600'}`}>
      {isPositive ? '▲' : '▼'} {isPositive && showSign ? '+' : ''}{formatCurrency(value)}
    </span>
  );
};

const DeltaPercent: React.FC<{ value: number }> = ({ value }) => {
  if (value === 0) return <span className="text-muted-foreground">—</span>;
  const isPositive = value > 0;
  return (
    <span className={`text-xs font-medium ${isPositive ? 'text-red-600' : 'text-green-600'}`}>
      {isPositive ? '+' : ''}{value}%
    </span>
  );
};

export const CustomerStatementComparison: React.FC = () => {
  const [selectedCustomerId, setSelectedCustomerId] = useState<string>('');
  const [dates, setDates] = useState<string[]>(['', '']);
  const [showResults, setShowResults] = useState(false);

  const { data: comparison, isLoading: isLoadingComparison, refetch } = useQuery({
    queryKey: ['customer-statement-compare', selectedCustomerId, dates],
    queryFn: async () => {
      const validDates = dates.filter(d => d !== '');
      const response = await api.get(`/customers/${selectedCustomerId}/statement/compare`, {
        params: { dates: validDates.join(',') },
      });
      return response.data as ComparisonResponse;
    },
    enabled: false,
  });

  const handleGenerate = async () => {
    const validDates = dates.filter(d => d !== '');
    if (!selectedCustomerId || validDates.length < 2) return;
    await refetch();
    setShowResults(true);
  };

  const handleDownloadPdf = () => {
    const validDates = dates.filter(d => d !== '');
    if (!selectedCustomerId || validDates.length < 2) return;
    const url = `${api.defaults.baseURL}/customers/${selectedCustomerId}/statement/compare/pdf?dates=${validDates.join(',')}`;
    window.open(url, '_blank');
  };

  const addDate = () => {
    setDates([...dates, '']);
  };

  const removeDate = (index: number) => {
    if (dates.length <= 2) return;
    setDates(dates.filter((_, i) => i !== index));
  };

  const updateDate = (index: number, value: string) => {
    const newDates = [...dates];
    newDates[index] = value;
    setDates(newDates);
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">SOA Year-over-Year Comparison</h1>
          <p className="text-muted-foreground mt-1">
            Compare customer statement of account across different periods
          </p>
        </div>
        {showResults && comparison && (
          <HeaderActionButton icon="solar:download-minimalistic-bold" variant="outline" onClick={handleDownloadPdf}>
            Download PDF
          </HeaderActionButton>
        )}
      </div>

      {/* Configuration Card */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:settings-bold-duotone" className="h-5 w-5" />
            Comparison Settings
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Customer Selector */}
          <div className="space-y-2">
            <Label>Customer</Label>
            <CustomerSearchCombobox
              value={selectedCustomerId}
              onValueChange={setSelectedCustomerId}
              placeholder="Search customer name or code..."
            />
          </div>

          {/* Date Pickers */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>Comparison Dates (as-of)</Label>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={addDate}
                className="text-xs"
              >
                <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-1" />
                Add Date
              </Button>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {dates.map((date, index) => (
                <div key={index} className="flex items-center gap-1">
                  <Input
                    type="date"
                    value={date}
                    onChange={(e) => updateDate(index, e.target.value)}
                    className="flex-1"
                  />
                  {dates.length > 2 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => removeDate(index)}
                      className="h-8 w-8 p-0 text-muted-foreground hover:text-red-500"
                    >
                      <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              ))}
            </div>
            <p className="text-xs text-muted-foreground">
              Add at least 2 dates to compare. Dates are sorted chronologically. Recommended: use fiscal year-end dates (e.g., 2024-08-31, 2025-08-31).
            </p>
          </div>

          {/* Generate Button */}
          <div className="flex justify-end pt-2">
            <Button
              onClick={handleGenerate}
              disabled={!selectedCustomerId || dates.filter(d => d !== '').length < 2 || isLoadingComparison}
            >
              {isLoadingComparison ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                  Loading...
                </>
              ) : (
                <>
                  <Icon icon="solar:chart-bold-duotone" className="h-4 w-4 mr-2" />
                  Generate Comparison
                </>
              )}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Results */}
      {showResults && comparison && (
        <>
          {/* Customer Info */}
          <Card>
            <CardContent className="pt-6">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground">Customer</p>
                  <p className="font-medium">{comparison.customer.name}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Code</p>
                  <p className="font-medium">{comparison.customer.code}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Payment Terms</p>
                  <p className="font-medium">{comparison.customer.payment_terms || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Generated</p>
                  <p className="font-medium">{new Date(comparison.generated_at).toLocaleString()}</p>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Outstanding Balance Summary */}
          <Card>
            <CardHeader>
              <CardTitle>Outstanding Balance Summary</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[200px]">Metric</TableHead>
                    {comparison.comparisons.map((comp) => (
                      <TableHead key={comp.as_of_date} className="text-right">
                        As of {new Date(comp.as_of_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow>
                    <TableCell className="font-medium">Total Outstanding ({comparison.currency ?? 'MYR'})</TableCell>
                    {comparison.comparisons.map((comp, i) => (
                      <TableCell key={comp.as_of_date} className="text-right">
                        <div className="font-medium">{formatCurrency(comp.total_outstanding)}</div>
                        {i > 0 && comparison.deltas[i - 1] && (
                          <div className="mt-1">
                            <DeltaIndicator value={comparison.deltas[i - 1].outstanding_change} />
                            <span className="ml-1">
                              <DeltaPercent value={comparison.deltas[i - 1].outstanding_change_pct} />
                            </span>
                          </div>
                        )}
                      </TableCell>
                    ))}
                  </TableRow>
                  <TableRow>
                    <TableCell className="font-medium">Invoice Count</TableCell>
                    {comparison.comparisons.map((comp, i) => (
                      <TableCell key={comp.as_of_date} className="text-right">
                        <div className="font-medium">{comp.invoice_count}</div>
                        {i > 0 && comparison.deltas[i - 1] && (
                          <div className="mt-1">
                            <DeltaIndicator value={comparison.deltas[i - 1].invoice_count_change} showSign={false} />
                          </div>
                        )}
                      </TableCell>
                    ))}
                  </TableRow>
                  <TableRow className="bg-muted/50">
                    <TableCell className="font-bold">Total Invoice Value ({comparison.currency ?? 'MYR'})</TableCell>
                    {comparison.comparisons.map((comp) => (
                      <TableCell key={comp.as_of_date} className="text-right font-bold">
                        {formatCurrency(comp.total_invoice_value)}
                      </TableCell>
                    ))}
                  </TableRow>
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          {/* Aging Breakdown */}
          <Card>
            <CardHeader>
              <CardTitle>Aging Breakdown</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[200px]">Aging Bucket</TableHead>
                    {comparison.comparisons.map((comp) => (
                      <TableHead key={comp.as_of_date} className="text-right">
                        As of {new Date(comp.as_of_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                      </TableHead>
                    ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {[
                    { key: 'current', label: 'Current (not due)' },
                    { key: 'days_1_30', label: '1 - 30 Days' },
                    { key: 'days_31_60', label: '31 - 60 Days' },
                    { key: 'days_61_90', label: '61 - 90 Days' },
                    { key: 'days_90_plus', label: '90+ Days' },
                  ].map(({ key, label }) => (
                    <TableRow key={key}>
                      <TableCell className="font-medium">{label}</TableCell>
                      {comparison.comparisons.map((comp, i) => (
                        <TableCell key={comp.as_of_date} className="text-right">
                          <div>{formatCurrency((comp.aging as any)[key])}</div>
                          {i > 0 && comparison.deltas[i - 1] && (
                            <div className="mt-1">
                              <DeltaIndicator value={(comparison.deltas[i - 1].aging_changes as any)[key]} />
                            </div>
                          )}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                  <TableRow className="bg-muted/50 font-bold">
                    <TableCell>Total</TableCell>
                    {comparison.comparisons.map((comp) => (
                      <TableCell key={comp.as_of_date} className="text-right">
                        {formatCurrency(comp.total_outstanding)}
                      </TableCell>
                    ))}
                  </TableRow>
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}

      {/* Empty State */}
      {showResults && !isLoadingComparison && !comparison && (
        <Card>
          <CardContent className="py-12 text-center">
            <Icon icon="solar:chart-bold-duotone" className="h-16 w-16 mx-auto mb-4 text-muted-foreground opacity-50" />
            <p className="text-lg font-medium mb-2">No comparison data</p>
            <p className="text-sm text-muted-foreground">
              No outstanding invoices found for the selected customer and dates.
            </p>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default CustomerStatementComparison;
