import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import reportApi from '@/lib/reportApi';

export default function CashFlowPage() {
  const navigate = useNavigate();
  const [startDate, setStartDate] = useState(
    new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
  const [method, setMethod] = useState<'direct' | 'indirect'>('indirect');

  // Fetch cash flow
  const { data, isLoading, error } = useQuery({
    queryKey: ['cash-flow', startDate, endDate, method],
    queryFn: () => reportApi.getCashFlow({ 
      start_date: startDate, 
      end_date: endDate,
      method 
    }),
    enabled: !!startDate && !!endDate,
  });

  // reportApi.getCashFlow() already returns response.data.data
  const cashFlow = data;

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount);
  };

  const renderSection = (title: string, section: any, icon: string) => {
    if (!section) return null;

    return (
      <div className="mb-6">
        <div className="flex items-center gap-2 mb-3">
          <Icon icon={icon} className="w-5 h-5 text-primary" />
          <h3 className="text-lg font-semibold">{title}</h3>
        </div>
        <Table>
          <TableBody>
            {section.items?.map((item: any, index: number) => (
              <TableRow key={index}>
                <TableCell className="pl-6">{item.description || item.name}</TableCell>
                <TableCell className="text-right font-mono">
                  {formatCurrency(item.amount)}
                </TableCell>
              </TableRow>
            ))}
            <TableRow className="font-bold border-t">
              <TableCell>Net Cash from {title}</TableCell>
              <TableCell className="text-right font-mono">
                {formatCurrency(section.total)}
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    );
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Cash Flow Statement</h1>
          <p className="text-muted-foreground mt-1">
            Show cash inflows and outflows
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="space-y-2">
            <Label htmlFor="start_date">Start Date</Label>
            <Input
              id="start_date"
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="end_date">End Date</Label>
            <Input
              id="end_date"
              type="date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="method">Method</Label>
            <Select value={method} onValueChange={(value: any) => setMethod(value)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="indirect">Indirect Method</SelectItem>
                <SelectItem value="direct">Direct Method</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex items-end">
            <Button onClick={() => window.print()} variant="outline" className="w-full">
              <Icon icon="solar:printer-linear" className="w-4 h-4 mr-2" />
              Print
            </Button>
          </div>
        </div>
      </Card>

      {/* Report */}
      <Card className="p-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading cash flow statement...</p>
          </div>
        ) : error ? (
          <div className="text-center py-12 text-destructive">
            <Icon icon="solar:danger-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>Failed to load cash flow statement</p>
          </div>
        ) : !cashFlow ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:money-bag-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No data available</p>
          </div>
        ) : (
          <>
            <div className="mb-6">
              <h2 className="text-xl font-semibold">Cash Flow Statement</h2>
              <p className="text-sm text-muted-foreground">
                {new Date(startDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })} - {new Date(endDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })}
              </p>
              <p className="text-sm text-muted-foreground">
                Method: {method === 'indirect' ? 'Indirect' : 'Direct'}
              </p>
            </div>

            {/* Opening Cash */}
            <div className="mb-6 p-4 bg-muted rounded-lg">
              <div className="flex justify-between items-center">
                <span className="font-semibold">Opening Cash Balance:</span>
                <span className="font-mono text-lg">{formatCurrency(cashFlow.opening_cash || 0)}</span>
              </div>
            </div>

            {/* Operating Activities */}
            {renderSection(
              'Operating Activities',
              cashFlow.operating_activities,
              'solar:chart-2-bold-duotone'
            )}

            {/* Investing Activities */}
            {renderSection(
              'Investing Activities',
              cashFlow.investing_activities,
              'solar:graph-new-bold-duotone'
            )}

            {/* Financing Activities */}
            {renderSection(
              'Financing Activities',
              cashFlow.financing_activities,
              'solar:wallet-money-bold-duotone'
            )}

            {/* Net Cash Flow */}
            <div className="mt-6 p-4 bg-primary/10 rounded-lg">
              <div className="flex justify-between items-center font-bold text-lg">
                <span>Net Increase/(Decrease) in Cash:</span>
                <span className={`font-mono ${cashFlow.net_cash_flow >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                  {formatCurrency(cashFlow.net_cash_flow || 0)}
                </span>
              </div>
            </div>

            {/* Closing Cash */}
            <div className="mt-4 p-4 bg-muted rounded-lg">
              <div className="flex justify-between items-center font-bold text-lg">
                <span>Closing Cash Balance:</span>
                <span className="font-mono">{formatCurrency(cashFlow.closing_cash || 0)}</span>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
