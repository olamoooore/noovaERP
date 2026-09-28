// Storefront editor integration (MRD ecommerce-Enterprice, Appendix B §B4,
// P0 Sprint 4 spike). Everything here is GENERATED from the backend
// shortcode registry (GET /website-builder/storefront/blocks) — the editor
// never duplicates function definitions (FR-BLD-03). If the tenant is not
// entitled for `storefront` the endpoint 403s and the Store group simply
// never appears: installed-but-unentitled degrades silently (FR-MOD-04).
//
// Serialization contract (B4.1): saved components are
//   <div data-storefront-function="storefront.products" data-attrs='{...}'>
//     [storefront.products category="mugs" limit="8"]
//   </div>
// The LITERAL TOKEN is what the server renderer consumes; `data-attrs` is an
// editor convenience with a token-text fallback.

import type { Editor } from 'grapesjs'
import api from '@/lib/api'
import {
  buildToken,
  coerceValues,
  defaultValues,
  parseTokenText,
  resolveComponentValues,
  type SfAttributeSchema,
  type SfFunctionSchema,
  type SfValues,
} from './storefrontShortcode'

const ATTRIBUTE_NAME = 'data-storefront-function'

const STORE_MEDIA =
  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 7h12l1.5 13.5a1 1 0 0 1-1 1.1H5.5a1 1 0 0 1-1-1.1L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>'

/** Property prefix for changeProp-bound traits (never touches the DOM). */
const prop = (attrName: string) => `sf-${attrName}`

const FN_PROP = 'sf-function'
const ATTRS_PROP = 'sf-attrs'

export async function setupStorefrontBlocks(editor: Editor): Promise<void> {
  const byKey = new Map<string, SfFunctionSchema>()

  // Register the TYPE synchronously so components parsed from existing page
  // content are adopted before the async registry fetch resolves. Until the
  // schemas arrive, model init defers; refreshExisting() re-hydrates after.
  registerShortcodeType(editor, byKey)

  let schemas: SfFunctionSchema[] = []

  try {
    const { data } = await api.get('/website-builder/storefront/blocks')
    schemas = (data?.data?.functions ?? []) as SfFunctionSchema[]
  } catch {
    // Not entitled / module disabled / network — no Store group today.
    return
  }

  if (!schemas.length) return

  schemas.forEach((s) => byKey.set(s.key, s))

  registerStoreBlocks(editor, schemas)
  registerCanvasDecoration(editor)
  refreshExisting(editor)
}

/** Re-run prop restoration for wrappers parsed before schemas were known. */
function refreshExisting(editor: Editor) {
  const walk = (components: any[]) => {
    components.forEach((comp: any) => {
      if (comp?.attributes?.[ATTRIBUTE_NAME] && typeof comp.restoreProps === 'function') {
        comp.restoreProps()
      }
      if (comp?.components && comp.components().length) walk(comp.components().models ?? comp.components().toArray?.() ?? [])
    })
  }
  walk(editor.getComponents().models ?? [])
}

// ─── Component type ───────────────────────────────────────────────────────

