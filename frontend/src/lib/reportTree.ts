import type { GeneralLedgerData } from '@/types/reports';

/**
 * Collapse/expand helpers for report hierarchies, shared by the table
 * components and the report pages that render the Expand/Collapse controls in
 * the toolbar row (next to Excel/PDF/Word).
 */

interface TreeNodeLike {
  id: string;
  children?: TreeNodeLike[];
}

/** Ids of every expandable node (has children) — the "collapse all" set. */
export function collectExpandableIds<T extends TreeNodeLike>(roots: T[]): string[] {
  const ids: string[] = [];
  const walk = (nodes: T[]) => {
    for (const node of nodes) {
      if (node.children?.length) {
        ids.push(node.id);
        walk(node.children as T[]);
      }
    }
  };
  walk(roots);
  return ids;
}

type LedgerHierarchy = NonNullable<GeneralLedgerData['hierarchy']>;

/**
 * Every collapsible ledger key (type → group → account → fiscal year →
 * month). Mirrors the key construction inside `GeneralLedgerHierarchy` —
 * keep the two in sync.
 */
export function ledgerCollapseKeys(hierarchy: LedgerHierarchy): string[] {
  const keys: string[] = [];

  hierarchy.types.forEach((type) => {
    if (!type.groups.length) return;
    const typeKey = `type:${type.type}`;
    keys.push(typeKey);

    type.groups.forEach((group) => {
      const groupKey = `${typeKey}:group:${group.code || group.name}`;
      if (group.accounts.length) keys.push(groupKey);

      group.accounts.forEach((account) => {
        const accountKey = `${groupKey}:account:${account.id}`;
        if (account.fiscal_years.length) keys.push(accountKey);

        account.fiscal_years.forEach((year) => {
          const yearKey = `${accountKey}:fy:${year.year}`;
          if (year.months.length) keys.push(yearKey);

          year.months.forEach((month) => {
            if (month.transactions.length) {
              keys.push(`${yearKey}:month:${month.key}`);
            }
          });
        });
      });
    });
  });

  return keys;
}