/**
 * One-off codemod: make the search/filter controls that sit in the same action
 * row as a HeaderActionButton use the same compact pill geometry (h-8,
 * rounded-full, 12px) as the buttons beside them.
 *
 * Proximity matching is not good enough — a dialog's form field can be a few
 * lines from a header button — so this walks the real JSX tree: it only touches
 * Input / SelectTrigger elements that are DIRECT children of the row container
 * that also holds a HeaderActionButton.
 *
 *   node scripts/round-filter-controls.mjs [--apply]
 */
import ts from 'typescript'
import { readFileSync, writeFileSync } from 'node:fs'
import { globSync } from 'node:fs'

const files = globSync('src/pages/**/*.tsx')
const apply = process.argv.includes('--apply')

const targets = new Set(['Input', 'SelectTrigger'])
const editsPerFile = new Map()

function tagName(node) {
  // JsxElement keeps its tag on the opening element; only a self-closing
  // element carries tagName directly.
  const t = ts.isJsxElement(node) ? node.openingElement.tagName : node.tagName
  return t && ts.isIdentifier(t) ? t.text : null
}

for (const file of files) {
  const src = readFileSync(file, 'utf8')
  const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
  const edits = []

  const visit = (node) => {
    if (ts.isJsxElement(node) || ts.isJsxSelfClosingElement(node)) {
      const name = tagName(node)
      if (name === 'HeaderActionButton') {
        // walk up to the row container (the nearest parent element with 2+ children)
        let row = node.parent
        while (row && !(ts.isJsxElement(row) && row.children.filter((c) => ts.isJsxElement(c) || ts.isJsxSelfClosingElement(c)).length > 1)) {
          row = row.parent
        }
        if (row && ts.isJsxElement(row)) {
          for (const child of row.children) {
            if (!ts.isJsxElement(child) && !ts.isJsxSelfClosingElement(child)) continue
            let childName = tagName(child)
            let target = child

            // Selects wrap their trigger: <Select><SelectTrigger className=... /></Select>
            if (childName === 'Select' && ts.isJsxElement(child)) {
              const inner = child.children.find(
                (c) => (ts.isJsxElement(c) || ts.isJsxSelfClosingElement(c)) && tagName(c) === 'SelectTrigger',
              )
              if (!inner) continue
              target = inner
              childName = 'SelectTrigger'
            }

            if (!targets.has(childName)) continue

            const opening = ts.isJsxElement(target) ? target.openingElement : target
            const attrs = opening.attributes.properties
            const classAttr = attrs.find((a) => ts.isJsxAttribute(a) && a.name.getText() === 'className')
            const { line } = sf.getLineAndCharacterOfPosition(opening.getStart(sf))

            let current = ''
            if (classAttr && classAttr.initializer && ts.isStringLiteral(classAttr.initializer)) {
              current = classAttr.initializer.text
            }

            // already a pill? leave it untouched
            if (/rounded-full/.test(current) && /(^|\s)h-8(\s|$)/.test(current)) {
              continue
            }

            // strip an explicit height / radius / size text, then prepend the pill geometry
            const kept = current
              .split(/\s+/)
              .filter(Boolean)
              .filter((c) => !/^h-/.test(c) && !/^rounded/.test(c) && c !== 'px-3' && c !== 'text-xs')
              .join(' ')

            const next = ['h-8', 'rounded-full', childName === 'Input' ? 'px-3' : null, 'text-xs', kept]
              .filter(Boolean)
              .join(' ')
              .trim()

            edits.push({ opening, classAttr, next, line: line + 1, childName, current })
          }
        }
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(sf)

  const deduped = [...new Map(edits.map((e) => [e.opening.getStart(sf), e])).values()]
  if (deduped.length) editsPerFile.set(file, { src, edits: deduped })
}

let total = 0
for (const [file, { src, edits }] of editsPerFile) {
  console.log(`\n${file}`)
  for (const e of edits) {
    console.log(`  ${String(e.line).padStart(5)}  ${e.childName.padEnd(14)} ${e.current || '(no className)'}  ->  ${e.next}`)
  }
  total += edits.length
}
console.log(`\n${total} control(s) across ${editsPerFile.size} file(s)`)

if (!apply) {
  console.log('(dry run — pass --apply to write)')
  process.exit(0)
}

// apply from the END of each file backwards so earlier offsets stay valid
for (const [file, { src, edits }] of editsPerFile) {
  const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
  const sorted = [...edits].sort((a, b) => b.opening.getStart(sf) - a.opening.getStart(sf))
  let out = src
  for (const e of sorted) {
    if (e.classAttr && e.classAttr.initializer) {
      const start = e.classAttr.initializer.getStart(sf)
      const end = e.classAttr.initializer.getEnd()
      out = out.slice(0, start) + `"${e.next}"` + out.slice(end)
    } else {
      const nameEnd = e.opening.tagName.getEnd()
      out = out.slice(0, nameEnd) + ` className="${e.next}"` + out.slice(nameEnd)
    }
  }
  writeFileSync(file, out)
}
console.log('applied')
