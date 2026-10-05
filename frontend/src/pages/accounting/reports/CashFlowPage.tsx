import { useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input';
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
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { ReportScopeHeader } from '@/components/reports/ReportScopeHeader';
import { useReportScope } from '@/hooks/useReportScope';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';

export default function CashFlowPage() {
  const navigate = useNavigate();
  const [startDate, setStartDate] = useState(
    new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
  const [method, setMethod] = useState<'direct' | 'indirect'>('indirect');
  const reportRef = useRef<HTMLDivElement>(null);
  const scope = useReportScope();
  const money = (value: number) => formatCurrency(value, scope.currencyCode);

  // Fetch cash flow
  const { data, isLoading, error } = useQuery({
    queryKey: ['cash-flow', startDate, endDate, method, scope.entityId],
    queryFn: () => reportApi.getCashFlow({ 
      start_date: startDate, 
      end_date: endDate,
      method,
      legal_entity_id: scope.legalEntityId,
    }),
    enabled: !!startDate && !!endDate && !scope.missingScope,
  });

  // reportApi.getCashFlow() already returns response.data.data
  const cashFlow = data;

  const renderSection = (title: string, section: any, icon: string) => {
    if (!section) return null;

    return (
      <div className="mb-6">
        <div className="flex items-center gap-2 mb-3">
          <Icon icon={icon} className="w-5 h-5 text-primary" />
          <h3 className="text-lg font-semibold">{title}</h3>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Description</TableHead>
              <TableHead className="text-right">
                Amount{scope.currencyCode ? ` (${scope.currencyCode})` : ''}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {section.items?.map((item: any, index: number) => (
              <TableRow key={index}>
                <TableCell className="pl-6">{item.description || item.name}</TableCell>
                <TableCell className="text-right font-mono">
                  {money(item.amount)}
                </TableCell>
              </TableRow>
            ))}
            <TableRow className="font-bold border-t">
              <TableCell>Net Cash from {title}</TableCell>
              <TableCell className="text-right font-mono">
                {money(section.total)}
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
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Cash Flow Statement</h1>
          <p className="text-muted-foreground mt-1">
            Show cash inflows and outflows
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            id="start_date"
            type="date"
            value={startDate}
            onChange={(e) => {
              const value = e.target.value;
              setStartDate(value);
              // Keep the range valid: the API rejects end_date < start_date.
              if (endDate && value > endDate) setEndDate(value);
            }}
            className="h-8 w-40 rounded-full px-3 text-xs"
          />
          <Input
            id="end_date"
            type="date"
            value={endDate}
            onChange={(e) => {
              const value = e.target.value;
              setEndDate(value);
              if (startDate && value < startDate) setStartDate(value);
            }}
            className="h-8 w-40 rounded-full px-3 text-xs"
          />
          <Select value={method} onValueChange={(value: any) => setMethod(value)}>
            <SelectTrigger className="h-8 w-40 rounded-full text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="indirect">Indirect Method</SelectItem>
              <SelectItem value="direct">Direct Method</SelectItem>
            </SelectContent>
          </Select>
          <ReportExportToolbar
            targetRef={reportRef}
            title="Cash Flow Statement"
            subtitle={`${method === 'direct' ? 'Direct' : 'Indirect'} method · ${startDate} → ${endDate} · ${scope.scopeLabel}`}
            filename={`cash-flow-${method}-${startDate}-${endDate}`}
          />
          <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
            Back to Accounting
          </HeaderActionButton>
        </div>
      </div>

      {/* Scope: entity + reporting currency */}
      <ReportScopeHeader scope={scope} />

      {/* Report */}
      <Card className="p-6" ref={reportRef}>
        {scope.missingScope ? (
          <div className="py-12 text-center text-muted-foreground" data-testid="report-scope-required">
            <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
            <p>Select a legal entity to run this report.</p>
            <p className="text-sm">
              Amounts in {scope.currencies.join(' and ')} are never summed together.
            </p>
          </div>
        ) : isLoading ? (
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
                <span className="font-mono text-lg">{money(cashFlow.opening_cash || 0)}</span>
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
                  {money(cashFlow.net_cash_flow || 0)}
                </span>
              </div>
            </div>

            {/* Closing Cash */}
            <div className="mt-4 p-4 bg-muted rounded-lg">
              <div className="flex justify-between items-center font-bold text-lg">
                <span>Closing Cash Balance:</span>
                <span className="font-mono">{money(cashFlow.closing_cash || 0)}</span>
              </div>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