function registerShortcodeType(editor: Editor, byKey: Map<string, SfFunctionSchema>) {
  editor.DomComponents.addType('storefront-shortcode', {
    isComponent: (el) => {
      if (el.getAttribute && el.getAttribute(ATTRIBUTE_NAME)) {
        return { type: 'storefront-shortcode' }
      }
      return false
    },
    model: {
      defaults: {
        tagName: 'div',
        name: 'Storefront Function',
        droppable: false,
        resizable: false,
        editable: false,
        stylable: ['margin', 'padding', 'background-color', 'border-radius'],
        attributes: { [ATTRIBUTE_NAME]: 'storefront.products' },
        [FN_PROP]: 'storefront.products',
        [ATTRS_PROP]: {},
        content: '',
      },

      init(this: any) {
        // Schemas may not have arrived yet (type registers synchronously,
        // the registry fetch is async). refreshExisting() re-runs restore
        // once byKey is populated, so deferring here is safe.
        if (!byKey.size) return

        // Initialize model props from whatever is on the element/token.
        this.restoreProps()

        this.on(`change:${FN_PROP}`, () => {
          this.onFunctionChanged()
        })

        // Attribute props are dynamic per function; listen broadly and
        // regenerate only for our own prefix.
        this.on('change', (model: any, opts: any) => {
          if (opts && opts.sfInternal) return
          if (!byKey.size) return
          const changed = model.changedAttributes() || {}
          const ours = Object.keys(changed).some((k) => k.startsWith('sf-') && k !== FN_PROP && k !== ATTRS_PROP)
          if (ours) this.regenerate()
        })
      },

      schema(this: any): SfFunctionSchema | null {
        return byKey.get(this.get(FN_PROP)) ?? null
      },

      restoreProps(this: any) {
        const attrs = this.getAttributes() || {}
        let fn = attrs[ATTRIBUTE_NAME] as string | undefined
        if (!fn || !byKey.has(fn)) {
          // Hand-typed/corrupted wrapper: recover from the token text.
          const parsed = parseTokenText(String(this.get('content') ?? ''))
          fn = parsed && byKey.has(parsed.key) ? parsed.key : byKey.keys().next().value
        }

        const schema = fn ? byKey.get(fn) : undefined
        if (!schema) return

        const values = resolveComponentValues(String(this.get('content') ?? ''), attrs['data-attrs'], schema)

        this.set({ [FN_PROP]: schema.key, [ATTRS_PROP]: values }, { sfInternal: true, silent: false })
        this.applyTraits(schema)
        this.regenerate(schema, values)
      },

      onFunctionChanged(this: any) {
        const schema = this.schema()
        if (!schema) return
        const values = defaultValues(schema)
        this.set({ [ATTRS_PROP]: values }, { sfInternal: true })
        this.applyTraits(schema)
        this.regenerate(schema, values)
      },

      /** Generated settings panel (FR-BLD-02): schema → trait controls. */
      applyTraits(this: any, schema: SfFunctionSchema) {
        const traits: any[] = [
          {
            type: 'select',
            label: 'Storefront function',
            name: FN_PROP,
            changeProp: 1,
            options: [...byKey.values()].map((f) => ({ id: f.key, label: f.label, value: f.key })),
          },
        ]

        for (const attr of schema.attributes) {
          traits.push(traitFor(attr))
        }

        this.set('traits', traits, { sfInternal: true })
      },

      regenerate(this: any, schema?: SfFunctionSchema | null, valuesIn?: SfValues) {
        schema = schema === undefined ? this.schema() : schema;
        if (!schema) return
        const values = coerceValues(schema, (valuesIn ?? this.get(ATTRS_PROP) ?? {}) as SfValues)

        // Sync each typed attribute into its own prop so traits reflect it.
        const patch: Record<string, unknown> = {}
        for (const attr of schema.attributes) {
          patch[prop(attr.name)] = values[attr.name]
        }

        const token = buildToken(schema.key, schema, values)

        this.set(
          {
            content: token,
            [ATTRS_PROP]: values,
            attributes: {
              ...(this.get('attributes') || {}),
              [ATTRIBUTE_NAME]: schema.key,
              'data-attrs': JSON.stringify(values),
            },
            ...patch,
          },
          { sfInternal: true },
        )
      },
    },
  })

  // Re-bind per-attribute listeners when traits (schema) change: Backbone
  // `change:sf-xxx` events flow through the broad 'change' handler above,
  // so no extra per-trait bindings are required — regenerate() covers all.
}

function traitFor(attr: SfAttributeSchema) {
  const base = {
    type: 'text',
    label: attr.help ? `${attr.name} — ${attr.help}` : attr.name,
    name: prop(attr.name),
    changeProp: 1,
  }

  switch (attr.kind) {
    case 'bool':
      return { ...base, type: 'checkbox', label: attr.name }
    case 'int':
      return {
        ...base,
        type: 'number',
        label: attr.name,
        min: attr.min ?? undefined,
        max: attr.max ?? undefined,
      }
    case 'enum':
      return {
        ...base,
        type: 'select',
        label: attr.name,
        options: (attr.enum ?? []).map((v) => ({ id: String(v), label: String(v), value: String(v) })),
      }
    default:
      return base
  }
}

// ─── Blocks (Store group, registry-generated) ─────────────────────────────

function registerStoreBlocks(editor: Editor, schemas: SfFunctionSchema[]) {
  const blockManager = editor.BlockManager

  for (const schema of schemas) {
    const token = buildToken(schema.key, schema, defaultValues(schema))

    blockManager.add(`sf-${schema.key.replace(/\./g, '-')}`, {
      label: schema.label,
      category: 'Store',
      media: STORE_MEDIA,
      content: `<div ${ATTRIBUTE_NAME}="${schema.key}" data-attrs='${JSON.stringify(defaultValues(schema))}'>${token}</div>`,
    })
  }
}

// ─── Canvas decoration (editor-only, never exported) ──────────────────────

function registerCanvasDecoration(editor: Editor) {
  const styleId = 'sf-editor-style'

  const paint = () => {
    const doc = editor.Canvas.getDocument()
    if (!doc || doc.getElementById(styleId)) return

    const el = doc.createElement('style')
    el.id = styleId
    el.textContent = `
      [${ATTRIBUTE_NAME}] {
        outline: 2px dashed #7c3aed !important;
        outline-offset: -2px;
        padding: 10px 12px;
        min-height: 2.25rem;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        color: #4c1d95;
        background: rgba(124, 58, 237, 0.06);
        border-radius: 6px;
      }
      [${ATTRIBUTE_NAME}]:before {
        content: 'Storefront · ' attr(${ATTRIBUTE_NAME});
        display: block;
        font-weight: 600;
        letter-spacing: .02em;
        margin-bottom: 4px;
        opacity: .8;
      }
    `
    doc.head?.appendChild(el)
  }

  editor.on('canvas:load', paint)
  paint()
}
