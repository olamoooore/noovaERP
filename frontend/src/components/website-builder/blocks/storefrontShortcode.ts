// Pure helpers for Storefront shortcode components (backend registry →
// editor serialization). Kept free of GrapesJS so token/attr round-tripping
// is unit-testable and the literal `[storefront.*]` token stays the source
// of truth for the server renderer (Appendix B §B4.1).

export interface SfAttributeSchema {
  name: string
  kind: 'string' | 'int' | 'bool' | 'enum' | 'list' | 'slug'
  default?: unknown
  enum?: (string | number)[] | null
  min?: number | null
  max?: number | null
  max_length?: number | null
  help?: string | null
}

export interface SfFunctionSchema {
  key: string
  label: string
  icon?: string
  category?: string
  tier?: 'A' | 'B'
  cacheable?: boolean
  version?: string
  help?: string
  example?: string
  attributes: SfAttributeSchema[]
}

export type SfValues = Record<string, string | number | boolean>

/**
 * Serialize a token from schema + current values. Values equal to the
 * attribute default are omitted; `bool true` renders as a bare flag; lists
 * join to comma-separated strings. Deterministic order = schema order, so
 * the same settings always produce the same token (stable diffs, cache keys).
 */
export function buildToken(key: string, schema: SfFunctionSchema, values: SfValues): string {
  const parts: string[] = []

  for (const attr of schema.attributes) {
    const value = values[attr.name]
    if (value === undefined) continue

    if (attr.kind === 'bool') {
      const truthy = value === true || value === 'true' || value === 1 || value === '1'
      const defaultTruthy = attr.default === true
      if (truthy === defaultTruthy) continue
      // Bare flag expresses `true`; a true-default switched off must
      // serialize explicitly (the backend coercion reads the value string).
      parts.push(truthy ? attr.name : `${attr.name}="false"`)
      continue
    }

    if (attr.kind === 'list') {
      const listStr = String(value ?? '')
      if (listStr !== '') parts.push(`${attr.name}="${listStr}"`)
      continue
    }

    const asString = String(value)
    if (attr.default !== undefined && attr.default !== null && String(attr.default) === asString) continue
    if (asString === '') continue

    parts.push(`${attr.name}="${escapeTokenValue(asString)}"`)
  }

  return `[${key}${parts.length ? ' ' + parts.join(' ') : ''}]`
}

/** Token text inside an HTML text node: only & < > can be lost on export. */
function escapeTokenValue(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/**
 * Recovery parser: read raw attributes from a literal token when the
 * editor convenience JSON (`data-attrs`) is missing or corrupt. Mirrors the
 * backend grammar loosely; unknown values fall back to schema defaults.
 */
export function parseTokenText(tokenText: string): { key: string; raw: SfValues } | null {
  const match = tokenText.match(/\[(storefront\.[a-z0-9_]+(?:\.[a-z0-9_]+)?)((?:\s+[a-zA-Z0-9_]+(?:=(?:"[^"]*"|'[^']*'|[^\s\]]+))?)*)\s*\]/)
  if (!match) return null

  const raw: SfValues = {}
  const attrRe = /([a-zA-Z0-9_]+)(?:=(?:"([^"]*)"|'([^']*)'|([^\s\]]+)))?/g
  let m: RegExpExecArray | null
  while ((m = attrRe.exec(match[2] ?? '')) !== null) {
    const value = m[2] ?? m[3] ?? m[4] ?? true
    raw[m[1]] = typeof value === 'string' ? unescapeTokenValue(value) : value
  }

  return { key: match[1], raw }
}

function unescapeTokenValue(value: string): string {
  return value.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
}

/**
 * Coerce editor values to typed values using the schema (mirrors backend
 * Attribute::coerce loosely — enough that the token always carries safe,
 * bounded values; the SERVER still re-validates every render).
 */
export function coerceValues(schema: SfFunctionSchema, raw: SfValues): SfValues {
  const out: SfValues = {}

  for (const attr of schema.attributes) {
    const value = raw[attr.name]

    switch (attr.kind) {
      case 'bool':
        out[attr.name] = value === true || value === 'true' || value === 1 || value === '1'
        break
      case 'int': {
        const n = parseInt(String(value ?? ''), 10)
        const fallback = Number(attr.default ?? 0)
        let v = Number.isFinite(n) ? n : fallback
        if (attr.min != null) v = Math.max(attr.min, v)
        if (attr.max != null) v = Math.min(attr.max, v)
        out[attr.name] = v
        break
      }
      case 'enum': {
        const s = String(value ?? '')
        const allowed = (attr.enum ?? []).map(String)
        out[attr.name] = allowed.includes(s) ? s : String(attr.default ?? allowed[0] ?? '')
        break
      }
      case 'list': {
        const items = String(value ?? '')
          .split(',')
          .map((s) => s.trim())
          .filter((s) => /^\d+$/.test(s))
          .slice(0, attr.max ?? 100)
        out[attr.name] = items.join(',')
        break
      }
      default: {
        let s = String(value ?? '')
        if (attr.max_length) s = s.slice(0, attr.max_length)
        out[attr.name] = s
      }
    }
  }

  return out
}

/** Schema default values (typed), used when initializing a fresh block. */
export function defaultValues(schema: SfFunctionSchema): SfValues {
  const out: SfValues = {}
  for (const attr of schema.attributes) {
    out[attr.name] = (attr.default ?? (attr.kind === 'bool' ? false : attr.kind === 'int' ? 0 : '')) as SfValues[string]
  }
  return out
}

/**
 * Read current values from a component: prefer the editor-convenience
 * `data-attrs` JSON, fall back to parsing the literal token text, then to
 * schema defaults. Returns values merged over defaults so every trait has
 * a starting value.
 */
export function resolveComponentValues(
  tokenContent: string,
  dataAttrsJson: string | null | undefined,
  schema: SfFunctionSchema,
): SfValues {
  const base = defaultValues(schema)

  if (dataAttrsJson) {
    try {
      const parsed = JSON.parse(dataAttrsJson) as Record<string, unknown>
      const raw: SfValues = {}
      Object.entries(parsed).forEach(([k, v]) => {
        if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') raw[k] = v
      })
      return coerceValues(schema, { ...base, ...raw })
    } catch {
      // fall through to token parsing
    }
  }

  const parsed = parseTokenText(tokenContent ?? '')
  if (parsed) {
    return coerceValues(schema, { ...base, ...parsed.raw })
  }

  return base
}
