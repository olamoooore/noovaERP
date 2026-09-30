import React, { useMemo, useState } from 'react';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { collectExpandableIds } from '@/lib/reportTree';

export interface ReportTreeNode {
  id: string;
  code?: string;
  name: string;
  /** Balance/activity for the primary (current) period or as-of date. */
  current: number;
  /** Balance/activity for the comparison period; undefined when not comparing. */
  compare?: number;
  /** True for non-expandable summary rows (Gross Profit, Total Assets, …). */
  summary?: boolean;
  children?: ReportTreeNode[];
}

interface ComparisonHierarchyTableProps {
  roots: ReportTreeNode[];
  compareEnabled: boolean;
  currentLabel: string;
  compareLabel?: string;
  formatMoney: (value: number) => string;
  /**
   * Controlled collapse state. When both are provided the table renders no
   * header controls of its own — the caller renders Expand/Collapse in the
   * report header row (next to Excel/PDF/Word).
   */
  collapsed?: Set<string>;
  onCollapsedChange?: (next: Set<string>) => void;
}


function variance(current: number, compare?: number) {
  if (compare === undefined) return null;
  const diff = current - compare;
  const pct = compare !== 0 ? (diff / Math.abs(compare)) * 100 : null;
  return { diff, pct };
}

const VarCell: React.FC<{ current: number; compare?: number; formatMoney: (v: number) => string }> = ({
  current,
  compare,
  formatMoney,
}) => {
  const v = variance(current, compare);
  if (!v) return null;
  const zeroish = Math.abs(v.diff) < 0.005;
  const color = zeroish ? 'text-muted-foreground' : v.diff > 0 ? 'text-emerald-700' : 'text-red-600';
  return (
    <>
      <TableCell className={`text-right font-mono text-sm ${color}`}>
        {zeroish ? '—' : `${v.diff > 0 ? '+' : ''}${formatMoney(v.diff)}`}
      </TableCell>
      <TableCell className={`text-right font-mono text-sm ${color}`}>
        {v.pct === null ? '—' : zeroish ? '—' : `${v.pct > 0 ? '+' : ''}${v.pct.toFixed(1)}%`}
      </TableCell>
    </>
  );
};

/**
 * Financial-statement tree with collapse/expand arrows per expandable row
 * (L1 sections -> L2 account groups -> L3 accounts) and optional
 * comparison columns (Current | Compare | Variance | Var %).
 */
export function ComparisonHierarchyTable({
  roots,
  compareEnabled,
  currentLabel,
  compareLabel,
  formatMoney,
  collapsed: controlledCollapsed,
  onCollapsedChange,
}: ComparisonHierarchyTableProps) {
  // Row ids that are COLLAPSED — everything starts expanded. Controlled by
  // the parent when it renders the header controls (report toolbar row).
  const [internalCollapsed, setInternalCollapsed] = useState<Set<string>>(new Set());
  const isControlled = controlledCollapsed !== undefined && onCollapsedChange !== undefined;
  const collapsed = isControlled ? controlledCollapsed : internalCollapsed;
  const setCollapsed = isControlled ? onCollapsedChange : setInternalCollapsed;

  const toggle = (id: string) => {
    const next = new Set(collapsed);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setCollapsed(next);
  };

  const expandableIds = useMemo(() => collectExpandableIds(roots), [roots]);

  const allCollapsed = expandableIds.length > 0 && expandableIds.every((id) => collapsed.has(id));

  const renderRow = (node: ReportTreeNode, depth: number) => {
    const hasChildren = !!node.children?.length;
    const isCollapsed = collapsed.has(node.id);

    const rowClass =
      depth === 0
        ? node.summary
          ? 'border-t-2 bg-muted font-bold'
          : 'bg-purple-50 border-l-4 border-l-purple-500 font-bold'
        : depth === 1
          ? 'bg-blue-50 border-l-4 border-l-blue-400 font-semibold'
          : 'hover:bg-gray-50';

    return (
      <React.Fragment key={node.id}>
        <TableRow className={rowClass}>
          <TableCell>
            <div
              className={`flex items-center gap-2 ${depth === 2 ? 'pl-8' : depth === 1 ? 'pl-2' : ''}`}
            >
              {hasChildren ? (
                <button
                  type="button"
                  aria-label={isCollapsed ? `Expand ${node.name}` : `Collapse ${node.name}`}
                  className="p-0.5 rounded hover:bg-black/5 text-muted-foreground shrink-0"
                  onClick={() => toggle(node.id)}
                >
                  <Icon
                    icon={isCollapsed ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'}
                    className="w-4 h-4"
                  />
                </button>
              ) : (
                <span className="w-5 shrink-0" />
              )}
              {depth === 0 && !node.summary && <Badge className="bg-purple-100 text-purple-800 font-bold text-xs">L1</Badge>}
              {depth === 1 && <Badge className="bg-blue-100 text-blue-800 text-xs">L2</Badge>}
              {depth >= 2 && <Badge className="bg-gray-100 text-gray-600 text-xs">L3</Badge>}
              {node.code && <span className="font-mono text-sm">{node.code}</span>}
              <span className={node.summary ? 'font-bold' : ''}>{node.name}</span>
            </div>
          </TableCell>
          <TableCell className="text-right font-mono">{formatMoney(node.current)}</TableCell>
          {compareEnabled && (
            <TableCell className="text-right font-mono text-muted-foreground">
              {formatMoney(node.compare ?? 0)}
            </TableCell>
          )}
          {compareEnabled && <VarCell current={node.current} compare={node.compare} formatMoney={formatMoney} />}
        </TableRow>
        {hasChildren && !isCollapsed && node.children!.map((child) => renderRow(child, depth + 1))}
      </React.Fragment>
    );
  };

  return (
    <div className="space-y-2">
      {!isControlled && expandableIds.length > 0 && (
        <div className="flex justify-end gap-2">
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs"
            onClick={() => setCollapsed(new Set())}
          >
            <Icon icon="solar:maximize-linear" className="w-3.5 h-3.5 mr-1" />
            Expand all
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs"
            onClick={() => setCollapsed(allCollapsed ? new Set() : new Set(expandableIds))}
          >
            <Icon icon="solar:minimize-linear" className="w-3.5 h-3.5 mr-1" />
            Collapse all
          </Button>
        </div>
      )}
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-2/5">Account</TableHead>
            <TableHead className="text-right">{currentLabel}</TableHead>
            {compareEnabled && <TableHead className="text-right">{compareLabel ?? 'Comparison'}</TableHead>}
            {compareEnabled && <TableHead className="text-right">Variance</TableHead>}
            {compareEnabled && <TableHead className="text-right">Var %</TableHead>}
          </TableRow>
        </TableHeader>
        <TableBody>
          {roots.map((node) => renderRow(node, 0))}
          {roots.length === 0 && (
            <TableRow>
              <TableCell colSpan={compareEnabled ? 5 : 2} className="text-center text-muted-foreground py-8">
                No data available
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
  );
}
