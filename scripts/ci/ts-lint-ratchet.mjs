#!/usr/bin/env node
// Typecheck / lint ratchet for frontend/ and superadmin/ (#364).
//
// Both apps carry pre-existing tsc errors and eslint warnings, so a plain
// `tsc --noEmit` / `eslint --max-warnings 0` CI job could never be required.
// This script runs the real tool, reduces its output to stable keys and
// compares against a committed baseline:
//   - tsc:    key = file | TS code | first line of the message  (no line/col,
//             so edits elsewhere in a file don't churn the baseline)
//   - eslint: key = file | rule
// Each key maps to a count. The check FAILS when any key's count goes above
// the baseline (a new error), and REPORTS keys whose count went down (fixed).
//
// Usage (run from the app directory, e.g. frontend/):
//   node ../scripts/ci/ts-lint-ratchet.mjs tsc|eslint            # check
//   node ../scripts/ci/ts-lint-ratchet.mjs tsc|eslint --update   # shrink baseline to current
//   ... --update --accept-new   # also add new entries (needs reviewer sign-off)
//
// Baselines live in <app>/ci-baseline/{tsc,eslint}.json.

import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'

const [tool, ...flags] = process.argv.slice(2)
const update = flags.includes('--update')
const acceptNew = flags.includes('--accept-new')

if (tool !== 'tsc' && tool !== 'eslint') {
  console.error('usage: ts-lint-ratchet.mjs tsc|eslint [--update [--accept-new]]')
  process.exit(2)
}

const appDir = process.cwd()
const baselineFile = path.join(appDir, 'ci-baseline', `${tool}.json`)
const rel = (f) => path.relative(appDir, path.resolve(appDir, f)).split(path.sep).join('/')
const scrub = (s) => s.split(appDir).join('<app>').split(appDir.split(path.sep).join('/')).join('<app>')

function run(bin, args) {
  const res = spawnSync(process.execPath, [path.join(appDir, 'node_modules', ...bin), ...args], {
    cwd: appDir,
    encoding: 'utf8',
    maxBuffer: 256 * 1024 * 1024,
  })
  if (res.error) throw res.error
  return res
}

function collectTsc() {
  const res = run(['typescript', 'bin', 'tsc'], ['--noEmit', '-p', '.', '--pretty', 'false'])
  const counts = {}
  let n = 0
  for (const line of res.stdout.split(/\r?\n/)) {
    let m = line.match(/^(.+?)\(\d+,\d+\): error (TS\d+): (.*)$/)
    let key
    if (m) key = `${rel(m[1])} | ${m[2]} | ${scrub(m[3])}`
    else if ((m = line.match(/^error (TS\d+): (.*)$/))) key = `<global> | ${m[1]} | ${scrub(m[2])}`
    else continue
    counts[key] = (counts[key] || 0) + 1
    n++
  }
  if (res.status !== 0 && n === 0) {
    console.error('tsc exited non-zero but no errors could be parsed:\n' + res.stdout + res.stderr)
    process.exit(2)
  }
  return { counts, total: n }
}

function collectEslint() {
  const res = run(['eslint', 'bin', 'eslint.js'], [
    '.', '--ext', 'ts,tsx', '--report-unused-disable-directives', '-f', 'json',
  ])
  if (res.status === 2) {
    console.error('eslint crashed:\n' + res.stdout + res.stderr)
    process.exit(2)
  }
  let report
  try {
    report = JSON.parse(res.stdout)
  } catch {
    console.error('could not parse eslint JSON output:\n' + res.stdout.slice(0, 2000) + res.stderr)
    process.exit(2)
  }
  const counts = {}
  let n = 0
  for (const file of report) {
    for (const msg of file.messages) {
      const rule = msg.ruleId
        ?? (msg.fatal ? 'fatal-parse-error'
          : /eslint-disable/.test(msg.message) ? 'unused-disable-directive' : 'eslint-core')
      const key = `${rel(file.filePath)} | ${rule}`
      counts[key] = (counts[key] || 0) + 1
      n++
    }
  }
  return { counts, total: n }
}

const { counts: current, total } = tool === 'tsc' ? collectTsc() : collectEslint()
const baseline = fs.existsSync(baselineFile) ? JSON.parse(fs.readFileSync(baselineFile, 'utf8')) : {}

const added = []
const fixed = []
for (const key of new Set([...Object.keys(current), ...Object.keys(baseline)])) {
  const now = current[key] || 0
  const was = baseline[key] || 0
  if (now > was) added.push([key, was, now])
  else if (now < was) fixed.push([key, was, now])
}

const label = path.basename(appDir) + ' ' + tool
const baselineTotal = Object.values(baseline).reduce((a, b) => a + b, 0)
console.log(`${label}: ${total} finding(s) now, ${baselineTotal} in baseline (${path.relative(appDir, baselineFile)})`)

if (fixed.length) {
  console.log(`\n${label}: ${fixed.length} baselined finding group(s) reduced — shrink the baseline with \`npm run ${tool === 'tsc' ? 'typecheck' : 'lint'}:baseline\`:`)
  for (const [k, was, now] of fixed) console.log(`  fixed  ${was} -> ${now}  ${k}`)
}

if (update) {
  const next = {}
  for (const key of Object.keys({ ...current, ...baseline }).sort()) {
    const now = current[key] || 0
    const was = baseline[key] || 0
    const v = acceptNew ? now : Math.min(now, was)
    if (v > 0) next[key] = v
  }
  fs.mkdirSync(path.dirname(baselineFile), { recursive: true })
  fs.writeFileSync(baselineFile, JSON.stringify(next, null, 2) + '\n')
  const nextTotal = Object.values(next).reduce((a, b) => a + b, 0)
  console.log(`\n${label}: baseline written (${nextTotal} finding(s)).`)
  if (added.length && !acceptNew) {
    console.log(`${label}: ${added.length} NEW finding group(s) were NOT added (pass --accept-new to baseline them deliberately).`)
  }
}

if (added.length && !(update && acceptNew)) {
  console.error(`\n${label}: ${added.length} NEW finding group(s) above baseline — fix them (run \`npx ${tool === 'tsc' ? 'tsc --noEmit' : 'eslint <file>'}\` for line numbers):`)
  for (const [k, was, now] of added) console.error(`  NEW    ${was} -> ${now}  ${k}`)
  process.exit(1)
}

console.log(`\n${label}: OK — no new findings.`)
