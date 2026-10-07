import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatCard } from '@/components/ui/stat-card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox';
import reportApi from '@/lib/reportApi';
import { formatCurrency, formatNumber } from '@/lib/utils';
import type {
  MedicalCaseDateField,
  MedicalCasePerformanceHospitalRow,
  MedicalCaseUsageCase,
  MedicalCaseUsageRow,
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
// ---------------------------------------------------------------------------
// Issue #608: Product usage pivot — legal entity → hospital group → hospital →
// doctor × product (or category), with used / paid / FOC units, cases and net
// value, expandable subtotals, a grand total and drill-down to the cases.
// ---------------------------------------------------------------------------

type UsageLevel = 'legal_entity' | 'hospital_group' | 'hospital' | 'doctor';
type UsageLeaf = 'product' | 'category';

const USAGE_LEVELS: Array<{ key: UsageLevel; label: string }> = [
  { key: 'legal_entity', label: 'Legal entity' },
  { key: 'hospital_group', label: 'Hospital group' },
  { key: 'hospital', label: 'Hospital' },
  { key: 'doctor', label: 'Doctor' },
];

type UsageDimension = UsageLevel | UsageLeaf;

function dimensionOf(row: MedicalCaseUsageRow, dimension: UsageDimension): { id: string; label: string } {
  switch (dimension) {
    case 'legal_entity':
      return { id: String(row.legal_entity_id ?? 'none'), label: row.legal_entity_name ?? 'No legal entity' };
    case 'hospital_group':
      return { id: String(row.hospital_group_id ?? 'none'), label: row.hospital_group_name ?? 'No hospital group' };
    case 'hospital':
      return { id: String(row.hospital_id), label: row.hospital_name ?? 'Unknown hospital' };
    case 'doctor':
      return { id: String(row.doctor_contact_id ?? 'none'), label: row.doctor_name || 'Unassigned doctor' };
    case 'category':
      return { id: String(row.category_id ?? 'none'), label: row.category_name ?? 'Uncategorised' };
    default:
      return { id: String(row.product_id ?? 'none'), label: row.product_name ?? 'Unknown product' };
  }
}

interface UsageTotals {
  used: number;
  paid: number;
  foc: number;
  value: number;
  caseIds: number[];
}

function totalsOf(rows: MedicalCaseUsageRow[]): UsageTotals {
  const caseIds = new Set<number>();
  let used = 0;
  let paid = 0;
  let foc = 0;
  let value = 0;
  for (const row of rows) {
    used += row.used_units;
    paid += row.paid_units;
    foc += row.foc_units;
    value += row.net_value;
    row.case_ids.forEach((id) => caseIds.add(id));
  }
  return { used, paid, foc, value, caseIds: [...caseIds] };
}

interface UsageNode {
  path: string;
  label: string;
  depth: number;
  totals: UsageTotals;
  children: UsageNode[];
}

function buildUsageTree(
  rows: MedicalCaseUsageRow[],
  dimensions: UsageDimension[],
  depth = 0,
  parentPath = '',
): UsageNode[] {
  const dimension = dimensions[depth];
  if (!dimension) return [];
  const buckets = new Map<string, { label: string; rows: MedicalCaseUsageRow[] }>();
  for (const row of rows) {
    const { id, label } = dimensionOf(row, dimension);
    const bucket = buckets.get(id) ?? { label, rows: [] };
    bucket.rows.push(row);
    buckets.set(id, bucket);
  }
  return [...buckets.entries()]
    .map(([id, bucket]) => {
      const path = `${parentPath}/${dimension}:${id}`;
      return {
        path,
        label: bucket.label,
        depth,
        totals: totalsOf(bucket.rows),
        children: buildUsageTree(bucket.rows, dimensions, depth + 1, path),
      };
    })
    .sort((a, b) => b.totals.used - a.totals.used || a.label.localeCompare(b.label));
}

interface UsageFilterState {
  legal_entity: string;
  hospital_group: string;
  hospital: string;
  doctor: string;
  product: string;
  category: string;
}

const EMPTY_USAGE_FILTERS: UsageFilterState = {
  legal_entity: '',
  hospital_group: '',
  hospital: '',
  doctor: '',
  product: '',
  category: '',
};

const USAGE_FILTERS: Array<{ key: keyof UsageFilterState; label: string; all: string }> = [
  { key: 'legal_entity', label: 'Legal entity', all: 'All legal entities' },
  { key: 'hospital_group', label: 'Hospital group', all: 'All hospital groups' },
  { key: 'hospital', label: 'Hospital', all: 'All hospitals' },
  { key: 'doctor', label: 'Doctor', all: 'All doctors' },
  { key: 'product', label: 'Product', all: 'All products' },
  { key: 'category', label: 'Category', all: 'All categories' },
];

function ProductUsagePivot({
  rows,
  cases,
  phiMasked,
}: {
  rows: MedicalCaseUsageRow[];
  cases: MedicalCaseUsageCase[];
  phiMasked: boolean;
}) {
  const [levels, setLevels] = useState<UsageLevel[]>(['legal_entity', 'hospital_group', 'hospital', 'doctor']);
  const [leaf, setLeaf] = useState<UsageLeaf>('product');
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [usageFilters, setUsageFilters] = useState<UsageFilterState>(EMPTY_USAGE_FILTERS);
  const [drill, setDrill] = useState<{ label: string; caseIds: number[] } | null>(null);

  const filterOptions = useMemo(() => {
    const options = {} as Record<keyof UsageFilterState, Array<{ id: string; label: string }>>;
    for (const { key } of USAGE_FILTERS) {
      const seen = new Map<string, string>();
      for (const row of rows) {
        const { id, label } = dimensionOf(row, key);
        seen.set(id, label);
      }
      options[key] = [...seen.entries()]
        .map(([id, label]) => ({ id, label }))
        .sort((a, b) => a.label.localeCompare(b.label));
    }
    return options;
  }, [rows]);

  const filteredRows = useMemo(
    () =>
      rows.filter((row) =>
        USAGE_FILTERS.every(({ key }) => !usageFilters[key] || dimensionOf(row, key).id === usageFilters[key]),
      ),
    [rows, usageFilters],
  );

  const dimensions = useMemo<UsageDimension[]>(
    () => [...USAGE_LEVELS.map((level) => level.key).filter((key) => levels.includes(key)), leaf],
    [levels, leaf],
  );
  const tree = useMemo(() => buildUsageTree(filteredRows, dimensions), [filteredRows, dimensions]);
  const grandTotal = useMemo(() => totalsOf(filteredRows), [filteredRows]);

  const casesById = useMemo(() => new Map(cases.map((item) => [item.id, item])), [cases]);
  const drillCases = drill
    ? drill.caseIds.map((id) => casesById.get(id)).filter((item): item is MedicalCaseUsageCase => !!item)
    : [];

  const toggleLevel = (key: UsageLevel) =>
    setLevels((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]));

  const toggleNode = (path: string) =>
    setCollapsed((current) => {
      const next = new Set(current);
      if (next.has(path)) next.delete(path);
      else next.add(path);
      return next;
    });

  const allPaths = (nodes: UsageNode[]): string[] =>
    nodes.flatMap((node) => (node.children.length ? [node.path, ...allPaths(node.children)] : []));

  const renderNode = (node: UsageNode): JSX.Element[] => {
    const isOpen = !collapsed.has(node.path);
    const hasChildren = node.children.length > 0;
    const row = (
      <tr
        key={node.path}
        data-testid="usage-row"
        className={`border-b last:border-b-0 ${hasChildren ? 'bg-muted/20 font-medium' : ''}`}
      >
        <td className="px-3 py-2" style={{ paddingLeft: `${0.75 + node.depth * 1.25}rem` }}>
          {hasChildren ? (
            <button
              type="button"
              className="inline-flex items-center gap-1 text-left"
              aria-expanded={isOpen}
              onClick={() => toggleNode(node.path)}
            >
              <Icon icon={isOpen ? 'solar:alt-arrow-down-linear' : 'solar:alt-arrow-right-linear'} className="h-3.5 w-3.5" />
              {node.label}
            </button>
          ) : (
            node.label
          )}
        </td>
        <td className="px-3 py-2 text-right tabular-nums">{formatNumber(node.totals.used)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{formatNumber(node.totals.paid)}</td>
        <td className="px-3 py-2 text-right tabular-nums">{formatNumber(node.totals.foc)}</td>
        <td className="px-3 py-2 text-right tabular-nums">
          <button
            type="button"
            className="text-primary underline-offset-2 hover:underline"
            aria-label={`Show ${node.totals.caseIds.length} cases for ${node.label}`}
            onClick={() => setDrill({ label: node.label, caseIds: node.totals.caseIds })}
          >
            {formatNumber(node.totals.caseIds.length)}
          </button>
        </td>
        <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(node.totals.value)}</td>
      </tr>
    );
    return isOpen && hasChildren ? [row, ...node.children.flatMap(renderNode)] : [row];
  };

  return (
    <section aria-label="Usage pivot" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-sm font-semibold">Usage by Entity, Hospital Group, Hospital and Doctor</h3>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="text-muted-foreground">Group by</span>
          {USAGE_LEVELS.map((level) => (
            <button
              key={level.key}
              type="button"
              aria-pressed={levels.includes(level.key)}
              onClick={() => toggleLevel(level.key)}
              className={`rounded-full border px-3 py-1 ${
                levels.includes(level.key) ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'
              }`}
            >
              {level.label}
            </button>
          ))}
          <select
            aria-label="Show products or categories"
            value={leaf}
            onChange={(event) => setLeaf(event.target.value as UsageLeaf)}
            className="h-8 rounded-md border bg-background px-2"
          >
            <option value="product">× Product</option>
            <option value="category">× Category</option>
          </select>
          <button type="button" className="text-primary hover:underline" onClick={() => setCollapsed(new Set())}>
            Expand all
          </button>
          <button
            type="button"
            className="text-primary hover:underline"
            onClick={() => setCollapsed(new Set(allPaths(tree)))}
          >
            Collapse all
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-6">
        {USAGE_FILTERS.map(({ key, label, all }) => (
          key === 'product' ? (
            // #1020: the product filter is the shared server-side picker
            // (custom input search + dropdown + "Show more" catalog).
            <div key={key} className="[&>div]:h-9">
              <ProductSearchCombobox
                value={usageFilters[key]}
                onValueChange={(value) => setUsageFilters((current) => ({ ...current, [key]: value }))}
                placeholder={all}
              />
            </div>
          ) : (
            <select
              key={key}
              aria-label={`Filter by ${label.toLowerCase()}`}
              value={usageFilters[key]}
              onChange={(event) => setUsageFilters((current) => ({ ...current, [key]: event.target.value }))}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              <option value="">{all}</option>
              {filterOptions[key].map((option) => (
                <option key={option.id} value={option.id}>
                  {option.label}
                </option>
              ))}
            </select>
          )
        ))}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40">
              <th className="px-3 py-2 text-left font-medium">
                {dimensions.map((dimension) => (dimension === 'product' ? 'Product' : dimension === 'category' ? 'Category' : USAGE_LEVELS.find((level) => level.key === dimension)?.label)).join(' → ')}
              </th>
              <th className="px-3 py-2 text-right font-medium">Units Used</th>
              <th className="px-3 py-2 text-right font-medium">Paid Units</th>
              <th className="px-3 py-2 text-right font-medium">FOC Units</th>
              <th className="px-3 py-2 text-right font-medium">Cases</th>
              <th className="px-3 py-2 text-right font-medium">Net Value</th>
            </tr>
          </thead>
          <tbody>
            {tree.length === 0 ? (
              <tr>
                <td className="px-3 py-4 text-center text-muted-foreground" colSpan={6}>
                  No product usage matches these filters.
                </td>
              </tr>
            ) : (
              tree.flatMap(renderNode)
            )}
          </tbody>
          <tfoot>
            <tr data-testid="usage-grand-total" className="border-t-2 font-semibold">
              <td className="px-3 py-2">Grand total</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(grandTotal.used)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(grandTotal.paid)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(grandTotal.foc)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatNumber(grandTotal.caseIds.length)}</td>
              <td className="px-3 py-2 text-right tabular-nums">{formatCurrency(grandTotal.value)}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {drill && (
        <section aria-label="Usage cases" className="rounded-lg border p-3">
          <div className="mb-2 flex items-center justify-between gap-2">
            <h4 className="text-sm font-semibold">Cases · {drill.label}</h4>
            <button type="button" className="text-sm text-primary hover:underline" onClick={() => setDrill(null)}>
              Close
            </button>
          </div>
          {phiMasked && (
            <p className="mb-2 text-xs text-muted-foreground">
              Patient details are masked — you need the medical cases PHI permission to see them.
            </p>
          )}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/40">
                  <th className="px-3 py-2 text-left font-medium">Case</th>
                  <th className="px-3 py-2 text-left font-medium">Procedure Date</th>
                  <th className="px-3 py-2 text-left font-medium">Hospital</th>
                  <th className="px-3 py-2 text-left font-medium">Doctor</th>
                  <th className="px-3 py-2 text-left font-medium">Patient</th>
                  <th className="px-3 py-2 text-left font-medium">Status</th>
                </tr>
              </thead>
              <tbody>
                {drillCases.map((item) => (
                  <tr key={item.id} className="border-b last:border-b-0">
                    <td className="px-3 py-2">
                      <a className="text-primary hover:underline" href={`/sales/medical-cases/${item.id}`}>
                        {item.case_no ?? `#${item.id}`}
                      </a>
                    </td>
                    <td className="px-3 py-2">{item.procedure_date ?? item.case_date ?? '—'}</td>
                    <td className="px-3 py-2">{item.hospital_name ?? '—'}</td>
                    <td className="px-3 py-2">{item.doctor_name}</td>
                    <td className="px-3 py-2">{item.patient_name ?? '—'}</td>
                    <td className="px-3 py-2 capitalize">{item.status}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </section>
  );
}

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
              Medical-case units used by legal entity, hospital group, hospital, doctor and product · {periodLabel}
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

                  <div className="xl:col-span-2">
                    <ProductUsagePivot
                      rows={data.usage?.rows ?? []}
                      cases={data.usage?.cases ?? []}
                      phiMasked={data.usage?.phi_masked ?? true}
                    />
                  </div>

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
                            <td className="px-3 py-2">
                              <div className="min-w-0">
                                <div>{row.product_name ?? 'Unknown product'}</div>
                                {/* #1020: every product field renders name + short description. */}
                                {row.short_description
                                  ? <div className="text-xs text-muted-foreground">{row.short_description}</div>
                                  : null}
                              </div>
                            </td>
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
