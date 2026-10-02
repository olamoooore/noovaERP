import { describe, expect, it } from 'vitest';
import { packChangeRows, packStatusChip, selectableCodes, formatPackValue, type PackInfo } from './taxPacks';

const base: PackInfo = {
  label: 'Singapore GST (IRAS)',
  currency: 'SGD',
  activated: true,
  partial: false,
  present: ['SG-GST-9'],
  missing: [],
  total_pack_rules: 5,
  version: 2,
  installed_version: 2,
  up_to_date: true,
  rules: [{ code: 'SG-GST-9', state: 'ok' }],
  new: [],
  changed: [],
  tenant_modified: [],
  removed_from_pack: [],
};

describe('#747 TU1 — pack review/upgrade helpers', () => {
  it('reports "Up to date" when nothing differs', () => {
    expect(packStatusChip(base)).toEqual({ label: 'Up to date', tone: 'ok' });
  });

  it('reports "Update available" for a changed rule', () => {
    const info: PackInfo = { ...base, up_to_date: false, changed: ['SG-GST-9'] };
    expect(packStatusChip(info)).toEqual({ label: 'Update available', tone: 'update' });
  });

  it('reports "Modified" when the tenant edited a rule', () => {
    const info: PackInfo = { ...base, up_to_date: false, tenant_modified: ['SG-GST-9'] };
    expect(packStatusChip(info)).toEqual({ label: 'Modified', tone: 'modified' });
  });

  it('flattens field-level diffs, tenant edits first, and marks pack changes as versioning', () => {
    const info: PackInfo = {
      ...base,
      up_to_date: false,
      rules: [
        {
          code: 'SG-GST-9',
          state: 'tenant_modified',
          tenant_changes: { rate: { from: 9, to: 8.25 } },
          changes: { effective_to: { from: null, to: '2025-12-31' } },
        },
      ],
      changed: ['SG-GST-9'],
      tenant_modified: ['SG-GST-9'],
    };

    const rows = packChangeRows(info);
    expect(rows).toEqual([
      { code: 'SG-GST-9', field: 'rate', from: 9, to: 8.25, kind: 'tenant', willVersion: false },
      { code: 'SG-GST-9', field: 'effective_to', from: null, to: '2025-12-31', kind: 'pack', willVersion: true },
    ]);
  });

  it('never offers a tenant-edited rule for upgrade', () => {
    const info: PackInfo = {
      ...base,
      up_to_date: false,
      new: ['SG-GST-10'],
      changed: ['SG-GST-9'],
      tenant_modified: ['SG-GST-9'],
      removed_from_pack: ['SG-GST-7'],
    };

    expect(selectableCodes(info)).toEqual(['SG-GST-10', 'SG-GST-7']);
  });

  it('formats values for the diff table', () => {
    expect(formatPackValue(null)).toBe('—');
    expect(formatPackValue(9)).toBe('9');
    expect(formatPackValue(true)).toBe('yes');
  });
});