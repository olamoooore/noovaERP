import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type {
  MedicalCaseDateField,
  MedicalCasePerformanceHospitalRow,
  ReportFilters as ReportFiltersType,
} from '@/types/reports';

interface ProductUsageViewProps {
  filters: Pick<ReportFiltersType, 'start_date' | 'end_date'>;
  periodLabel: string;
}

interface DoctorTotal extends MedicalCasePerformanceHospitalRow {
  doctor_contact_id: number;
  doctor_name: string | null;
}

/**
 * Issue #609: the frontend for GET /medical-cases/reports/performance, which
 * previously had no caller. Shows medical-case usage per hospital, per doctor
 * and per product for the period. Quantities are units actually used
 * (delivered from a completed delivery-order line), never allocations.
 *
 * Only the period applies here — the sales filters above (customer, product,
 * tag…) are not understood by this endpoint, so they are not sent.
 */
export function ProductUsageView({ filters, periodLabel }: ProductUsageViewProps) {
  const [dateField, setDateField] = useState<MedicalCaseDateField>('procedure_date');

  const { data, isLoading, error } = useQuery({
    queryKey: ['medical-case-performance', filters.start_date, filters.end_date, dateField],
    queryFn: () => reportApi.getMedicalCasePerformance(filters, dateField),
    enabled: !!filters.start_date && !!filters.end_date,
    staleTime: 15 * 1000,
  });

  const hospitals = useMemo(
    () => [...(data?.hospital_totals ?? [])].sort((a, b) => b.case_count - a.case_count),
    [data],
  );

  // by_doctor is per doctor contact row AND month — roll the months up.
  const doctors = useMemo<DoctorTotal[]>(() => {
    const totals = new Map<number, DoctorTotal>();
    for (const row of data?.by_doctor ?? []) {
      const current = totals.get(row.doctor_contact_id) ?? {
        doctor_contact_id: row.doctor_contact_id,
        doctor_name: row.doctor_name,
        customer_id: row.customer_id,
        customer_name: row.customer_name,
        case_count: 0,
        invoiced_value: 0,
        bonus_case_count: 0,
      };
      current.case_count += row.case_count;
      current.invoiced_value += row.invoiced_value;
      current.bonus_case_count += row.bonus_case_count;
      totals.set(row.doctor_contact_id, current);
    }
    return [...totals.values()].sort((a, b) => b.case_count - a.case_count);
  }, [data]);

  const products = useMemo(
    () => [...(data?.product_mix ?? [])].sort((a, b) => b.quantity - a.quantity),
    [data],
  );

  const summary = useMemo(
    () => ({
      cases: hospitals.reduce((sum, row) => sum + row.case_count, 0),
      units: products.reduce((sum, row) => sum + row.quantity, 0),
      value: hospitals.reduce((sum, row) => sum + row.invoiced_value, 0),
      bonusCases: hospitals.reduce((sum, row) => sum + row.bonus_case_count, 0),
    }),
    [hospitals, products],
  );

  const errorMessage =
    (error as { response?: { status?: number; data?: { message?: string } } } | null)?.response?.status === 403
      ? 'You need the medical cases view permission to see product usage.'
      : (error as { response?: { data?: { message?: string } } } | null)?.response?.data?.message ??
        (error as Error | null)?.message;

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Product Usage</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Medical-case units used by hospital, doctor and product · {periodLabel}
            </p>
          </div>
          <div className="w-48">
            <Select value={dateField} onValueChange={(value) => setDateField(value as MedicalCaseDateField)}>
              <SelectTrigger aria-label="Date basis">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="procedure_date">By procedure date</SelectItem>
                <SelectItem value="case_date">By case date</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardHeader>

        <CardContent className="space-y-6 pt-6">
          {isLoading && (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2">Loading product usage…</p>
            </div>
          )}

          {!isLoading && error && (
            <div className="py-12 text-center text-destructive">
              <p className="font-medium">Failed to load product usage</p>
              <p className="mt-1 text-sm">{errorMessage}</p>
            </div>
          )}

          {!isLoading && !error && data && (
            <>
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatCard
                  label="Cases"
                  value={formatNumber(summary.cases)}
                  icon="solar:medical-kit-bold-duotone"
                  tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
                />
                <StatCard
                  label="Units Used"
                  value={formatNumber(summary.units)}
                  icon="solar:box-bold-duotone"
                  tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
                />
                <StatCard
                  label="Invoiced Value"
                  value={formatCurrency(summary.value)}
                  icon="solar:wallet-money-bold-duotone"
                  tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                />
                <StatCard
                  label="Bonus Cases"
                  value={formatNumber(summary.bonusCases)}
                  icon="solar:gift-bold-duotone"
                  tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                />
              </div>

              {hospitals.length === 0 ? (
                <div className="py-12 text-center text-muted-foreground">
                  <Icon icon="solar:document-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
                  <p>No medical cases in this period.</p>
                </div>
              ) : (
                <div className="grid gap-6 xl:grid-cols-2">
                  <section aria-label="By hospital" className="overflow-x-auto">
                    <h3 className="mb-2 text-sm font-semibold">By Hospital</h3>
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b bg-muted/40">
                          <th className="px-3 py-2 text-left font-medium">Hospital</th>
                          <th className="px-3 py-2 text-right font-medium">Cases</th>
                          <th className="px-3 py-2 text-right font-medium">Bonus Cases</th>
                          <th className="px-3 py-2 text-right font-medium">Invoiced Value</th>
                        </tr>
                      </thead>
                      <tbody>
                        {hospitals.map((row) => (
                          <tr key={row.customer_id} className="border-b last:border-b-0">
                            <td className="px-3 py-2">{row.customer_name ?? 'Unknown hospital'}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.case_count)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.bonus_case_count)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(row.invoiced_value)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </section>

                  <section aria-label="By doctor" className="overflow-x-auto">
                    <h3 className="mb-2 text-sm font-semibold">By Doctor</h3>
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b bg-muted/40">
                          <th className="px-3 py-2 text-left font-medium">Doctor</th>
                          <th className="px-3 py-2 text-left font-medium">Hospital</th>
                          <th className="px-3 py-2 text-right font-medium">Cases</th>
                          <th className="px-3 py-2 text-right font-medium">Invoiced Value</th>
                        </tr>
                      </thead>
                      <tbody>
                        {doctors.length === 0 ? (
                          <tr>
                            <td className="px-3 py-4 text-center text-muted-foreground" colSpan={4}>
                              No cases have a doctor assigned.
                            </td>
                          </tr>
                        ) : (
                          doctors.map((row) => (
                            <tr key={row.doctor_contact_id} className="border-b last:border-b-0">
                              <td className="px-3 py-2">{row.doctor_name ?? 'Unnamed doctor'}</td>
                              <td className="px-3 py-2 text-muted-foreground">{row.customer_name ?? '—'}</td>
                              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.case_count)}</td>
                              <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(row.invoiced_value)}</td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </section>

                  <section aria-label="Products used" className="overflow-x-auto xl:col-span-2">
                    <h3 className="mb-2 text-sm font-semibold">Products Used</h3>
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b bg-muted/40">
                          <th className="px-3 py-2 text-left font-medium">Product</th>
                          <th className="px-3 py-2 text-left font-medium">SKU</th>
                          <th className="px-3 py-2 text-right font-medium">Units Used</th>
                        </tr>
                      </thead>
                      <tbody>
                        {products.map((row) => (
                          <tr key={row.product_id ?? 'unknown'} className="border-b last:border-b-0">
                            <td className="px-3 py-2">{row.product_name ?? 'Unknown product'}</td>
                            <td className="px-3 py-2 text-muted-foreground">{row.sku ?? '—'}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{formatNumber(row.quantity)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </section>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default ProductUsageView;
