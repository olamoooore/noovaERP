/**
 * #747 (TU1): pure helpers for the Tax Packs review/upgrade screen.
 *
 * The backend (#739) reports, per pack rule: new / changed (field-level diff)
 * / tenant_modified / removed_from_pack, plus the pack version vs the version
 * the tenant installed. Keeping the row/chip shaping here (no rate computation)
 * means the screen is a thin renderer and the mapping is unit-testable.
 */

export type PackRuleState = 'new' | 'changed' | 'tenant_modified' | 'removed_from_pack' | 'ok';

export interface PackFieldChange {
  from: unknown;
  to: unknown;
}

export interface PackRuleEntry {
  code: string;
  state: PackRuleState;
  changes?: Record<string, PackFieldChange>;
  tenant_changes?: Record<string, PackFieldChange>;
  untracked?: boolean;
}

export interface PackInfo {
  label: string;
  currency: string | null;
  activated: boolean;
  partial: boolean;
  present: string[];
  missing: string[];
  total_pack_rules: number;
  version?: number;
  installed_version?: number | null;
  up_to_date?: boolean;
  rules?: PackRuleEntry[];
  new?: string[];
  changed?: string[];
  tenant_modified?: string[];
  removed_from_pack?: string[];
}

export interface PackChangeRow {
  code: string;
  field: string;
  from: unknown;
  to: unknown;
  kind: 'pack' | 'tenant';
  /** Applying a pack change to a rule in use end-dates it and versions it. */
  willVersion: boolean;
}

function flatten(
  code: string,
  changes: Record<string, PackFieldChange> | undefined,
  kind: 'pack' | 'tenant',
): PackChangeRow[] {
  return Object.entries(changes ?? {}).map(([field, change]) => ({
    code,
    field,
    from: change?.from ?? null,
    to: change?.to ?? null,
    kind,
    willVersion: kind === 'pack',
  }));
}

/** All field-level rows for a pack, tenant edits first so they are unmissable. */
export function packChangeRows(info: Pick<PackInfo, 'rules'>): PackChangeRow[] {
  const rows: PackChangeRow[] = [];

  for (const rule of info.rules ?? []) {
    rows.push(...flatten(rule.code, rule.tenant_changes, 'tenant'));
  }
  for (const rule of info.rules ?? []) {
    rows.push(...flatten(rule.code, rule.changes, 'pack'));
  }

  return rows;
}

/** Codes the user may apply an upgrade to. Tenant-edited rules are excluded. */
export function selectableCodes(info: PackInfo): string[] {
  const explicit = info.changed ?? [];
  const added = info.new ?? [];
  const removed = info.removed_from_pack ?? [];
  const tenantModified = new Set(info.tenant_modified ?? []);

  return [...new Set([...added, ...explicit, ...removed])].filter((code) => !tenantModified.has(code));
}

export type PackChipTone = 'ok' | 'update' | 'modified';

export function packStatusChip(info: PackInfo): { label: string; tone: PackChipTone } {
  if (info.up_to_date) {
    return { label: 'Up to date', tone: 'ok' };
  }
  if ((info.tenant_modified ?? []).length > 0) {
    return { label: 'Modified', tone: 'modified' };
  }
  if (!info.activated || info.partial) {
    return { label: 'Not activated', tone: 'update' };
  }

  return { label: 'Update available', tone: 'update' };
}

export function formatPackValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }
  if (typeof value === 'boolean') {
    return value ? 'yes' : 'no';
  }

  return String(value);
}