import { useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import reportApi from '@/lib/reportApi';
import type {
  PivotDimension,
  PivotMeasure,
  PivotMeasureValues,
  PivotRowNode,
  ReportFilters as ReportFiltersType,
} from '@/types/reports';
import {
  ALL_PIVOT_DIMENSIONS,
  ALL_PIVOT_MEASURES,
  DEFAULT_PIVOT_MEASURES,
  PIVOT_DIMENSION_LABELS,
  PIVOT_MEASURE_LABELS,
  buildPivotExportRows,
  formatPivotMeasure,
  pivotNodeKey,
} from './salesPivotRows';

interface SalesPivotViewProps {
  filters: ReportFiltersType;
  periodLabel: string;
  exportRange: string;
}

interface PivotTableRowProps {
  node: PivotRowNode;
  measures: PivotMeasure[];
  expanded: Set<string>;
  onToggle: (key: string) => void;
}

/** One cell's measures, stacked with a short label when several are shown. */
function MeasureStack({
  values,
  measures,
}: {
  values: PivotMeasureValues;
  measures: PivotMeasure[];
}) {
  if (measures.length === 1) {
    return <span className="tabular-nums">{formatPivotMeasure(measures[0], values[measures[0]] ?? 0)}</span>;
  }

  return (
    <div className="space-y-0.5">
      {measures.map((measure) => (
        <div key={measure} className="whitespace-nowrap leading-tight">
          <span className="mr-1 text-[10px] uppercase tracking-wide text-muted-foreground">
            {PIVOT_MEASURE_LABELS[measure]}
          </span>
          <span className="tabular-nums">{formatPivotMeasure(measure, values[measure] ?? 0)}</span>
        </div>
      ))}
    </div>
  );
}

/**
 * One hierarchy row plus its expanded children, recursively. A node with
 * children renders an expand/collapse chevron and, while collapsed, shows the
 * aggregate of its subtree (the API already aggregates parents).
 */
function PivotTableRow({ node, measures, expanded, onToggle }: PivotTableRowProps) {
  const key = pivotNodeKey(node);
  const hasChildren = node.children.length > 0;
  const isExpanded = expanded.has(key);

  return (
    <>
      <tr className="border-b last:border-b-0">
        <td className="px-3 py-2" style={{ paddingLeft: `${12 + node.depth * 20}px` }}>
          <span className="inline-flex items-center gap-1">
            {hasChildren ? (
              <button
                type="button"
                aria-label={`${isExpanded ? 'Collapse' : 'Expand'} ${node.label}`}
                aria-expanded={isExpanded}
                onClick={() => onToggle(key)}
                className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <Icon
                  icon={isExpanded ? 'solar:alt-arrow-down-bold' : 'solar:alt-arrow-right-bold'}
                  className="h-3.5 w-3.5"
                />
              </button>
            ) : (
              <span className="inline-block h-3.5 w-3.5" aria-hidden="true" />
            )}
            <span className={hasChildren ? 'font-medium' : undefined}>{node.label}</span>
          </span>
        </td>

        {node.values.map((cell, index) => (
          <td key={index} className="px-3 py-2 text-right align-top">
            <MeasureStack values={cell} measures={measures} />
          </td>
        ))}

        <td className="px-3 py-2 text-right align-top font-semibold">
          <MeasureStack values={node.totals} measures={measures} />
        </td>
      </tr>

      {isExpanded &&
        node.children.map((child) => (
          <PivotTableRow
            key={pivotNodeKey(child)}
            node={child}
            measures={measures}
            expanded={expanded}
            onToggle={onToggle}
          />
        ))}
    </>
  );
}

/**
 * Issue #601: the Pivot tab. Row dimensions (up to three, outermost first)
 * nest into an expandable hierarchy; an optional column dimension spreads the
 * matrix; the whitelisted measures are chosen per cell. Export reuses the
 * shared XLSX/PDF/Word toolbar (`downloadReportXlsx` → the repo's existing
 * `xlsx` dependency).
 */
export function SalesPivotView({ filters, periodLabel, exportRange }: SalesPivotViewProps) {
  const pivotRef = useRef<HTMLDivElement>(null);

  const [rowDims, setRowDims] = useState<PivotDimension[]>(['product']);
  const [colDim, setColDim] = useState<PivotDimension | ''>('month');
  const [measures, setMeasures] = useState<PivotMeasure[]>(DEFAULT_PIVOT_MEASURES);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());

  const colDims = useMemo(() => (colDim ? [colDim] : []), [colDim]);

  const { data, isLoading, error } = useQuery({
    queryKey: ['sales-pivot', filters, rowDims, colDims, measures],
    queryFn: () =>
      reportApi.getSalesPivot(filters, {
        row_dims: rowDims,
        col_dims: colDims,
        measure: measures,
      }),
    staleTime: 15 * 1000,
  });

  const exportRows = useMemo(() => (data ? buildPivotExportRows(data) : []), [data]);

  const toggleExpanded = (key: string) =>
    setExpanded((previous) => {
      const next = new Set(previous);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });

  const changeRowDim = (index: number, value: PivotDimension) => {
    setExpanded(new Set());
    setRowDims((previous) => previous.map((dim, i) => (i === index ? value : dim)));
  };

  const addRowDim = () => {
    setExpanded(new Set());
    setRowDims((previous) => {
      const next = ALL_PIVOT_DIMENSIONS.find((dim) => !previous.includes(dim));

      return previous.length < 3 && next ? [...previous, next] : previous;
    });
  };

  const removeRowDim = (index: number) => {
    setExpanded(new Set());
    setRowDims((previous) => (previous.length > 1 ? previous.filter((_, i) => i !== index) : previous));
  };

  const toggleMeasure = (measure: PivotMeasure) => {
    setMeasures((previous) => {
      if (previous.includes(measure)) {
        return previous.length > 1 ? previous.filter((value) => value !== measure) : previous;
      }

      return ALL_PIVOT_MEASURES.filter((value) => previous.includes(value) || value === measure);
    });
  };

  const columns = data?.columns ?? [];
  const rowDimHeading = rowDims.map((dimension) => PIVOT_DIMENSION_LABELS[dimension]).join(' / ');

  return (
    <div className="space-y-6">
      <Card className="rounded-xl p-4">
        <div className="flex flex-wrap items-end gap-4">
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-muted-foreground">Rows</Label>
            <div className="flex flex-wrap items-center gap-2">
              {rowDims.map((dimension, index) => (
                <span key={`${dimension}-${index}`} className="flex items-center gap-1">
                  <select
                    aria-label={`Row dimension ${index + 1}`}
                    value={dimension}
                    onChange={(event) => changeRowDim(index, event.target.value as PivotDimension)}
                    className="h-9 rounded-full border border-input bg-background px-3 text-sm"
                  >
                    {ALL_PIVOT_DIMENSIONS.map((option) => (
                      <option
                        key={option}
                        value={option}
                        disabled={rowDims.includes(option) && option !== dimension}
                      >
                        {PIVOT_DIMENSION_LABELS[option]}
                      </option>
                    ))}
                  </select>
                  {rowDims.length > 1 && (
                    <button
                      type="button"
                      aria-label={`Remove row dimension ${index + 1}`}
                      onClick={() => removeRowDim(index)}
                      className="rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                      <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                    </button>
                  )}
                </span>
              ))}
              {rowDims.length < 3 && (
                <button
                  type="button"
                  onClick={addRowDim}
                  className="inline-flex h-9 items-center gap-1 rounded-full border border-dashed px-3 text-sm text-muted-foreground hover:bg-muted"
                >
                  <Icon icon="solar:add-circle-bold" className="h-4 w-4" />
                  Add row
                </button>
              )}
            </div>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-muted-foreground">Columns</Label>
            <select
              aria-label="Column dimension"
              value={colDim}
              onChange={(event) => {
                setExpanded(new Set());
                setColDim(event.target.value as PivotDimension | '');
              }}
              className="h-9 rounded-full border border-input bg-background px-3 text-sm"
            >
              <option value="">None</option>
              {ALL_PIVOT_DIMENSIONS.map((dimension) => (
                <option key={dimension} value={dimension}>
                  {PIVOT_DIMENSION_LABELS[dimension]}
                </option>
              ))}
            </select>
          </div>

          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-muted-foreground">Measures</Label>
            <div className="flex flex-wrap gap-3 pb-1">
              {ALL_PIVOT_MEASURES.map((measure) => (
                <label key={measure} className="inline-flex items-center gap-1.5 text-sm">
                  <input
                    type="checkbox"
                    aria-label={PIVOT_MEASURE_LABELS[measure]}
                    checked={measures.includes(measure)}
                    onChange={() => toggleMeasure(measure)}
                    className="h-4 w-4 rounded border-input"
                  />
                  {PIVOT_MEASURE_LABELS[measure]}
                </label>
              ))}
            </div>
          </div>
        </div>
      </Card>

      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Sales Analysis Pivot</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {data ? `${data.summary.row_count} grouped row(s) · ${data.summary.column_count} column(s)` : periodLabel}
            </p>
          </div>
          <ReportExportToolbar
            targetRef={pivotRef}
            title="Sales Analysis"
            subtitle={`For the period ${periodLabel}`}
            filename={`sales-pivot-${exportRange}`}
            rows={exportRows}
          />
        </CardHeader>

        <CardContent className="pt-6" ref={pivotRef}>
          {isLoading && (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="svg-spinners:ring-resize" className="mx-auto h-8 w-8" />
              <p className="mt-2">Loading pivot…</p>
            </div>
          )}

          {!isLoading && error && (
            <div className="py-12 text-center text-destructive">
              <p className="font-medium">Failed to load the pivot</p>
              <p className="mt-1 text-sm">{(error as Error).message}</p>
            </div>
          )}

          {!isLoading && !error && columns.length === 0 && (
            <div className="py-12 text-center text-muted-foreground">
              <p>No data for the selected period.</p>
            </div>
          )}

          {!isLoading && !error && columns.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/40">
                    <th className="px-3 py-2 text-left font-medium">{rowDimHeading || 'Rows'}</th>
                    {columns.map((column, index) => (
                      <th key={index} className="whitespace-nowrap px-3 py-2 text-right font-medium">
                        {column.label}
                      </th>
                    ))}
                    <th className="px-3 py-2 text-right font-medium">Total</th>
                  </tr>
                </thead>
                <tbody>
                  {(data?.rows ?? []).map((node) => (
                    <PivotTableRow
                      key={pivotNodeKey(node)}
                      node={node}
                      measures={measures}
                      expanded={expanded}
                      onToggle={toggleExpanded}
                    />
                  ))}
                  <tr className="border-t-2 font-semibold">
                    <td className="px-3 py-2">Total</td>
                    {columns.map((_, index) => (
                      <td key={index} className="px-3 py-2 text-right align-top">
                        <MeasureStack
                          values={measures.reduce(
                            (totals, measure) => ({
                              ...totals,
                              [measure]: (data?.rows ?? []).reduce(
                                (sum, node) => sum + (node.values[index]?.[measure] ?? 0),
                                0,
                              ),
                            }),
                            {} as PivotMeasureValues,
                          )}
                          measures={measures}
                        />
                      </td>
                    ))}
                    {data && (
                      <td className="px-3 py-2 text-right align-top">
                        <MeasureStack values={data.totals} measures={measures} />
                      </td>
                    )}
                  </tr>
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default SalesPivotView;
